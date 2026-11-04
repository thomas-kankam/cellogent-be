<?php

namespace App\Services\Payments;

use App\Models\Admin;
use App\Models\PaymentGateway;
use App\Services\Payments\Gateways\KairosPayGateway;
use App\Services\Payments\Gateways\PaymentGatewayDriver;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Picks which gateway to use. The admin sets the order (priority) and can disable a gateway;
 * a gateway that keeps failing to connect is moved behind the others until its cooldown ends.
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

        return PaymentGateway::query()->orderBy('priority')->orderBy('id')->get();
    }

    /**
     * Gateways to try for a method, in order: healthy ones by priority, then tripped ones.
     *
     * @return list<PaymentGateway>
     */
    public function candidates(string $method): array
    {
        $usable = $this->all()->filter(fn (PaymentGateway $g) => $g->is_enabled
            && $g->supports($method)
            && $this->driver($g->code)->configured());

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
     * Move a gateway to the front; the others keep their relative order as fallbacks.
     */
    public function makePrimary(string $code, Admin $admin): void
    {
        $ordered = $this->all()->sortBy(fn (PaymentGateway $g) => $g->code === $code ? 0 : $g->priority)->values();

        foreach ($ordered as $index => $gateway) {
            $gateway->update(['priority' => $index + 1, 'updated_by' => $admin->id]);
        }
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
            'is_primary' => $this->all()->firstWhere('is_enabled', true)?->code === $gateway->code,
            'priority' => $gateway->priority,
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
