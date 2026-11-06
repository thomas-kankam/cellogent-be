<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Plan;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Facades\DB;

class PlanService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'details' => $plan->options(),
            'status' => $plan->status,
            'is_featured' => (bool) $plan->is_featured,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Admin $admin, array $input): array
    {
        $featured = (bool) ($input['is_featured'] ?? false);

        $plan = DB::transaction(function () use ($admin, $input, $featured) {
            if ($featured) {
                $this->clearFeatured();
            }

            return Plan::create([
                'name' => $input['name'],
                'details' => $this->normalizeDetails($input['details'] ?? []),
                'status' => $input['status'] ?? 'active',
                'is_featured' => $featured,
                'created_by' => $admin->id,
            ]);
        });

        $this->activity()->record('Plan created', 'subscription', 'created', 'admin', $admin, null, [
            'plan_id' => $plan->id,
            'is_featured' => $featured,
        ]);

        return $this->result(false, 'Plan created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($plan));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $admin, Plan $plan, array $input): array
    {
        $plan = DB::transaction(function () use ($admin, $plan, $input) {
            $payload = [
                'name' => $input['name'] ?? $plan->name,
                'details' => isset($input['details']) ? $this->normalizeDetails($input['details']) : $plan->options(),
                'status' => $input['status'] ?? $plan->status,
                'updated_by' => $admin->id,
            ];

            if (array_key_exists('is_featured', $input)) {
                $featured = (bool) $input['is_featured'];

                if ($featured) {
                    $this->clearFeatured($plan->id);
                }

                $payload['is_featured'] = $featured;
            }

            $plan->update($payload);

            return $plan->fresh();
        });

        $this->activity()->record('Plan updated', 'subscription', 'updated', 'admin', $admin, null, [
            'plan_id' => $plan->id,
            'is_featured' => (bool) $plan->is_featured,
        ]);

        return $this->result(false, 'Plan updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($plan));
    }

    /**
     * Only one plan may be featured at a time.
     */
    protected function clearFeatured(?int $exceptId = null): void
    {
        $query = Plan::query()->where('is_featured', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_featured' => false]);
    }

    /**
     * @param  array<int, mixed>  $details
     * @return list<array{months: int, units: int, price: float}>
     */
    protected function normalizeDetails(array $details): array
    {
        if (isset($details['months']) || isset($details['price']) || isset($details['units'])) {
            $details = [$details];
        }

        return array_values(array_map(function ($option) {
            return [
                'months' => (int) ($option['months'] ?? 1),
                'units' => (int) ($option['units'] ?? 0),
                'price' => (float) ($option['price'] ?? 0),
            ];
        }, $details));
    }
}
