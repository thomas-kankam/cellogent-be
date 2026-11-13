<?php

namespace App\Services\Payments;

use App\Models\Admin;
use App\Models\PaymentGateway;
use App\Services\Payments\Gateways\KairosPayGateway;
use App\Services\Payments\Gateways\PaymentGatewayDriver;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Picks which gateway to use.
 *
 * priority 1 = primary (tried first). Priorities are always unique 1..n.
 * Disabled gateways keep their priority but are skipped at checkout.
 * A gateway that keeps failing to connect is tried after healthy ones until its cooldown ends.
 */
class GatewayManager
{
    /** @var array<string, class-string<PaymentGatewayDriver>> */
    protected const DRIVERS = [
        'kairos' => KairosPayGateway::class,
        'paystack' => PaystackGateway::class,
    ];

    public function driver(string $code): PaymentGatewayDriver
    {
        $class = self::DRIVERS[$code] ?? throw new InvalidArgumentException("Unknown payment gateway [{$code}].");

        return app($class);
    }

    /**
     * @return Collection<int, PaymentGateway>
     */
    public function all(): Collection
    {
        $priority = 1;

        foreach (array_keys(self::DRIVERS) as $code) {
            PaymentGateway::query()->firstOrCreate(['code' => $code], [
                'name' => config("payments.gateways.{$code}.name", ucfirst($code)),
                'methods' => config("payments.gateways.{$code}.methods", []),
                'is_enabled' => true,
                'priority' => $priority++,
            ]);
        }

        $gateways = PaymentGateway::query()->orderBy('priority')->orderBy('id')->get();

        if ($this->prioritiesNeedRepair($gateways)) {
            $this->renumber($gateways);
            $gateways = PaymentGateway::query()->orderBy('priority')->orderBy('id')->get();
        }

        return $gateways;
    }

    /**
     * Gateways to try for a method, in order: healthy ones by priority, then tripped ones.
     * Null method = open hosted checkout (payer picks card/momo on the gateway page).
     *
     * @return list<PaymentGateway>
     */
    public function candidates(?string $method = null): array
    {
        $usable = $this->all()->filter(function (PaymentGateway $g) use ($method) {
            if (! $g->is_enabled || ! $this->driver($g->code)->configured()) {
                return false;
            }

            // Open checkout: gateways that can collect card and/or momo on their own page (e.g. Paystack).
            if ($method === null || $method === '') {
                return $g->supports('card') || $g->supports('momo');
            }

            return $g->supports($method);
        });

        // Kairos needs an MSISDN prompt — skip it when the method is still undecided.
        if ($method === null || $method === '') {
            $usable = $usable->reject(fn (PaymentGateway $g) => $g->code === 'kairos');
        }

        return $this->orderByHealth($usable);
    }

    /**
     * Wallet top-up: always follow admin priority. Include Kairos when an MSISDN is available.
     *
     * @return list<PaymentGateway>
     */
    public function topUpCandidates(bool $hasMsisdn): array
    {
        $usable = $this->all()->filter(function (PaymentGateway $g) use ($hasMsisdn) {
            if (! $g->is_enabled || ! $this->driver($g->code)->configured()) {
                return false;
            }

            if ($g->code === 'kairos') {
                return $hasMsisdn;
            }

            return $g->supports('card') || $g->supports('momo');
        });

        return $this->orderByHealth($usable);
    }

    /**
     * @param  Collection<int, PaymentGateway>  $usable
     * @return list<PaymentGateway>
     */
    protected function orderByHealth(Collection $usable): array
    {
        [$tripped, $healthy] = $usable->partition(fn (PaymentGateway $g) => $this->isTripped($g));

        return $healthy->concat($tripped)->values()->all();
    }

    public function isTripped(PaymentGateway $gateway): bool
    {
        return $gateway->consecutive_failures >= (int) config('payments.circuit.failures', 3)
            && $gateway->last_failure_at
            && $gateway->last_failure_at->gt(now()->subMinutes((int) config('payments.circuit.cooldown_minutes', 5)));
    }

    public function recordFailure(string $code, string $reason): void
    {
        PaymentGateway::query()->where('code', $code)->increment('consecutive_failures', 1, [
            'last_failure_at' => now(),
            'last_failure_reason' => mb_substr($reason, 0, 255),
        ]);
    }

    public function recordSuccess(string $code): void
    {
        PaymentGateway::query()->where('code', $code)->update([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
        ]);
    }

    /**
     * Make this gateway priority 1; others shift down keeping relative order.
     */
    public function makePrimary(string $code, Admin $admin): void
    {
        $this->setPriority($code, 1, $admin);
    }

    /**
     * Place a gateway at the given rank (1 = primary). All priorities are rewritten uniquely as 1..n.
     *
     * @return Collection<int, PaymentGateway>
     */
    public function setPriority(string $code, int $rank, Admin $admin): Collection
    {
        return DB::transaction(function () use ($code, $rank, $admin) {
            $gateways = $this->all()->values();
            $target = $gateways->firstWhere('code', $code);

            if (! $target) {
                throw new InvalidArgumentException("Unknown payment gateway [{$code}].");
            }

            $rank = max(1, min($rank, $gateways->count()));
            $rest = $gateways->reject(fn (PaymentGateway $g) => $g->code === $code)->values();

            $ordered = $rest->take($rank - 1)
                ->concat(collect([$target]))
                ->concat($rest->slice($rank - 1))
                ->values();

            $this->renumber($ordered, $admin->id);

            return $this->all();
        });
    }

    /**
     * @param  Collection<int, PaymentGateway>  $ordered
     */
    protected function renumber(Collection $ordered, ?int $updatedBy = null): void
    {
        foreach ($ordered->values() as $index => $gateway) {
            $payload = ['priority' => $index + 1];

            if ($updatedBy !== null) {
                $payload['updated_by'] = $updatedBy;
            }

            if ((int) $gateway->priority !== $payload['priority'] || $updatedBy !== null) {
                $gateway->update($payload);
            }
        }
    }

    /**
     * @param  Collection<int, PaymentGateway>  $gateways
     */
    protected function prioritiesNeedRepair(Collection $gateways): bool
    {
        if ($gateways->isEmpty()) {
            return false;
        }

        $priorities = $gateways->pluck('priority')->map(fn ($p) => (int) $p)->all();

        return $priorities !== range(1, $gateways->count());
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PaymentGateway $gateway): array
    {
        return [
            'code' => $gateway->code,
            'name' => $gateway->name,
            'is_enabled' => $gateway->is_enabled,
            'is_primary' => (int) $gateway->priority === 1,
            'priority' => (int) $gateway->priority,
            'methods' => $gateway->methods,
            'configured' => $this->driver($gateway->code)->configured(),
            'health' => $this->isTripped($gateway) ? 'down' : 'up',
            'consecutive_failures' => $gateway->consecutive_failures,
            'last_failure_at' => optional($gateway->last_failure_at)->toDateTimeString(),
            'last_failure_reason' => $gateway->last_failure_reason,
            'last_success_at' => optional($gateway->last_success_at)->toDateTimeString(),
        ];
    }
}
