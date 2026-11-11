<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Reward;
use App\Models\Survey;
use App\Services\Engagement\EngagementSupport;
use App\Services\Utilities\SwiftCatalog;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rewards catalog (money, airtime, data) that campaigns and surveys can attach.
 * Admins manage it; clients only see what is in stock.
 */
class RewardService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public function __construct(protected SwiftCatalog $catalog, protected EngagementSupport $support) {}

    /**
     * Admin view. For data and airtime `amount` is the provider price (api_price); `price` is what
     * a client's wallet pays for one reward and `profit` the margin in between.
     *
     * @return array<string, mixed>
     */
    public function present(Reward $reward): array
    {
        $pricing = $this->pricing($reward);

        return [
            'package_id' => $reward->bundle_id,
            'api_price' => $pricing['api_price'],
            'price' => $pricing['price'],
            'profit' => round($pricing['price'] - $pricing['api_price'], 2),
            'id' => $reward->id,
            'name' => $reward->name,
            'type' => $reward->type,
            'size' => $reward->size,
            'amount' => (float) $reward->amount,
            'network' => $reward->network,
            'units' => (int) $reward->units,
            'currency' => $reward->currency,
            'provider_code' => $reward->provider_code,
            'bundle_id' => $reward->bundle_id,
            'status' => $reward->status,
            'meta' => $reward->meta,
            'created_at' => optional($reward->created_at)->toDateTimeString(),
            'updated_at' => optional($reward->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * Client view: no provider internals.
     *
     * @return array<string, mixed>
     */
    public function presentForClient(Reward $reward): array
    {
        return [
            'id' => $reward->id,
            'name' => $reward->name,
            'type' => $reward->type,
            'size' => $reward->size,
            'amount' => (float) $reward->amount,
            'network' => $reward->network,
            'currency' => $reward->currency,
            'status' => $reward->status,
            'package_id' => $reward->type === Reward::DATA ? $reward->bundle_id : null,
            // What one reward costs the wallet (data and airtime include the service margin).
            'price' => $this->pricing($reward)['price'],
        ];
    }

    /**
     * @return array{api_price: float, price: float}
     */
    protected function pricing(Reward $reward): array
    {
        $snapshot = $this->support->snapshot($reward);

        return [
            'api_price' => round((float) ($snapshot['api_price'] ?? $reward->amount), 2),
            'price' => EngagementSupport::unitCost($snapshot),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  status, type, network, search
     */
    public function index(array $filters = [], bool $instockOnly = false): Builder
    {
        $query = Reward::query()->latest('id');

        if ($instockOnly) {
            $query->where('status', Reward::INSTOCK);
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['network'])) {
            $query->where('network', $filters['network']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where('name', 'like', $term);
        }

        return $query;
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function show(Reward $reward): array
    {
        return $this->result(false, 'Reward loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($reward));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Admin $admin, array $input): array
    {
        $input = $this->resolveInput($input);

        if ($this->failed($input)) {
            return $input;
        }

        $reward = Reward::create([
            'name' => trim((string) $input['name']),
            'type' => $input['type'],
            'size' => $input['size'] ?? null,
            'amount' => round((float) $input['amount'], 2),
            'network' => $input['network'] ?? null,
            'units' => (int) ($input['units'] ?? 0),
            'currency' => strtoupper((string) ($input['currency'] ?? 'GHS')),
            'provider_code' => $input['provider_code'] ?? null,
            'bundle_id' => $input['bundle_id'] ?? null,
            'status' => $input['status'] ?? Reward::INSTOCK,
            'meta' => $input['meta'] ?? null,
        ]);

        $this->activity()->record('Reward created', 'reward', 'created', 'admin', $admin, null, [
            'reward_id' => $reward->id,
            'type' => $reward->type,
        ]);

        return $this->result(false, 'Reward created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($reward));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $admin, Reward $reward, array $input): array
    {
        $input = $this->resolveInput($input, $reward);

        if ($this->failed($input)) {
            return $input;
        }

        $changes = [];

        foreach (['size', 'network', 'provider_code', 'bundle_id', 'meta', 'status', 'type'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $input[$field];
            }
        }

        if (array_key_exists('name', $input)) {
            $changes['name'] = trim((string) $input['name']);
        }

        if (array_key_exists('amount', $input)) {
            $changes['amount'] = round((float) $input['amount'], 2);
        }

        if (array_key_exists('units', $input)) {
            $changes['units'] = (int) $input['units'];
        }

        if (array_key_exists('currency', $input)) {
            $changes['currency'] = strtoupper((string) $input['currency']);
        }

        $reward->update($changes);

        $this->activity()->record('Reward updated', 'reward', 'updated', 'admin', $admin, null, [
            'reward_id' => $reward->id,
            'fields' => array_keys($changes),
        ]);

        return $this->result(false, 'Reward updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($reward->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function updateStatus(Admin $admin, Reward $reward, string $status): array
    {
        $reward->update(['status' => $status]);

        $this->activity()->record('Reward status changed', 'reward', 'updated', 'admin', $admin, null, [
            'reward_id' => $reward->id,
            'status' => $status,
        ]);

        return $this->result(false, 'Reward status updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($reward->fresh()));
    }

    /**
     * A reward in use by a live campaign/survey cannot be removed (it can be set out of stock instead).
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Admin $admin, Reward $reward): array
    {
        $inUse = Campaign::query()->where('reward_id', $reward->id)
            ->whereNotIn('status', [Campaign::STATUS_COMPLETED, Campaign::STATUS_CANCELLED])->exists()
            || Survey::query()->where('reward_id', $reward->id)
                ->whereNotIn('status', [Survey::STATUS_COMPLETED, Survey::STATUS_CANCELLED])->exists();

        if ($inUse) {
            return $this->result(true, 'This reward is used by a live campaign or survey. Set it out of stock instead.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $reward->delete();

        $this->activity()->record('Reward deleted', 'reward', 'deleted', 'admin', $admin, null, [
            'reward_id' => $reward->id,
        ]);

        return $this->result(false, 'Reward deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * Data rewards are picked from the Swift catalog: `package_id` (or `bundle_id`) fills in the
     * network, size and amount (= provider api_price) from the live plan. Airtime needs a network.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function resolveInput(array $input, ?Reward $existing = null): array
    {
        $type = $input['type'] ?? $existing?->type;
        $packageId = trim((string) ($input['package_id'] ?? $input['bundle_id'] ?? ''));
        $touchesBundle = $packageId !== '' || isset($input['type']);

        unset($input['package_id']);

        if ($type === Reward::AIRTIME) {
            $network = $input['network'] ?? $existing?->network;
            $code = $network ? $this->support->airtimeNetwork((string) $network) : null;

            if (! $code) {
                return $this->result(true, 'Choose the airtime network.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                    'network' => ['Allowed: '.implode(', ', array_keys((array) config('swift.airtime_networks', [])))],
                ]);
            }

            $input['network'] = $code;
        }

        // Editing something else (name, stock…) on a data reward leaves its bundle alone.
        if ($type !== Reward::DATA || ($existing && ! $touchesBundle)) {
            return $input;
        }

        if ($packageId === '' && $existing) {
            $packageId = (string) $existing->bundle_id;
        }

        if ($packageId === '') {
            return $this->result(true, 'Pick a data bundle from the catalog.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'package_id' => ['Pick a data bundle from the catalog.'],
            ]);
        }

        $input['bundle_id'] = $packageId;

        if ($this->catalog->plans() === null) {
            // Catalog unreachable: keep what the admin typed, but it needs the numbers.
            $complete = ! empty($input['network'] ?? $existing?->network) && ! empty($input['amount'] ?? $existing?->amount);

            return $complete
                ? $input
                : $this->result(true, 'The data catalog is unavailable. Try again shortly.', ApiCode::UNAVAILABLE, ApiReason::PROVIDER_UNAVAILABLE);
        }

        $plan = $this->catalog->plan($packageId);

        if (! $plan) {
            return $this->result(true, 'That data bundle is not in the catalog.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'package_id' => ['That data bundle is not in the catalog.'],
            ]);
        }

        $input['network'] = $plan['network'];
        $input['size'] = $plan['size'];
        $input['amount'] = $plan['cost'];
        $input['name'] = trim((string) ($input['name'] ?? '')) !== '' ? $input['name'] : trim($plan['network'].' '.$plan['size']);

        return $input;
    }

    protected function failed(mixed $value): bool
    {
        return is_array($value) && array_key_exists('in_error', $value);
    }
}
