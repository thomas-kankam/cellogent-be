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
            'details' => $plan->details ?? [],
            'status' => $plan->status,
            'created_by' => $plan->created_by,
            'updated_by' => $plan->updated_by,
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
            'details' => [
                'months' => (int) ($input['details']['months'] ?? $input['months'] ?? 1),
                'units' => (int) ($input['details']['units'] ?? $input['units'] ?? 0),
                'price' => (float) ($input['details']['price'] ?? $input['price'] ?? 0),
            ],
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
        $details = $plan->details ?? [];
        if (isset($input['details']) && is_array($input['details'])) {
            $details = array_merge($details, $input['details']);
        }
        if (array_key_exists('months', $input)) {
            $details['months'] = (int) $input['months'];
        }
        if (array_key_exists('units', $input)) {
            $details['units'] = (int) $input['units'];
        }
        if (array_key_exists('price', $input)) {
            $details['price'] = (float) $input['price'];
        }

        $plan->update([
            'name' => $input['name'] ?? $plan->name,
            'details' => $details,
            'status' => $input['status'] ?? $plan->status,
            'updated_by' => $admin->id,
        ]);

        $this->activity()->record('Plan updated', 'subscription', 'updated', 'admin', $admin, null, [
            'plan_id' => $plan->id,
        ]);

        return $this->result(false, 'Plan updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($plan->fresh()));
    }
}
