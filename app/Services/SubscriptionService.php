<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionArchive;
use App\Support\ApiCode;
use App\Support\ApiReason;

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
        return Subscription::query()
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * Move the current pack into archives, then start a new one from a plan.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function activatePlan(
        Client $client,
        Plan $plan,
        string $type = 'monthly',
        ?int $paymentId = null,
        ?string $paymentReference = null,
        string $paymentStatus = 'paid',
        ?Admin $actor = null,
    ): array {
        if ($plan->status !== 'active') {
            return $this->result(true, 'This plan is not available.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $current = $this->currentFor($client);

        if ($current) {
            $previousPlan = $current->plan_id ? Plan::query()->find($current->plan_id) : null;

            SubscriptionArchive::create([
                'client_id' => $client->id,
                'plan_id' => $current->plan_id,
                'plan_name' => $current->plan_name,
                'plan_price' => $previousPlan?->price(),
                'plan_type' => $current->type,
                'duration_in_months' => $current->duration_in_months,
                'starts_at' => $current->starts_at,
                'ends_at' => $current->ends_at,
                'payment_status' => $paymentStatus,
                'payment_id' => $paymentId,
                'payment_reference' => $paymentReference,
            ]);

            $current->update(['status' => 'archived']);
        }

        $months = $plan->months();
        $starts = now();
        $ends = $type === 'non_expiry' ? null : $starts->copy()->addMonths($months);

        $subscription = Subscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'status' => 'active',
            'units' => $plan->units(),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'type' => $type,
            'duration_in_months' => $months,
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

        return $this->result(false, 'Subscription activated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($subscription));
    }
}
