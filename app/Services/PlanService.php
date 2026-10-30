<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Plan;
use App\Support\ApiCode;
use App\Support\ApiReason;

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
            // 'created_by' => $plan->created_by,
            // 'updated_by' => $plan->updated_by,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Admin $admin, array $input): array
    {
        $plan = Plan::create([
            'name' => $input['name'],
            'details' => $this->normalizeDetails($input['details'] ?? []),
            'status' => $input['status'] ?? 'active',
            'created_by' => $admin->id,
        ]);

        $this->activity()->record('Plan created', 'subscription', 'created', 'admin', $admin, null, [
            'plan_id' => $plan->id,
        ]);

        return $this->result(false, 'Plan created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($plan));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $admin, Plan $plan, array $input): array
    {
        $plan->update([
            'name' => $input['name'] ?? $plan->name,
            'details' => isset($input['details']) ? $this->normalizeDetails($input['details']) : $plan->options(),
            'status' => $input['status'] ?? $plan->status,
            'updated_by' => $admin->id,
        ]);

        $this->activity()->record('Plan updated', 'subscription', 'updated', 'admin', $admin, null, [
            'plan_id' => $plan->id,
        ]);

        return $this->result(false, 'Plan updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($plan->fresh()));
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
