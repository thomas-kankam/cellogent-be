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
        $subscription->loadMissing('client');

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
            'client' => $this->presentClient($subscription->client),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentArchive(SubscriptionArchive $archive): array
    {
        $archive->loadMissing('client');

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
            'client' => $this->presentClient($archive->client),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function presentClient(?Client $client): ?array
    {
        if (! $client) {
            return null;
        }

        return [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'email' => $client->email,
            'phone_number' => $client->phone_number,
            'account_type' => $client->account_type,
            'business_name' => $client->business_name,
            'status' => $client->status,
            'is_verified' => (bool) $client->is_verified,
            'profile_image' => $client->profileImageUrl(),
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
        ?array $purchasedOption = null,
    ): array {
        if ($requireActivePlan && $plan->status !== 'active') {
            return $this->result(true, 'This plan is not available.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        // A paid order delivers exactly what was priced at checkout, even if the plan changed since.
        $option = $purchasedOption ?? $plan->optionForMonths($months);

        if (! $option) {
            return $this->result(true, 'This plan has no option for that duration.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $starts = now();

        $subscription = DB::transaction(function () use ($client, $plan, $option, $starts, $type) {
            $current = Subscription::query()->where('client_id', $client->id)->lockForUpdate()->first();

            $stillActive = $current
                && $current->status === 'active'
                && ($current->ends_at === null || $current->ends_at->isFuture());

            // Leftover units always roll over while the pack is still usable.
            $before = $stillActive ? (int) $current->units : 0;
            $after = $before + (int) $option['units'];

            // non_expiry = SMS top-up style (no end date). Dated packs add remaining days onto the new term.
            $ends = $this->endsAtFor($type, (int) $option['months'], $starts, $stillActive ? $current : null);

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
                    'duration_in_months' => $type === 'non_expiry' ? null : $option['months'],
                ],
            );

            SmsUnitLedger::create([
                'client_id' => $client->id,
                'type' => 'credit',
                'units' => (int) $option['units'],
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => $type === 'non_expiry'
                    ? $plan->name.' top-up'
                    : $plan->name.' plan ('.$option['months'].' month)',
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
            'duration_in_months' => $type === 'non_expiry' ? null : $option['months'],
            'starts_at' => $subscription->starts_at,
            'ends_at' => $subscription->ends_at,
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
            [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'type' => $type,
                'units' => $subscription->units,
                'ends_at' => optional($subscription->ends_at)->toDateTimeString(),
            ],
        );

        CheckAggregatorBalanceJob::dispatch();

        return $this->result(false, 'Subscription activated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($subscription));
    }

    /**
     * non_expiry → no end date (SMS top-up).
     * Dated pack while an old dated pack is still running → remaining time is added onto the new term.
     */
    protected function endsAtFor(string $type, int $months, $starts, ?Subscription $current): mixed
    {
        if ($type === 'non_expiry') {
            return null;
        }

        $ends = $starts->copy()->addMonths($months);

        if ($current && $current->ends_at && $current->ends_at->isFuture()) {
            // remaining days on the old pack are stacked onto the new pack's length
            $ends = $ends->addSeconds($starts->diffInSeconds($current->ends_at));
        }

        return $ends;
    }
}
