<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\CheckAggregatorBalanceJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Plan;
use App\Models\SmsUnitLedger;
use App\Models\Subscription;
use App\Models\SubscriptionArchive;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'client_id' => $subscription->client_id,
            'plan_id' => $subscription->plan_id,
            'plan_name' => $subscription->plan_name,
            'status' => $subscription->status,
            'price' => $subscription->price,
            'units' => $subscription->units,
            'type' => $subscription->type,
            'duration_in_months' => $subscription->duration_in_months,
            'starts_at' => optional($subscription->starts_at)->toDateTimeString(),
            'ends_at' => optional($subscription->ends_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentArchive(SubscriptionArchive $archive): array
    {
        return [
            'id' => $archive->id,
            'client_id' => $archive->client_id,
            'plan_id' => $archive->plan_id,
            'plan_name' => $archive->plan_name,
            'plan_price' => $archive->plan_price,
            'units' => $archive->units,
            'plan_type' => $archive->plan_type,
            'duration_in_months' => $archive->duration_in_months,
            'starts_at' => optional($archive->starts_at)->toDateTimeString(),
            'ends_at' => optional($archive->ends_at)->toDateTimeString(),
            'payment_status' => $archive->payment_status,
            'payment_id' => $archive->payment_id,
            'payment_reference' => $archive->payment_reference,
        ];
    }

    public function currentFor(Client $client): ?Subscription
    {
        return Subscription::query()->where('client_id', $client->id)->first();
    }

    /**
     * Update the client's one subscription row and record the purchase in archives
     * (archives = full subscription history, one row per activation).
     *
     * @param  bool  $requireActivePlan  false when fulfilling a payment already made for the plan
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function activatePlan(
        Client $client,
        Plan $plan,
        int $months = 1,
        string $type = 'monthly',
        ?int $paymentId = null,
        ?string $paymentReference = null,
        string $paymentStatus = 'paid',
        ?Admin $actor = null,
        bool $requireActivePlan = true,
    ): array {
        if ($requireActivePlan && $plan->status !== 'active') {
            return $this->result(true, 'This plan is not available.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $option = $plan->optionForMonths($months);

        if (! $option) {
            return $this->result(true, 'This plan has no option for that duration.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $starts = now();
        $ends = $type === 'non_expiry' ? null : $starts->copy()->addMonths($option['months']);

        $subscription = DB::transaction(function () use ($client, $plan, $option, $starts, $ends, $type) {
            $current = Subscription::query()->where('client_id', $client->id)->lockForUpdate()->first();

            // Unused units carry over while the current subscription is still running.
            $stillActive = $current
                && $current->status === 'active'
                && ($current->ends_at === null || $current->ends_at->isFuture());
            $before = $stillActive ? (int) $current->units : 0;
            $after = $before + (int) $option['units'];

            $subscription = Subscription::query()->updateOrCreate(
                ['client_id' => $client->id],
                [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'status' => 'active',
                    'price' => $option['price'],
                    'units' => $after,
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'type' => $type,
                    'duration_in_months' => $option['months'],
                ],
            );

            SmsUnitLedger::create([
                'client_id' => $client->id,
                'type' => 'credit',
                'units' => (int) $option['units'],
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => $plan->name.' plan ('.$option['months'].' month)',
            ]);

            return $subscription;
        }, 3);

        SubscriptionArchive::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_price' => $option['price'],
            'units' => $option['units'],
            'plan_type' => $type,
            'duration_in_months' => $option['months'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'payment_status' => $paymentStatus,
            'payment_id' => $paymentId,
            'payment_reference' => $paymentReference,
        ]);

        $this->activity()->record(
            'Subscription activated',
            'subscription',
            'created',
            $actor ? 'admin' : 'client',
            $actor ?? $client,
            $client,
            ['plan_id' => $plan->id, 'plan_name' => $plan->name],
        );

        CheckAggregatorBalanceJob::dispatch();

        return $this->result(false, 'Subscription activated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($subscription));
    }
}
