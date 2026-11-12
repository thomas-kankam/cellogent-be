<?php

namespace App\Services;

use App\Concerns\ReturnsServiceResult;
use App\Jobs\ProcessRewardDisbursementJob;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Reward;
use App\Models\RewardDisbursement;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\UtilityOrder;
use App\Models\WalletHold;
use App\Services\Engagement\EngagementSupport;
use App\Services\Payments\PaystackTransferService;
use App\Services\Utilities\UtilityService;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pays campaign / survey rewards to recipients.
 *
 * A reward is queued once per source + phone + period (unique key), then processed by a job:
 *  - airtime / data: bought from Swift as a `reward` utility order,
 *  - money: sent with the Paystack Transfer API.
 * The client's reserved wallet money (the hold) is only consumed when the reward really went
 * out, so a failure leaves it reserved for the next recipient.
 */
class RewardDisbursementService
{
    use ReturnsServiceResult;

    public const MAX_ATTEMPTS = 5;

    /** Minutes to wait before retrying a transient failure, by attempt number. */
    protected const BACKOFF = [1 => 1, 2 => 5, 3 => 15, 4 => 60];

    public function __construct(
        protected WalletService $wallets,
        protected UtilityService $utilities,
        protected PaystackTransferService $paystack,
        protected EngagementSupport $support,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Queueing
    |--------------------------------------------------------------------------
    */

    /**
     * When does a campaign pay its reward? `click` (CTA with triggers_reward), `send` (no CTA at all)
     * or null (a CTA that does not trigger the reward).
     */
    public function campaignTrigger(Campaign $campaign): ?string
    {
        if (! $campaign->reward_enabled || ! is_array($campaign->reward)) {
            return null;
        }

        $cta = is_array($campaign->cta) ? $campaign->cta : [];
        $hasCta = $cta !== [] && filter_var($cta['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($hasCta) {
            return filter_var($cta['triggers_reward'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'click' : null;
        }

        return 'send';
    }

    /**
     * Queue the campaign reward for a phone. Safe to call again: one reward per phone and period.
     *
     * @param  string  $period  'once' or e.g. the year for a yearly occasion
     */
    public function queueFromCampaignEvent(Campaign $campaign, ?string $phone, string $trigger, string $period = 'once'): ?RewardDisbursement
    {
        if ($this->campaignTrigger($campaign) !== $trigger || $campaign->status !== Campaign::STATUS_ACTIVE) {
            return null;
        }

        $mobile = GhanaPhone::toMobile($phone);

        if (! $mobile) {
            return null;
        }

        $contact = $this->support->contactForPhone($campaign->client, array_map('intval', $campaign->contact_group_ids ?? []), $mobile);

        // Rewards go only to people on the campaign's lists.
        if (! $contact) {
            return null;
        }

        // Affordable MTN SME data: skip numbers that are not verified beneficiaries.
        if ($this->support->rewardNeedsBeneficiary(is_array($campaign->reward) ? $campaign->reward : null)
            && $contact->beneficiary_status !== \App\Models\Contact::BENEFICIARY_VERIFIED) {
            return null;
        }

        return $this->queue($campaign, RewardDisbursement::SOURCE_CAMPAIGN, $mobile, $contact, $period, ['trigger' => $trigger]);
    }

    /**
     * Queue the survey reward for a response that was marked `pending`.
     */
    public function queueFromSurveyResponse(Survey $survey, SurveyResponse $response): ?RewardDisbursement
    {
        if (! $survey->reward_enabled || $response->reward_status !== 'pending') {
            return null;
        }

        $mobile = GhanaPhone::toMobile($response->phone);

        if (! $mobile) {
            return null;
        }

        $contact = $response->contact_id ? Contact::query()->find($response->contact_id) : null;

        return $this->queue($survey, RewardDisbursement::SOURCE_SURVEY, $mobile, $contact, 'once', ['response_id' => $response->id]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function queue(Model $source, string $sourceType, string $phone, ?Contact $contact, string $period, array $meta): ?RewardDisbursement
    {
        $snapshot = is_array($source->reward) ? $source->reward : null;
        $type = $snapshot['type'] ?? null;
        $unit = EngagementSupport::unitCost($snapshot);

        if (! $snapshot || ! in_array($type, Reward::TYPES, true) || $unit <= 0) {
            return null;
        }

        $find = fn () => RewardDisbursement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $source->id)
            ->where('phone', $phone)
            ->where('period', $period)
            ->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            $row = DB::transaction(function () use ($source, $sourceType, $phone, $contact, $period, $meta, $snapshot, $type, $unit) {
                // Serialise on the hold so two simultaneous clicks cannot both claim the last reward.
                $hold = $source->wallet_hold_id
                    ? WalletHold::query()->whereKey($source->wallet_hold_id)->lockForUpdate()->first()
                    : null;

                $committed = (float) RewardDisbursement::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $source->id)
                    ->whereIn('status', [RewardDisbursement::PENDING, RewardDisbursement::PROCESSING])
                    ->sum('amount');

                $room = $hold && $hold->status === WalletHold::ACTIVE ? (float) $hold->remaining - $committed : 0.0;
                $funded = $room + 0.00001 >= $unit;

                return RewardDisbursement::create([
                    'client_id' => $source->client_id,
                    'source_type' => $sourceType,
                    'source_id' => $source->id,
                    'contact_id' => $contact?->id,
                    'phone' => $phone,
                    'period' => $period,
                    'reward_type' => $type,
                    'amount' => $unit,
                    'network' => $snapshot['network'] ?? null,
                    'package_id' => $snapshot['package_id'] ?? $snapshot['bundle_id'] ?? null,
                    'status' => $funded ? RewardDisbursement::PENDING : RewardDisbursement::FAILED,
                    'provider' => $type === Reward::MONEY ? 'paystack' : 'swift',
                    'reference' => 'rwd-'.Str::lower(Str::random(20)),
                    'wallet_hold_id' => $source->wallet_hold_id,
                    'failure_reason' => $funded ? null : 'The reserved reward funds are used up.',
                    'meta' => $meta + ['snapshot' => $snapshot, 'queued_at' => now()->toDateTimeString()],
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $find();
        }

        if ($row->status === RewardDisbursement::FAILED) {
            $this->settled($row, null, RewardDisbursement::FAILED);

            return $row;
        }

        $this->settled($row, null, RewardDisbursement::PENDING);
        ProcessRewardDisbursementJob::dispatch($row->id)->afterCommit();

        return $row;
    }

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    */

    /**
     * Pay one pending disbursement. Does nothing unless it is still pending, so a duplicate job is harmless.
     */
    public function process(RewardDisbursement|int $disbursement): ?RewardDisbursement
    {
        $id = $disbursement instanceof RewardDisbursement ? $disbursement->id : $disbursement;

        $claimed = DB::transaction(function () use ($id) {
            $locked = RewardDisbursement::query()->lockForUpdate()->find($id);

            if (! $locked || $locked->status !== RewardDisbursement::PENDING) {
                return null;
            }

            $locked->update([
                'status' => RewardDisbursement::PROCESSING,
                'attempts' => (int) $locked->attempts + 1,
                'next_attempt_at' => null,
            ]);

            return $locked->fresh();
        }, 3);

        if (! $claimed) {
            return null;
        }

        try {
            $this->run($claimed);
        } catch (Throwable $e) {
            report($e);
            $this->retryLater($claimed->fresh(), 'Unexpected error: '.Str::limit($e->getMessage(), 150));
        }

        return $claimed->fresh();
    }

    protected function run(RewardDisbursement $d): void
    {
        $client = Client::query()->find($d->client_id);
        $hold = $d->wallet_hold_id ? WalletHold::query()->find($d->wallet_hold_id) : null;

        if (! $client) {
            $this->fail($d, 'The client no longer exists.');

            return;
        }

        if (! $hold || $hold->status !== WalletHold::ACTIVE || (float) $hold->remaining + 0.00001 < (float) $d->amount) {
            $this->fail($d, 'The reserved reward funds are no longer available.');

            return;
        }

        if ($d->reward_type === Reward::MONEY) {
            $this->runMoney($d, $client);

            return;
        }

        $this->runUtility($d, $client);
    }

    protected function runUtility(RewardDisbursement $d, Client $client): void
    {
        $order = $d->utility_order_id ? UtilityOrder::query()->find($d->utility_order_id) : null;

        if (! $order) {
            $order = $this->utilities->createRewardOrder($client, (array) ($d->meta['snapshot'] ?? []), $d->phone, $d->reference);

            if (is_array($order)) {
                // The catalog / provider is unreachable: try again later instead of failing the reward.
                $order['reason'] === ApiReason::PROVIDER_UNAVAILABLE
                    ? $this->retryLater($d, (string) $order['message'])
                    : $this->fail($d, (string) $order['message']);

                return;
            }

            $d->update(['utility_order_id' => $order->id, 'provider_reference' => $order->provider_reference]);
        }

        $order = $this->utilities->process($order);

        $this->settleFromOrder($d->fresh(), $order);
    }

    protected function runMoney(RewardDisbursement $d, Client $client): void
    {
        if (! $this->paystack->configured()) {
            $this->fail($d, 'Money rewards are not available right now.');

            return;
        }

        $meta = $d->meta ?? [];

        // A previous attempt may have reached Paystack: look before sending again.
        if (! empty($meta['transfer_sent_at'])) {
            $seen = $this->paystack->verify($d->reference);

            if (! empty($seen['not_found'])) {
                unset($meta['transfer_sent_at']);
                $d->update(['meta' => $meta]);
            } else {
                $this->settleFromTransfer($d->fresh(), $seen);

                return;
            }
        }

        if (empty($meta['recipient_code'])) {
            $contact = $d->contact_id ? Contact::query()->find($d->contact_id) : null;
            $name = trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''));
            $recipient = $this->paystack->recipient($d->phone, $name, $d->network);

            if ($recipient['state'] === PaystackTransferService::UNAVAILABLE) {
                $this->retryLater($d, (string) $recipient['message']);

                return;
            }

            if ($recipient['state'] !== PaystackTransferService::SUCCESS) {
                $this->fail($d, (string) $recipient['message']);

                return;
            }

            $meta['recipient_code'] = $recipient['recipient_code'];
        }

        $meta['transfer_sent_at'] = now()->toDateTimeString();
        $d->update(['meta' => $meta]);

        $transfer = $this->paystack->transfer((string) $meta['recipient_code'], (float) $d->amount, $d->reference, 'Reward from '.($client->business_name ?: $client->first_name));

        // Not sure whether Paystack created it (timeout / 5xx): keep it processing, reconcile verifies.
        if ($transfer['state'] === PaystackTransferService::UNAVAILABLE && empty($transfer['ambiguous'])) {
            unset($meta['transfer_sent_at']);
            $d->update(['meta' => $meta]);
            $this->retryLater($d->fresh(), (string) $transfer['message']);

            return;
        }

        $this->settleFromTransfer($d->fresh(), $transfer);
    }

    /**
     * @param  array<string, mixed>  $transfer  PaystackTransferService result
     */
    protected function settleFromTransfer(RewardDisbursement $d, array $transfer): void
    {
        $meta = $d->meta ?? [];
        $meta['last_checked_at'] = now()->toDateTimeString();

        if (! empty($transfer['transfer_code'])) {
            $meta['transfer_code'] = $transfer['transfer_code'];
        }

        $d->update(['meta' => $meta, 'provider_reference' => $transfer['transfer_code'] ?? $d->provider_reference]);

        match ($transfer['state']) {
            PaystackTransferService::SUCCESS => $this->finalizeSuccess($d->fresh(), $transfer['transfer_code'] ?? null),
            PaystackTransferService::FAILED => $this->finalizeFailure($d->fresh(), (string) $transfer['message']),
            default => null, // pending / unclear: stays processing until the webhook or reconcile
        };
    }

    protected function settleFromOrder(RewardDisbursement $d, UtilityOrder $order): void
    {
        if ($order->status === 'completed') {
            $this->finalizeSuccess($d, $order->provider_reference);
        } elseif ($order->status === 'failed') {
            $this->finalizeFailure($d, (string) ($order->failure_reason ?: 'The provider could not deliver the reward.'));
        } else {
            $d->update(['provider_reference' => $order->provider_reference ?: $d->provider_reference]);
        }
    }

    /**
     * Called by UtilityService when a reward order completes or fails (webhook, status check, sync reply).
     */
    public function syncFromUtilityOrder(UtilityOrder $order): void
    {
        $d = RewardDisbursement::query()->where('utility_order_id', $order->id)->first();

        if ($d) {
            $this->settleFromOrder($d, $order);
        }
    }

    /**
     * Paystack `transfer.*` webhook: re-read the transfer from Paystack instead of trusting the body.
     *
     * @param  array<string, mixed>  $data  the event's `data`
     */
    public function handlePaystackTransfer(array $data): void
    {
        $code = (string) ($data['transfer_code'] ?? '');
        $reference = (string) ($data['reference'] ?? '');

        if ($code === '' && $reference === '') {
            return;
        }

        $d = RewardDisbursement::query()
            ->where('provider', 'paystack')
            ->where(fn ($q) => $q
                ->when($code !== '', fn ($w) => $w->orWhere('provider_reference', $code))
                ->when($reference !== '', fn ($w) => $w->orWhere('reference', $reference)))
            ->first();

        if (! $d || $d->status === RewardDisbursement::PAID) {
            return;
        }

        $this->settleFromTransfer($d, $this->paystack->verify($d->reference));
    }

    /**
     * Mark paid and take the money out of the client's reserved funds. Idempotent.
     */
    protected function finalizeSuccess(RewardDisbursement $d, ?string $providerReference = null): void
    {
        $previous = null;

        $paid = DB::transaction(function () use ($d, $providerReference, &$previous) {
            $locked = RewardDisbursement::query()->lockForUpdate()->find($d->id);

            if (! $locked || $locked->status === RewardDisbursement::PAID) {
                return null;
            }

            $previous = $locked->status;
            $client = Client::query()->find($locked->client_id);
            $hold = $locked->wallet_hold_id ? WalletHold::query()->find($locked->wallet_hold_id) : null;
            $debited = $client && $hold
                && $this->wallets->debitFromHold($client, $hold, (float) $locked->amount, 'reward_disbursement', 'Reward '.$locked->reference);

            if (! $debited) {
                Log::warning('Reward paid but the reserved funds could not be collected.', ['disbursement_id' => $locked->id]);
            }

            $meta = $locked->meta ?? [];
            $meta['funds_collected'] = (bool) $debited;

            $locked->update([
                'status' => RewardDisbursement::PAID,
                'paid_at' => now(),
                'failure_reason' => null,
                'provider_reference' => $providerReference ?: $locked->provider_reference,
                'meta' => $meta,
            ]);

            return $locked->fresh();
        }, 3);

        if ($paid) {
            $this->settled($paid, $previous, RewardDisbursement::PAID);
        }
    }

    /**
     * Mark failed. The reserved funds stay reserved. Idempotent; a paid reward never flips back.
     */
    protected function finalizeFailure(RewardDisbursement $d, string $reason): void
    {
        $previous = null;

        $failed = DB::transaction(function () use ($d, $reason, &$previous) {
            $locked = RewardDisbursement::query()->lockForUpdate()->find($d->id);

            if (! $locked || in_array($locked->status, [RewardDisbursement::PAID, RewardDisbursement::FAILED], true)) {
                return null;
            }

            $previous = $locked->status;
            $locked->update(['status' => RewardDisbursement::FAILED, 'failure_reason' => Str::limit($reason, 250, '')]);

            return $locked->fresh();
        }, 3);

        if ($failed) {
            $this->settled($failed, $previous, RewardDisbursement::FAILED);
        }
    }

    protected function fail(RewardDisbursement $d, string $reason): void
    {
        $this->finalizeFailure($d->fresh(), $reason);
    }

    /**
     * Transient problem: put it back to pending with a delay, or give up after MAX_ATTEMPTS.
     */
    protected function retryLater(RewardDisbursement $d, string $reason): void
    {
        $attempts = (int) $d->attempts;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->finalizeFailure($d, $reason);

            return;
        }

        RewardDisbursement::query()
            ->whereKey($d->id)
            ->where('status', RewardDisbursement::PROCESSING)
            ->update([
                'status' => RewardDisbursement::PENDING,
                'failure_reason' => Str::limit($reason, 250, ''),
                'next_attempt_at' => now()->addMinutes(self::BACKOFF[$attempts] ?? 60),
            ]);
    }

    /**
     * Keep the campaign / survey counters, events and the survey response in step with the disbursement.
     */
    protected function settled(RewardDisbursement $d, ?string $previous, string $now): void
    {
        $amount = (float) $d->amount;

        if ($d->source_type === RewardDisbursement::SOURCE_CAMPAIGN) {
            $campaign = Campaign::withTrashed()->find($d->source_id);

            if (! $campaign) {
                return;
            }

            $event = match ($now) {
                RewardDisbursement::PENDING => CampaignEvent::REWARD_PENDING,
                RewardDisbursement::PAID => CampaignEvent::REWARD_DISBURSED,
                default => CampaignEvent::REWARD_FAILED,
            };

            CampaignEvent::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $d->contact_id,
                'phone' => $d->phone,
                'event' => $event,
                'meta' => ['disbursement_id' => $d->id, 'amount' => $amount, 'reason' => $d->failure_reason],
                'created_at' => now(),
            ]);

            $deltas = [];
            $wasOpen = in_array($previous, [RewardDisbursement::PENDING, RewardDisbursement::PROCESSING], true);

            if ($now === RewardDisbursement::PENDING) {
                $deltas['rewards_pending'] = 1;
            } elseif ($now === RewardDisbursement::PAID) {
                $deltas['rewards_pending'] = $wasOpen ? -1 : 0;
                $deltas['rewards_failed'] = $previous === RewardDisbursement::FAILED ? -1 : 0;
                $deltas['rewards_disbursed'] = 1;
                $deltas['actual_cost'] = $amount;
            } else {
                // a reward that failed while being queued was never counted as pending
                $deltas['rewards_pending'] = $wasOpen ? -1 : 0;
                $deltas['rewards_failed'] = 1;
            }

            $this->bump($campaign, $deltas);

            return;
        }

        $survey = Survey::withTrashed()->find($d->source_id);

        if (! $survey || $now === RewardDisbursement::PENDING) {
            return;
        }

        $response = SurveyResponse::query()->where('survey_id', $survey->id)->where('phone', $d->phone)->first();

        if ($response) {
            $response->update([
                'reward_status' => $now === RewardDisbursement::PAID ? 'disbursed' : 'failed',
                'reward_meta' => array_merge($response->reward_meta ?? [], ['disbursement_id' => $d->id, 'settled_at' => now()->toDateTimeString()]),
            ]);
        }

        if ($now === RewardDisbursement::PAID) {
            $this->bump($survey, ['actual_cost' => $amount]);
        }
    }

    /**
     * @param  array<string, int|float>  $deltas
     */
    protected function bump(Model $source, array $deltas): void
    {
        $deltas = array_filter($deltas, fn ($value) => $value != 0);

        if ($deltas === []) {
            return;
        }

        DB::transaction(function () use ($source, $deltas) {
            $locked = $source::query()->withTrashed()->lockForUpdate()->find($source->id);

            if (! $locked) {
                return;
            }

            $analytics = is_array($locked->analytics) ? $locked->analytics : [];

            foreach ($deltas as $key => $delta) {
                $analytics[$key] = $key === 'actual_cost'
                    ? round((float) ($analytics[$key] ?? 0) + $delta, 2)
                    : max(0, (int) ($analytics[$key] ?? 0) + (int) $delta);
            }

            $locked->update(['analytics' => $analytics]);
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    */

    /**
     * Dispatch due pending rewards, un-stick abandoned claims and check rewards waiting on a provider.
     *
     * @return array{dispatched: int, reset: int, reconciled: int}
     */
    public function dispatchPending(int $limit = 200): array
    {
        $ids = RewardDisbursement::query()
            ->where('status', RewardDisbursement::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            // A job that has not started yet is not queued a second time next minute.
            RewardDisbursement::query()->whereKey($id)->update(['next_attempt_at' => now()->addMinutes(5)]);
            ProcessRewardDisbursementJob::dispatch((int) $id);
        }

        // A worker died after claiming but before reaching any provider (no order / transfer yet).
        $reset = RewardDisbursement::query()
            ->where('status', RewardDisbursement::PROCESSING)
            ->whereNull('utility_order_id')
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->get()
            ->filter(fn (RewardDisbursement $d) => empty($d->meta['transfer_sent_at']))
            ->each(fn (RewardDisbursement $d) => $d->update(['status' => RewardDisbursement::PENDING, 'next_attempt_at' => null]))
            ->count();

        $reconciled = 0;

        RewardDisbursement::query()
            ->where('status', RewardDisbursement::PROCESSING)
            ->where('updated_at', '<=', now()->subMinute())
            ->where(fn ($q) => $q->whereNotNull('utility_order_id')->orWhere('provider', 'paystack'))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (RewardDisbursement $d) use (&$reconciled) {
                try {
                    $this->reconcile($d);
                    $reconciled++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return ['dispatched' => $ids->count(), 'reset' => $reset, 'reconciled' => $reconciled];
    }

    /**
     * Ask the provider where a processing reward stands.
     */
    public function reconcile(RewardDisbursement $d): void
    {
        if ($d->provider === 'paystack') {
            if (! empty($d->meta['transfer_sent_at'])) {
                $this->settleFromTransfer($d, $this->paystack->verify($d->reference));
            }

            return;
        }

        $order = $d->utility_order_id ? UtilityOrder::query()->find($d->utility_order_id) : null;

        if (! $order) {
            return;
        }

        $order = $this->utilities->refresh($order);
        $this->settleFromOrder($d->fresh(), $order);
        $d->touch();
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters  status, source_type, source_id, reward_type, phone, client_id, from, to
     */
    public function index(?Client $client, array $filters = []): Builder
    {
        $query = RewardDisbursement::query()->latest('id');

        if ($client) {
            $query->where('client_id', $client->id);
        } elseif (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }

        foreach (['status', 'source_type', 'reward_type', 'provider'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['source_id'])) {
            $query->where('source_id', (int) $filters['source_id']);
        }

        if (! empty($filters['phone'])) {
            $query->where('phone', 'like', '%'.preg_replace('/\D+/', '', (string) $filters['phone']).'%');
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(RewardDisbursement $d, bool $admin = false): array
    {
        $data = [
            'id' => $d->id,
            'resource' => 'reward_disbursement',
            'reference' => $d->reference,
            'source_type' => $d->source_type,
            'source_id' => $d->source_id,
            'contact_id' => $d->contact_id,
            'phone' => $d->phone,
            'reward_type' => $d->reward_type,
            'amount' => (float) $d->amount,
            'network' => $d->network,
            'package_id' => $d->package_id,
            'status' => $d->status,
            'failure_reason' => $d->failure_reason,
            'utility_order_id' => $d->utility_order_id,
            'paid_at' => optional($d->paid_at)->toDateTimeString(),
            'created_at' => optional($d->created_at)->toDateTimeString(),
        ];

        if (! $admin) {
            return $data;
        }

        return $data + [
            'client_id' => $d->client_id,
            'provider' => $d->provider,
            'provider_reference' => $d->provider_reference,
            'wallet_hold_id' => $d->wallet_hold_id,
            'attempts' => (int) $d->attempts,
            'next_attempt_at' => optional($d->next_attempt_at)->toDateTimeString(),
            'meta' => $d->meta,
        ];
    }
}
