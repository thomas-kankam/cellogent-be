<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\DispatchEngagementBlastJob;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Client;
use App\Services\Engagement\EngagementSupport;
use App\Services\Payments\CheckoutService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use App\Support\SmsParts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Client campaigns (occasion | promotion) with optional funded rewards.
 * Rewards are paid from a wallet hold, so reserved money is never spent elsewhere.
 */
class CampaignService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public const OCCASION_TYPES = ['birthday', 'anniversary', 'holiday', 'custom'];

    /** Matches the frontend: on the day, N days before/after, or a custom offset. */
    public const TRIGGER_OFFSETS = ['on', 'before', 'after', 'same_day', 'one_day_before', 'three_days_before', 'one_week_before', 'custom'];

    public const FUND_METHODS = ['wallet', 'momo', 'card'];

    public function __construct(
        protected EngagementSupport $support,
        protected WalletService $wallets,
        protected CheckoutService $checkout,
        protected RewardDisbursementService $rewards,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  status, type, search
     */
    public function index(Client $client, array $filters = []): Builder
    {
        $query = Campaign::query()->where('client_id', $client->id)->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Campaign $campaign): array
    {
        $reward = is_array($campaign->reward) ? $campaign->reward : null;
        $summary = $this->summary($campaign);

        return [
            'id' => $campaign->id,
            'resource' => 'campaign',
            'client_id' => $campaign->client_id,
            'name' => $campaign->name,
            'type' => $campaign->type,
            'status' => $campaign->status,
            'occasion_type' => $campaign->occasion_type,
            'custom_date' => optional($campaign->custom_date)->toDateString(),
            'trigger_offset' => $campaign->trigger_offset,
            'custom_offset_days' => $campaign->custom_offset_days,
            'send_time' => $campaign->send_time,
            'repeat_yearly' => (bool) $campaign->repeat_yearly,
            'contact_group_ids' => array_map('intval', $campaign->contact_group_ids ?? []),
            'sender' => $campaign->sender,
            'body' => $campaign->body,
            'send_at' => optional($campaign->send_at)->toDateTimeString(),
            'cta' => $campaign->cta,
            'tracking_url' => url('/api/public/campaigns/'.$campaign->id.'/click'),
            'reward' => [
                'enabled' => (bool) $campaign->reward_enabled,
                'reward_id' => $campaign->reward_id,
                'name' => $reward['name'] ?? null,
                'type' => $reward['type'] ?? null,
                'size' => $reward['size'] ?? null,
                'amount' => isset($reward['amount']) ? (float) $reward['amount'] : null,
                'network' => $reward['network'] ?? null,
                'currency' => $reward['currency'] ?? null,
                'eligible_recipients' => (int) $campaign->eligible_recipients,
                'not_eligible' => (int) $campaign->not_eligible,
                'estimated_cost' => (float) $campaign->estimated_cost,
                'estimated_cost_units' => (int) $campaign->estimated_cost_units,
            ],
            'funding' => [
                'method' => $campaign->funding_method,
                'funded' => $this->support->isFunded($campaign),
                'wallet_hold_id' => $campaign->wallet_hold_id,
                'payment_id' => $campaign->payment_id,
                'reserved_amount' => (float) $campaign->reserved_amount,
                'reserved_remaining' => $summary['reserved_remaining'],
            ],
            'analytics' => $summary,
            'inbox_id' => $campaign->inbox_id,
            'activated_at' => optional($campaign->activated_at)->toDateTimeString(),
            'completed_at' => optional($campaign->completed_at)->toDateTimeString(),
            'cancelled_at' => optional($campaign->cancelled_at)->toDateTimeString(),
            'created_at' => optional($campaign->created_at)->toDateTimeString(),
            'updated_at' => optional($campaign->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function show(Campaign $campaign): array
    {
        return $this->result(false, 'Campaign loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($campaign));
    }

    /**
     * Saves a draft only. Funding and activation are separate steps.
     *
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Client $client, array $input): array
    {
        $attributes = $this->build($client, $input, null);

        if ($this->support->failed($attributes)) {
            return $attributes;
        }

        $campaign = Campaign::create($attributes + [
            'client_id' => $client->id,
            'status' => Campaign::STATUS_DRAFT,
            'reserved_amount' => 0,
            'analytics' => $this->emptyAnalytics(),
        ]);

        $this->activity()->record('Campaign created', 'campaign', 'created', 'client', $client, $client, [
            'campaign_id' => $campaign->id,
            'type' => $campaign->type,
        ]);

        return $this->result(false, 'Campaign saved as draft.', ApiCode::CREATED, ApiReason::CREATED, $this->present($campaign->fresh()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Client $client, Campaign $campaign, array $input): array
    {
        if (! in_array($campaign->status, Campaign::EDITABLE, true)) {
            return $this->result(true, 'Only draft, funded or paused campaigns can be edited.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $attributes = $this->build($client, $input, $campaign);

        if ($this->support->failed($attributes)) {
            return $attributes;
        }

        $reserved = (float) $campaign->reserved_amount;
        $reset = [];

        if ($campaign->wallet_hold_id) {
            $needsMore = $attributes['reward_enabled'] && (float) $attributes['estimated_cost'] > $reserved + 0.00001;

            if (! $attributes['reward_enabled'] || $needsMore) {
                if ($campaign->status === Campaign::STATUS_PAUSED && $needsMore) {
                    return $this->result(true, 'The new audience or reward costs more than the reserved funds. Cancel this campaign and create a new one.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                        'reserved' => $reserved,
                        'required' => (float) $attributes['estimated_cost'],
                    ]);
                }

                // Reserved money no longer matches this campaign: free it and ask for funding again.
                $this->support->releaseHold($campaign);
                $reset = [
                    'wallet_hold_id' => null,
                    'funding_method' => null,
                    'payment_id' => null,
                    'reserved_amount' => 0,
                ];

                if ($campaign->status === Campaign::STATUS_FUNDED) {
                    $reset['status'] = Campaign::STATUS_DRAFT;
                }
            }
        }

        $campaign->update($attributes + $reset);

        $this->activity()->record('Campaign updated', 'campaign', 'updated', 'client', $client, $client, [
            'campaign_id' => $campaign->id,
        ]);

        return $this->result(false, 'Campaign updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($campaign->fresh()));
    }

    /**
     * Draft, funded, cancelled and completed campaigns can be removed; reserved money is released.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Client $client, Campaign $campaign): array
    {
        if (! in_array($campaign->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_FUNDED, Campaign::STATUS_COMPLETED, Campaign::STATUS_CANCELLED], true)) {
            return $this->result(true, 'Cancel or complete this campaign before deleting it.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $this->support->releaseHold($campaign);
        $campaign->delete();

        $this->activity()->record('Campaign deleted', 'campaign', 'deleted', 'client', $client, $client, [
            'campaign_id' => $campaign->id,
        ]);

        return $this->result(false, 'Campaign deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * Pay for the rewards. Wallet reserves the money at once; momo/card start a checkout and the
     * reservation is made when that payment completes.
     *
     * @param  array<string, mixed>  $input  method, phone_number, network, estimated_cost
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function fund(Client $client, Campaign $campaign, array $input): array
    {
        $method = (string) ($input['method'] ?? 'wallet');

        if (! in_array($method, self::FUND_METHODS, true)) {
            return $this->result(true, 'Choose a valid payment method.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'method' => ['Allowed: '.implode(', ', self::FUND_METHODS)],
            ]);
        }

        if (! $campaign->reward_enabled) {
            return $this->result(false, 'This campaign has no reward, so no funding is needed. You can activate it.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($campaign));
        }

        if ($this->support->isFunded($campaign) || $campaign->status !== Campaign::STATUS_DRAFT) {
            return $this->result(true, 'Only unfunded draft campaigns can be funded.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        $refreshed = $this->refreshEstimate($client, $campaign, $input['estimated_cost'] ?? null);

        if ($this->support->failed($refreshed)) {
            return $refreshed;
        }

        $campaign = $refreshed;
        $required = (float) $campaign->estimated_cost;

        if ($campaign->eligible_recipients < 1 || $required <= 0) {
            return $this->result(true, 'There are no eligible recipients to reward in the selected groups.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($method !== 'wallet') {
            $started = $this->checkout->start($client, 'campaign_fund', [
                'method' => $method,
                'phone_number' => $input['phone_number'] ?? null,
                'network' => $input['network'] ?? null,
                'amount' => $required,
                'name' => 'Campaign funding: '.$campaign->name,
                'campaign_id' => $campaign->id,
            ]);

            if (! $started['in_error']) {
                $campaign->update(['funding_method' => $method, 'payment_id' => $started['data']['id'] ?? null]);
                $started['data']['campaign_id'] = $campaign->id;
            }

            return $started;
        }

        $outcome = DB::transaction(function () use ($client, $campaign, $required) {
            $locked = Campaign::query()->lockForUpdate()->find($campaign->id);

            if (! $locked || $locked->status !== Campaign::STATUS_DRAFT || $locked->wallet_hold_id) {
                return $this->result(true, 'Only unfunded draft campaigns can be funded.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
            }

            $hold = $this->support->holdFromWallet($client, $locked, $required, 'campaign_fund', ['campaign_id' => $locked->id]);

            if ($this->support->failed($hold)) {
                return $hold;
            }

            $locked->update([
                'wallet_hold_id' => $hold->id,
                'funding_method' => 'wallet',
                'reserved_amount' => $hold->amount,
                'status' => Campaign::STATUS_FUNDED,
            ]);

            return null;
        }, 3);

        if ($outcome) {
            return $outcome;
        }

        $this->activity()->record('Campaign funded', 'campaign', 'funded', 'client', $client, $client, [
            'campaign_id' => $campaign->id,
            'method' => 'wallet',
            'amount' => $required,
        ]);

        return $this->result(false, 'Campaign funded from your wallet.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($campaign->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function activate(Client $client, Campaign $campaign): array
    {
        $ready = $campaign->status === Campaign::STATUS_FUNDED
            || ($campaign->status === Campaign::STATUS_DRAFT && ! $campaign->reward_enabled);

        if (! $ready) {
            $message = $campaign->status === Campaign::STATUS_DRAFT && $campaign->reward_enabled
                ? 'Fund this campaign before activating it.'
                : 'Only funded campaigns, or drafts without a reward, can be activated.';

            return $this->result(true, $message, ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($campaign->reward_enabled && ! $this->support->isFunded($campaign)) {
            return $this->result(true, 'The reserved funds for this campaign are no longer available. Fund it again.', ApiCode::BAD_REQUEST, ApiReason::FUNDS_RESERVED);
        }

        if ($campaign->eligible_recipients < 1) {
            return $this->result(true, 'There are no eligible recipients in the selected groups.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $sender = $this->support->sender($client, $campaign->sender);

        if ($this->support->failed($sender)) {
            return $sender;
        }

        $campaign->update([
            'status' => $this->liveStatus($campaign),
            'activated_at' => $campaign->activated_at ?? now(),
        ]);

        $this->activity()->record('Campaign activated', 'campaign', 'activated', 'client', $client, $client, [
            'campaign_id' => $campaign->id,
            'status' => $campaign->status,
        ]);

        // Promotions go out now unless they are scheduled; occasions are sent by campaigns:run-occasions.
        if ($campaign->status === Campaign::STATUS_ACTIVE && $campaign->type === Campaign::TYPE_PROMOTION) {
            DispatchEngagementBlastJob::dispatch('campaign', $campaign->id)->afterCommit();
        }

        return $this->result(false, 'Campaign activated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($campaign->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function pause(Client $client, Campaign $campaign): array
    {
        return $this->transition($client, $campaign, [Campaign::STATUS_ACTIVE, Campaign::STATUS_SCHEDULED], Campaign::STATUS_PAUSED, 'paused', 'Campaign paused.');
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function resume(Client $client, Campaign $campaign): array
    {
        if ($campaign->reward_enabled && ! $this->support->isFunded($campaign)) {
            return $this->result(true, 'The reserved funds for this campaign are no longer available.', ApiCode::BAD_REQUEST, ApiReason::FUNDS_RESERVED);
        }

        return $this->transition($client, $campaign, [Campaign::STATUS_PAUSED], $this->liveStatus($campaign), 'resumed', 'Campaign resumed.');
    }

    /**
     * Finishes the campaign and frees any reward money still reserved.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function complete(Client $client, Campaign $campaign): array
    {
        return $this->transition(
            $client,
            $campaign,
            [Campaign::STATUS_ACTIVE, Campaign::STATUS_SCHEDULED, Campaign::STATUS_PAUSED],
            Campaign::STATUS_COMPLETED,
            'completed',
            'Campaign completed. Unused reserved funds were released.',
            ['completed_at' => now()],
            true,
        );
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function cancel(Client $client, Campaign $campaign): array
    {
        return $this->transition(
            $client,
            $campaign,
            [Campaign::STATUS_ACTIVE, Campaign::STATUS_SCHEDULED, Campaign::STATUS_PAUSED, Campaign::STATUS_FUNDED],
            Campaign::STATUS_CANCELLED,
            'cancelled',
            'Campaign cancelled. Unused reserved funds were released.',
            ['cancelled_at' => now()],
            true,
        );
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function analytics(Campaign $campaign): array
    {
        return $this->result(false, 'Campaign analytics loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->summary($campaign));
    }

    /**
     * Stored analytics merged with live counts from campaign_events (the larger number wins,
     * so a sender that writes both never double counts).
     *
     * @return array<string, mixed>
     */
    public function summary(Campaign $campaign): array
    {
        $stored = is_array($campaign->analytics) ? $campaign->analytics : [];

        $counts = CampaignEvent::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event');

        $uniqueClicks = (int) CampaignEvent::query()
            ->where('campaign_id', $campaign->id)
            ->where('event', CampaignEvent::CLICK)
            ->whereNotNull('phone')
            ->distinct()
            ->count('phone');

        $merge = fn (string $event, string $key) => max((int) ($stored[$key] ?? 0), (int) ($counts[$event] ?? 0));

        $sent = $merge(CampaignEvent::SENT, 'sent');
        $delivered = $merge(CampaignEvent::DELIVERED, 'delivered');
        $failed = $merge(CampaignEvent::FAILED, 'failed');
        $clicks = $merge(CampaignEvent::CLICK, 'clicks');
        $disbursed = $merge(CampaignEvent::REWARD_DISBURSED, 'rewards_disbursed');
        $rewardsFailed = $merge(CampaignEvent::REWARD_FAILED, 'rewards_failed');

        $pending = array_key_exists('rewards_pending', $stored)
            ? (int) $stored['rewards_pending']
            : max(0, (int) ($counts[CampaignEvent::REWARD_PENDING] ?? 0) - $disbursed - $rewardsFailed);

        $unit = EngagementSupport::unitCost(is_array($campaign->reward) ? $campaign->reward : null);
        $actual = isset($stored['actual_cost']) ? round((float) $stored['actual_cost'], 2) : round($disbursed * $unit, 2);

        return [
            'sent' => $sent,
            'delivered' => $delivered,
            'failed' => $failed,
            'clicks' => $clicks,
            'unique_clicks' => max($uniqueClicks, (int) ($stored['unique_clicks'] ?? 0)),
            'delivery_rate' => $sent > 0 ? round($delivered / $sent * 100, 2) : 0.0,
            'click_rate' => $delivered > 0 ? round($clicks / $delivered * 100, 2) : 0.0,
            'rewards_disbursed' => $disbursed,
            'rewards_failed' => $rewardsFailed,
            'rewards_pending' => $pending,
            'reserved_remaining' => $this->support->holdRemaining($campaign),
            'estimated_cost' => (float) $campaign->estimated_cost,
            'actual_cost' => $actual,
            'eligible_recipients' => (int) $campaign->eligible_recipients,
        ];
    }

    /**
     * Record a tracking event (click, delivery, reward result…). Phone is optional.
     *
     * @param  array<string, mixed>  $meta
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function recordEvent(Campaign $campaign, string $event, ?string $phone = null, array $meta = []): array
    {
        if (! in_array($event, CampaignEvent::EVENTS, true)) {
            return $this->result(true, 'Unknown event.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'event' => ['Allowed: '.implode(', ', CampaignEvent::EVENTS)],
            ]);
        }

        $mobile = GhanaPhone::toMobile($phone);
        $contact = $mobile
            ? $this->support->contactForPhone($campaign->client, array_map('intval', $campaign->contact_group_ids ?? []), $mobile)
            : null;

        $record = CampaignEvent::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact?->id,
            'phone' => $mobile,
            'event' => $event,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);

        return $this->result(false, 'Event recorded.', ApiCode::CREATED, ApiReason::CREATED, [
            'id' => $record->id,
            'campaign_id' => $campaign->id,
            'event' => $record->event,
            'phone' => $record->phone,
            'created_at' => optional($record->created_at)->toDateTimeString(),
        ]);
    }

    /**
     * Public link click. Only campaigns that have gone out can be tracked.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function click(Campaign $campaign, ?string $phone = null): array
    {
        if (! in_array($campaign->status, [Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED], true)) {
            return $this->result(true, 'Campaign not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $result = $this->recordEvent($campaign, CampaignEvent::CLICK, $phone);

        if ($result['in_error']) {
            return $result;
        }

        // A CTA marked triggers_reward pays the reward to the person who clicked (once per phone).
        // Yearly occasions can reward the same person again next year.
        $period = $campaign->type === Campaign::TYPE_OCCASION && $campaign->repeat_yearly ? (string) now()->year : 'once';
        $this->rewards->queueFromCampaignEvent($campaign, $phone, 'click', $period);

        return $this->result(false, 'Click recorded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'cta' => $campaign->cta,
        ]);
    }

    /**
     * Re-count the audience and cost (contacts change after a draft is saved).
     *
     * @return Campaign|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function refreshEstimate(Client $client, Campaign $campaign, mixed $override): Campaign|array
    {
        $groups = $this->support->groupIds($client, $campaign->contact_group_ids ?? []);

        if ($this->support->failed($groups)) {
            return $groups;
        }

        $audience = $this->support->audience($client, $groups);
        $snapshot = is_array($campaign->reward) ? $campaign->reward : null;

        if ($snapshot && is_numeric($override) && (float) $override > 0 && ($snapshot['type'] ?? null) === 'data') {
            $snapshot['estimate_override'] = round((float) $override, 2);
        }

        $campaign->update([
            'reward' => $snapshot,
            'eligible_recipients' => $audience['eligible'],
            'not_eligible' => $audience['not_eligible'],
            'estimated_cost' => $this->support->estimate($snapshot, $audience['eligible'], $snapshot['estimate_override'] ?? null),
            'estimated_cost_units' => SmsParts::count($campaign->body) * $audience['eligible'],
        ]);

        return $campaign->fresh();
    }

    /**
     * Status after activation/resume: scheduled while the start moment is still ahead.
     */
    protected function liveStatus(Campaign $campaign): string
    {
        $start = $campaign->type === Campaign::TYPE_PROMOTION ? $campaign->send_at : $campaign->custom_date?->copy()->startOfDay();

        return $start && $start->isFuture() ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_ACTIVE;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function transition(Client $client, Campaign $campaign, array $from, string $to, string $action, string $message, array $extra = [], bool $release = false): array
    {
        $moved = DB::transaction(function () use ($campaign, $from, $to, $extra, $release) {
            $locked = Campaign::query()->lockForUpdate()->find($campaign->id);

            if (! $locked || ! in_array($locked->status, $from, true)) {
                return false;
            }

            $locked->update(['status' => $to] + $extra);

            if ($release) {
                $this->support->releaseHold($locked);
            }

            return true;
        }, 3);

        if (! $moved) {
            return $this->result(true, 'This action is not available for a '.$campaign->fresh()->status.' campaign.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $this->activity()->record('Campaign '.$action, 'campaign', $action, 'client', $client, $client, [
            'campaign_id' => $campaign->id,
        ]);

        return $this->result(false, $message, ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($campaign->fresh()));
    }

    /**
     * Validate type-specific fields and resolve groups, sender, reward and audience.
     * On update, anything not sent keeps its current value.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function build(Client $client, array $input, ?Campaign $existing): array
    {
        $get = function (string $key, mixed $default = null) use ($input, $existing) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }

            return $existing?->{$key} ?? $default;
        };

        $type = $existing?->type ?? ($input['type'] ?? null);

        if (! in_array($type, Campaign::TYPES, true)) {
            return $this->invalid(['type' => ['Allowed: '.implode(', ', Campaign::TYPES)]]);
        }

        $errors = [];
        $name = trim((string) $get('name', ''));
        $body = trim((string) $get('body', ''));

        if ($name === '') {
            $errors['name'] = ['A campaign name is required.'];
        }

        if ($body === '') {
            $errors['body'] = ['The message is required.'];
        }

        $fields = [
            'occasion_type' => null,
            'custom_date' => null,
            'trigger_offset' => null,
            'custom_offset_days' => null,
            'send_time' => null,
            'repeat_yearly' => false,
            'send_at' => null,
        ];

        if ($type === Campaign::TYPE_OCCASION) {
            $occasion = (string) $get('occasion_type', '');
            $trigger = (string) $get('trigger_offset', 'on');
            $sendTime = (string) $get('send_time', '');

            if (! in_array($occasion, self::OCCASION_TYPES, true)) {
                $errors['occasion_type'] = ['Allowed: '.implode(', ', self::OCCASION_TYPES)];
            }

            $customDate = $get('custom_date');

            if ($occasion === 'custom' && ! $customDate) {
                $errors['custom_date'] = ['A date is required for a custom occasion.'];
            }

            if (! in_array($trigger, self::TRIGGER_OFFSETS, true)) {
                $errors['trigger_offset'] = ['Allowed: '.implode(', ', self::TRIGGER_OFFSETS)];
            }

            $offsetDays = $get('custom_offset_days');

            if (in_array($trigger, ['before', 'after', 'custom'], true)
                && (! is_numeric($offsetDays) || (int) $offsetDays < 0 || (int) $offsetDays > 365)) {
                $errors['custom_offset_days'] = ['Days must be between 0 and 365 for before/after/custom offsets.'];
            }

            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sendTime)) {
                $errors['send_time'] = ['Send time must be HH:MM (24 hour).'];
            }

            $fields = [
                'occasion_type' => $occasion,
                'custom_date' => $customDate ? Carbon::parse($customDate)->toDateString() : null,
                'trigger_offset' => $trigger,
                'custom_offset_days' => in_array($trigger, ['before', 'after', 'custom'], true) ? (int) $offsetDays : null,
                'send_time' => $sendTime,
                'repeat_yearly' => filter_var($get('repeat_yearly', $occasion !== 'custom'), FILTER_VALIDATE_BOOLEAN),
                'send_at' => null,
            ];
        } else {
            $sendAt = $existing?->send_at;

            if (array_key_exists('send_at', $input)) {
                $sendAt = $input['send_at'] ? Carbon::parse($input['send_at']) : null;

                if ($sendAt && $sendAt->isPast()) {
                    $errors['send_at'] = ['The send time must be in the future.'];
                }
            }

            $fields['send_at'] = $sendAt;
        }

        $cta = $get('cta');

        if ($cta !== null) {
            if (! is_array($cta)) {
                $errors['cta'] = ['CTA must be an object.'];
            } elseif (filter_var($cta['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)
                && empty($cta['value']) && empty($cta['url'])) {
                $errors['cta'] = ['A call to action needs a value (or url).'];
            } elseif (is_array($cta)) {
                // Normalize frontend shape: value is the canonical link/ussd/phone target.
                $cta['value'] = $cta['value'] ?? $cta['url'] ?? null;
                $cta['url'] = $cta['url'] ?? ((isset($cta['type']) && $cta['type'] === 'link') ? $cta['value'] : null);
                $input['cta'] = $cta;
            }
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $sender = $this->support->sender($client, (string) $get('sender', ''));

        if ($this->support->failed($sender)) {
            return $sender;
        }

        $groups = $this->support->groupIds($client, $get('contact_group_ids', []));

        if ($this->support->failed($groups)) {
            return $groups;
        }

        $reward = $this->rewardFields($input, $existing);

        if ($this->support->failed($reward)) {
            return $reward;
        }

        $audience = $this->support->audience($client, $groups);
        $eligible = $audience['eligible'];

        return $fields + [
            'name' => $name,
            'type' => $type,
            'contact_group_ids' => $groups,
            'sender' => $sender,
            'body' => $body,
            'cta' => $cta,
            'reward_enabled' => $reward['enabled'],
            'reward' => $reward['snapshot'],
            'reward_id' => $reward['reward_id'],
            'eligible_recipients' => $eligible,
            'not_eligible' => $audience['not_eligible'],
            'estimated_cost' => $reward['enabled']
                ? $this->support->estimate($reward['snapshot'], $eligible, $reward['snapshot']['estimate_override'] ?? null)
                : 0,
            'estimated_cost_units' => SmsParts::count($body) * $eligible,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, snapshot: array<string, mixed>|null, reward_id: int|null}|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function rewardFields(array $input, ?Campaign $existing): array
    {
        $given = $this->support->rewardInput($input);
        $override = $input['estimated_cost'] ?? ($given['inline']['estimate_override'] ?? null);

        if (! $given['present']) {
            $snapshot = $existing && is_array($existing->reward) ? $existing->reward : null;
            $enabled = (bool) ($existing?->reward_enabled ?? false);
            $id = $existing?->reward_id;
        } elseif (! $given['enabled']) {
            return ['enabled' => false, 'snapshot' => null, 'reward_id' => null];
        } elseif ($given['reward_id']) {
            $reward = $this->support->reward($given['reward_id']);

            if ($this->support->failed($reward)) {
                return $reward;
            }

            $snapshot = $this->support->snapshot($reward);
            $enabled = true;
            $id = $reward->id;
        } elseif (is_array($given['inline'] ?? null)) {
            $snapshot = $this->support->inlineSnapshot($given['inline']);

            if ($this->support->failed($snapshot)) {
                return $snapshot;
            }

            $enabled = true;
            $id = null;
        } elseif ($existing && is_array($existing->reward)) {
            $snapshot = $existing->reward;
            $enabled = true;
            $id = $existing->reward_id;
        } else {
            return $this->result(true, 'Provide a reward type and amount, or a catalog reward_id.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward' => ['Provide a reward type and amount, or a catalog reward_id.'],
            ]);
        }

        if ($snapshot && is_numeric($override) && (float) $override > 0 && ($snapshot['type'] ?? null) === 'data') {
            $snapshot['estimate_override'] = round((float) $override, 2);
        }

        return ['enabled' => $enabled && $snapshot !== null, 'snapshot' => $snapshot, 'reward_id' => $id];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function invalid(array $errors): array
    {
        return $this->result(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $errors);
    }

    /**
     * @return array<string, int|float>
     */
    protected function emptyAnalytics(): array
    {
        return [
            'sent' => 0,
            'delivered' => 0,
            'failed' => 0,
            'clicks' => 0,
            'rewards_disbursed' => 0,
            'rewards_failed' => 0,
            'rewards_pending' => 0,
            'actual_cost' => 0,
        ];
    }
}
