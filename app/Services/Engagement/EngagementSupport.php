<?php

namespace App\Services\Engagement;

use App\Concerns\ReturnsServiceResult;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Reward;
use App\Models\SenderId;
use App\Models\SenderIdBlacklist;
use App\Models\WalletHold;
use App\Services\MarginService;
use App\Services\Utilities\SwiftCatalog;
use App\Services\WalletService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Database\Eloquent\Model;

/**
 * Pieces shared by campaigns and surveys: sender + group checks, audience size,
 * reward snapshot / cost estimate and wallet holds.
 */
class EngagementSupport
{
    use ReturnsServiceResult;

    public function __construct(
        protected WalletService $wallets,
        protected MarginService $margins,
        protected SwiftCatalog $catalog,
    ) {}

    /**
     * True when a helper returned an error result instead of its value.
     */
    public function failed(mixed $value): bool
    {
        return is_array($value) && array_key_exists('in_error', $value);
    }

    /**
     * Canonical approved sender name, or an error result (same rules as SMS sending).
     *
     * @return string|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function sender(Client $client, string $from): string|array
    {
        if (SenderIdBlacklist::match($from)) {
            return $this->result(true, 'This sender ID is not allowed.', ApiCode::BAD_REQUEST, ApiReason::SENDER_ID_NOT_ALLOWED);
        }

        $sender = SenderId::query()
            ->where('client_id', $client->id)
            ->where('status', 'approved')
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($from))])
            ->first();

        if (! $sender) {
            return $this->result(true, 'This sender ID is not approved for your account.', ApiCode::BAD_REQUEST, ApiReason::SENDER_ID_NOT_APPROVED);
        }

        return $sender->name;
    }

    /**
     * Group ids (integers) that all belong to the client, or an error result.
     *
     * @return list<int>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function groupIds(Client $client, mixed $ids): array
    {
        $requested = is_array($ids)
            ? array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))))
            : [];

        if ($requested === []) {
            return $this->result(true, 'Choose at least one contact group.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'contact_group_ids' => ['Choose at least one contact group.'],
            ]);
        }

        $owned = ContactGroup::query()
            ->where('client_id', $client->id)
            ->whereIn('id', $requested)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff($requested, $owned));

        if ($missing !== []) {
            return $this->result(true, 'Some contact groups were not found.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'contact_group_ids' => $missing,
            ]);
        }

        return $requested;
    }

    /**
     * Distinct phones reachable in the groups. Phones marked DND in any selected group are excluded.
     *
     * @param  list<int>  $groupIds
     * @return array{eligible: int, not_eligible: int}
     */
    public function audience(Client $client, array $groupIds, bool $excludeDnd = true): array
    {
        $base = fn () => Contact::query()
            ->where('client_id', $client->id)
            ->visible()
            ->whereIn('contact_group_id', $groupIds);

        $total = (int) $base()->distinct()->count('phone_number');

        if (! $excludeDnd) {
            return ['eligible' => $total, 'not_eligible' => 0];
        }

        $eligible = (int) $base()
            ->whereNotIn('phone_number', $base()->where('dnd_status', 'active')->select('phone_number'))
            ->distinct()
            ->count('phone_number');

        return ['eligible' => $eligible, 'not_eligible' => max(0, $total - $eligible)];
    }

    /**
     * A contact of this client (in the given groups) with this phone.
     *
     * @param  list<int>  $groupIds
     */
    public function contactForPhone(Client $client, array $groupIds, string $phone): ?Contact
    {
        return Contact::query()
            ->where('client_id', $client->id)
            ->visible()
            ->whereIn('contact_group_id', $groupIds)
            ->where('phone_number', $phone)
            ->first();
    }

    /**
     * Normalize reward fields. Accepts:
     * - catalog pick: reward_id / reward.reward_id
     * - inline config: reward.type + reward.amount (frontend campaign/survey payloads)
     *
     * @param  array<string, mixed>  $input
     * @return array{present: bool, enabled: bool, reward_id: int|null, inline: array<string, mixed>|null}
     */
    public function rewardInput(array $input): array
    {
        $nested = is_array($input['reward'] ?? null) ? $input['reward'] : [];

        $present = array_key_exists('reward_enabled', $input)
            || array_key_exists('reward_id', $input)
            || $nested !== [];

        $id = $input['reward_id'] ?? $nested['reward_id'] ?? $nested['id'] ?? null;
        $enabled = $input['reward_enabled'] ?? $nested['enabled'] ?? ($id !== null || isset($nested['type']));

        $inline = null;

        if (isset($nested['type']) || isset($nested['amount']) || isset($nested['bundle_id']) || isset($nested['package_id'])) {
            $inline = [
                'name' => $nested['name'] ?? $nested['bundle_label'] ?? $nested['type'] ?? null,
                'type' => $nested['type'] ?? null,
                'size' => $nested['size'] ?? $nested['bundle_label'] ?? null,
                'amount' => isset($nested['amount']) ? (float) $nested['amount'] : null,
                'network' => $nested['network'] ?? null,
                'currency' => $nested['currency'] ?? 'GHS',
                'provider_code' => $nested['provider_code'] ?? null,
                'bundle_id' => $nested['bundle_id'] ?? null,
                'package_id' => $nested['package_id'] ?? null,
                'bundle_label' => $nested['bundle_label'] ?? null,
                'airtime_preset' => $nested['airtime_preset'] ?? null,
                'disbursement_method' => $nested['disbursement_method'] ?? null,
                'estimate_override' => isset($nested['estimated_cost']) ? (float) $nested['estimated_cost'] : null,
            ];
        }

        return [
            'present' => $present,
            'enabled' => filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            'reward_id' => $id !== null && $id !== '' ? (int) $id : null,
            'inline' => $inline,
        ];
    }

    /**
     * Validate an inline reward object from the campaign/survey payload and price it.
     *
     * - money: amount is paid out as is.
     * - airtime: network + face amount; the wallet is charged the sell price.
     * - data: package_id from the Swift catalog; network, size and price come from the live plan.
     *
     * @param  array<string, mixed>  $inline
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function inlineSnapshot(array $inline): array
    {
        $type = $inline['type'] ?? null;

        if (! in_array($type, Reward::TYPES, true)) {
            return $this->result(true, 'Reward type must be data, airtime, or money.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward.type' => ['Allowed: '.implode(', ', Reward::TYPES)],
            ]);
        }

        $packageId = $inline['package_id'] ?? $inline['bundle_id'] ?? null;

        $base = [
            'reward_id' => null,
            'name' => $inline['name'] ?? $type,
            'type' => $type,
            'size' => $inline['size'] ?? null,
            'amount' => (float) ($inline['amount'] ?? 0),
            'network' => $inline['network'] ?? null,
            'units' => 0,
            'currency' => $inline['currency'] ?? 'GHS',
            'provider_code' => $inline['provider_code'] ?? null,
            'bundle_id' => $packageId,
            'package_id' => $packageId,
            'bundle_label' => $inline['bundle_label'] ?? null,
            'airtime_preset' => $inline['airtime_preset'] ?? null,
            'disbursement_method' => $inline['disbursement_method'] ?? null,
        ];

        if ($type === Reward::DATA) {
            return $this->dataSnapshot($base, trim((string) $packageId), $inline);
        }

        if ($base['amount'] <= 0) {
            return $this->result(true, 'Reward amount must be greater than zero.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward.amount' => ['Enter a reward amount greater than zero.'],
            ]);
        }

        if ($type === Reward::AIRTIME) {
            return $this->airtimeSnapshot($base);
        }

        return array_merge($base, ['unit_cost' => $base['amount']]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $inline
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function dataSnapshot(array $base, string $packageId, array $inline): array
    {
        if ($packageId === '') {
            return $this->result(true, 'Choose a data bundle from the catalog.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward.package_id' => ['Pick a bundle from GET /client/rewards/catalog.'],
            ]);
        }

        if ($this->catalog->plans() === null) {
            return $this->result(true, 'The data catalog is unavailable right now. Try again shortly.', ApiCode::UNAVAILABLE, ApiReason::PROVIDER_UNAVAILABLE);
        }

        $plan = $this->catalog->plan($packageId);

        if (! $plan || ! $plan['available']) {
            return $this->result(true, 'That data bundle is not available.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward.package_id' => ['That data bundle is not available.'],
            ]);
        }

        $price = $this->margins->priceForClient((float) $plan['cost'], (string) $plan['product_type'], $plan['network'], $plan['package_id']);

        return array_merge($base, [
            'name' => $inline['name'] ?? trim($plan['network'].' '.$plan['size']),
            'size' => $plan['size'],
            'network' => $plan['network'],
            'package_id' => $plan['package_id'],
            'bundle_id' => $plan['package_id'],
            'amount' => $price['sell'],
            'api_price' => $price['cost'],
            'unit_cost' => $price['sell'],
            'product_type' => $plan['product_type'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function airtimeSnapshot(array $base): array
    {
        $code = $this->airtimeNetwork((string) ($base['network'] ?? ''));

        if ($code === null) {
            return $this->result(true, 'Choose the airtime network.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward.network' => ['Allowed: '.implode(', ', array_keys((array) config('swift.airtime_networks', [])))],
            ]);
        }

        $limits = (array) config('swift.airtime_limits', []);
        $min = (float) ($limits['min'] ?? 1);
        $max = (float) ($limits['max'] ?? 500);

        if ($base['amount'] < $min || $base['amount'] > $max) {
            return $this->result(true, 'Airtime reward amount is outside the allowed range.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward.amount' => ["Allowed: {$min} to {$max}"],
            ]);
        }

        $price = $this->margins->priceForClient($base['amount'], 'airtime', $code);

        return array_merge($base, [
            'network' => $code,
            'api_price' => $price['cost'],
            'unit_cost' => $price['sell'],
            'product_type' => 'airtime',
        ]);
    }

    /**
     * Canonical Swift airtime network code (MTN, TELECEL, AT, GLO) or null.
     */
    public function airtimeNetwork(string $network): ?string
    {
        $key = strtolower(preg_replace('/[^a-z]/i', '', $network) ?? '');

        return match (true) {
            $key === 'mtn' => 'MTN',
            in_array($key, ['telecel', 'vodafone', 'vodaf', 'vod'], true) => 'TELECEL',
            in_array($key, ['at', 'airteltigo', 'airtel', 'tigo', 'attigo'], true) => 'AT',
            $key === 'glo' => 'GLO',
            default => null,
        };
    }

    /**
     * What one reward costs the client's wallet (data/airtime include our margin).
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function unitCost(?array $snapshot): float
    {
        return $snapshot ? round((float) ($snapshot['unit_cost'] ?? $snapshot['amount'] ?? 0), 2) : 0.0;
    }

    /**
     * An in-stock catalog reward, or an error result.
     */
    public function reward(?int $rewardId): Reward|array
    {
        $reward = $rewardId ? Reward::query()->find($rewardId) : null;

        if (! $reward || $reward->status !== Reward::INSTOCK) {
            return $this->result(true, 'Choose a reward that is in stock.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward_id' => ['Choose a reward that is in stock.'],
            ]);
        }

        return $reward;
    }

    /**
     * Frozen copy of the reward, so later catalog edits never change a funded campaign.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Reward $reward): array
    {
        $snapshot = [
            'reward_id' => $reward->id,
            'name' => $reward->name,
            'type' => $reward->type,
            'size' => $reward->size,
            'amount' => (float) $reward->amount,
            'network' => $reward->network,
            'units' => (int) $reward->units,
            'currency' => $reward->currency,
            'provider_code' => $reward->provider_code,
            'bundle_id' => $reward->bundle_id,
            'package_id' => $reward->bundle_id,
            'unit_cost' => (float) $reward->amount,
        ];

        if ($reward->type === Reward::DATA) {
            $productType = preg_match('/mash/i', $reward->bundle_id.' '.$reward->network) ? 'mashup' : 'data_bundle';
            $price = $this->margins->priceForClient((float) $reward->amount, $productType, $reward->network, $reward->bundle_id);

            return $snapshot + ['api_price' => $price['cost'], 'product_type' => $productType, 'unit_cost' => $price['sell']];
        }

        if ($reward->type === Reward::AIRTIME) {
            $code = $this->airtimeNetwork((string) $reward->network);
            $price = $this->margins->priceForClient((float) $reward->amount, 'airtime', $code);
            $snapshot['network'] = $code ?? $reward->network;

            return $snapshot + ['api_price' => $price['cost'], 'product_type' => 'airtime', 'unit_cost' => $price['sell']];
        }

        return $snapshot;
    }

    /**
     * GHS needed to pay every eligible recipient (unit cost already includes our margin).
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function estimate(?array $snapshot, int $eligible, mixed $override = null): float
    {
        return round(self::unitCost($snapshot) * $eligible, 2);
    }

    /**
     * Reserve wallet money, or an insufficient_balance result.
     *
     * @param  array<string, mixed>|null  $meta
     * @return WalletHold|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function holdFromWallet(Client $client, Model $item, float $required, string $purpose, ?array $meta = null): WalletHold|array
    {
        $hold = $this->wallets->hold($client, $required, $item, $purpose, null, $meta);

        if ($hold) {
            return $hold;
        }

        $wallet = $this->wallets->forClient($client);
        $reserved = $this->wallets->reservedTotal($client->id);

        return $this->result(true, 'Insufficient available wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
            'balance' => (float) $wallet->balance,
            'reserved' => $reserved,
            'available' => $this->wallets->available($client),
            'required' => round($required, 2),
        ]);
    }

    /**
     * Give the unused reserved money back to the client's available balance.
     */
    public function releaseHold(Model $item): void
    {
        if (! $item->wallet_hold_id) {
            return;
        }

        $hold = WalletHold::query()->find($item->wallet_hold_id);

        if ($hold) {
            $this->wallets->release($hold);
        }
    }

    public function holdRemaining(Model $item): float
    {
        if (! $item->wallet_hold_id) {
            return 0.0;
        }

        $hold = WalletHold::query()->find($item->wallet_hold_id);

        return $hold && $hold->status === WalletHold::ACTIVE ? round((float) $hold->remaining, 2) : 0.0;
    }

    /**
     * Fund-on-activate check shared by campaigns and surveys.
     */
    public function isFunded(Model $item): bool
    {
        return $item->wallet_hold_id !== null
            && WalletHold::query()->whereKey($item->wallet_hold_id)->where('status', WalletHold::ACTIVE)->exists();
    }
}
