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
        $name = trim((string) $input['name']);

        if ($this->nameTaken($name)) {
            return $this->result(true, 'A plan with this name already exists.', ApiCode::BAD_REQUEST, ApiReason::INVALID, [
                'name' => ['A plan with this name already exists.'],
            ]);
        }

        // At least one plan must always be featured. First plan, or create when none are featured, becomes featured.
        $featured = (bool) ($input['is_featured'] ?? false);
        if (! $featured && ! Plan::query()->where('is_featured', true)->exists()) {
            $featured = true;
        }

        $plan = DB::transaction(function () use ($admin, $input, $name, $featured) {
            if ($featured) {
                $this->clearFeatured();
            }

            return Plan::create([
                'name' => $name,
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
        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $plan->name;

        if ($this->nameTaken($name, $plan->id)) {
            return $this->result(true, 'A plan with this name already exists.', ApiCode::BAD_REQUEST, ApiReason::INVALID, [
                'name' => ['A plan with this name already exists.'],
            ]);
        }

        if (array_key_exists('is_featured', $input) && ! (bool) $input['is_featured'] && $plan->is_featured) {
            $otherFeatured = Plan::query()
                ->where('is_featured', true)
                ->whereKeyNot($plan->id)
                ->exists();

            if (! $otherFeatured) {
                return $this->result(
                    true,
                    'At least one plan must be featured. Feature another plan first.',
                    ApiCode::BAD_REQUEST,
                    ApiReason::INVALID,
                    ['is_featured' => ['At least one plan must be featured. Feature another plan first.']],
                );
            }
        }

        $plan = DB::transaction(function () use ($admin, $plan, $input, $name) {
            $payload = [
                'name' => $name,
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

    protected function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $query = Plan::query()->whereRaw('LOWER(name) = ?', [strtolower($name)]);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
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
