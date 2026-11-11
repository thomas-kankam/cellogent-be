<?php

namespace App\Services\Engagement;

use App\Jobs\DispatchEngagementBlastJob;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Survey;
use App\Services\RewardDisbursementService;
use App\Services\Sms\SmsService;
use App\Support\GhanaPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the SMS for campaigns and surveys through the normal SMS pipeline (client SMS units,
 * Kairos aggregator): one blast when a promotion / survey goes live, and personalised sends for
 * occasion campaigns (see OccasionService).
 *
 * Placeholders (any case, `_` optional): {firstName} {lastName} {name} {businessName}
 * {rewardAmount} {rewardName} {bundleSize} {rewardType} {surveyUrl} {link}
 */
class EngagementBlastService
{
    /** A blast that stalled mid-way is picked up again after this many minutes. */
    protected const CLAIM_MINUTES = 30;

    protected const MAX_ATTEMPTS = 5;

    public function __construct(
        protected SmsService $sms,
        protected RewardDisbursementService $rewards,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    */

    /**
     * Queue a blast job for every promotion / survey that is live (or due) and not sent yet.
     */
    public function dispatchDue(int $limit = 100): int
    {
        $count = 0;

        $campaigns = Campaign::query()
            ->where('type', Campaign::TYPE_PROMOTION)
            ->whereIn('status', [Campaign::STATUS_SCHEDULED, Campaign::STATUS_ACTIVE])
            ->whereNull('analytics->blasted_at')
            ->whereNull('analytics->blast_failed_at')
            ->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $surveys = Survey::query()
            ->whereIn('status', [Survey::STATUS_SCHEDULED, Survey::STATUS_ACTIVE])
            ->whereNull('analytics->blasted_at')
            ->whereNull('analytics->blast_failed_at')
            ->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($campaigns->concat($surveys) as $item) {
            $analytics = is_array($item->analytics) ? $item->analytics : [];
            $retryAt = $analytics['blast_next_attempt_at'] ?? null;

            if ($retryAt && now()->lt($retryAt)) {
                continue;
            }

            DispatchEngagementBlastJob::dispatch($item instanceof Campaign ? 'campaign' : 'survey', $item->id);
            $count++;
        }

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | One blast
    |--------------------------------------------------------------------------
    */

    /**
     * Send the campaign / survey SMS to its contact groups once.
     *
     * @return array{status: string, sent: int, failed: int, message: string|null}
     */
    public function blast(string $kind, int $id): array
    {
        $item = $this->find($kind, $id);

        if (! $item) {
            return $this->outcome('missing');
        }

        if (! $this->claim($item)) {
            return $this->outcome('skipped');
        }

        $item = $this->find($kind, $id);
        $client = Client::query()->find($item->client_id);

        if (! $client) {
            $this->giveUp($item, 'The client no longer exists.');

            return $this->outcome('failed', 0, 0, 'The client no longer exists.');
        }

        $groupIds = array_map('intval', $item->contact_group_ids ?? []);
        $template = $this->template($item);
        $vars = $this->vars($item);

        try {
            $delivery = $this->deliver(
                $client,
                (string) $item->sender,
                $template,
                $this->sms->audience($client->id, $groupIds)->orderBy('id')->cursor(),
                $vars,
                $groupIds,
            );
        } catch (Throwable $e) {
            report($e);
            $this->release($item, 'Unexpected error: '.Str::limit($e->getMessage(), 150));

            return $this->outcome('failed', 0, 0, $e->getMessage());
        }

        // Nothing went out at all (no units, sender not approved…): try again later.
        if ($delivery['sent'] === [] && $delivery['failed'] !== []) {
            $this->release($item, $delivery['failed'][0]['reason']);

            return $this->outcome('failed', 0, count($delivery['failed']), $delivery['failed'][0]['reason']);
        }

        $this->finish($item, $delivery);

        return $this->outcome('sent', count($delivery['sent']), count($delivery['failed']));
    }

    /**
     * Send `$template` personalised to each contact.
     *
     * Messages that are the same for everyone go out as one bulk SMS to `$bulkGroupIds` (when given);
     * personalised ones go out as quick SMS, grouped by identical text (max recipients per message
     * from config). A failed batch is reported per phone; the rest still go out.
     *
     * @param  iterable<int, Contact>  $contacts
     * @param  array<string, mixed>  $vars  rewardAmount, bundleSize, surveyUrl… (same for everybody)
     * @param  list<int>|null  $bulkGroupIds
     * @return array{sent: list<array{phone: string, contact_id: int|null, inbox_id: int|null}>, failed: list<array{phone: string, contact_id: int|null, reason: string}>, inbox_ids: list<int>}
     */
    public function deliver(Client $client, string $sender, string $template, iterable $contacts, array $vars, ?array $bulkGroupIds = null): array
    {
        $personal = $this->isPersonal($template);
        $seen = [];
        $buckets = [];

        foreach ($contacts as $contact) {
            $phone = GhanaPhone::toMobile((string) $contact->phone_number);

            if (! $phone || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $message = $this->render($template, $contact, $client, $vars);
            $key = $personal ? md5($message) : 'all';

            $buckets[$key]['message'] = $message;
            $buckets[$key]['rows'][] = ['phone' => $phone, 'contact_id' => $contact->id];
        }

        $sent = [];
        $failed = [];
        $inboxIds = [];
        $max = max(1, (int) config('sms.quick.max_recipients', 100));

        foreach ($buckets as $bucket) {
            $batches = (! $personal && $bulkGroupIds)
                ? [['rows' => $bucket['rows'], 'bulk' => true]]
                : array_map(fn ($rows) => ['rows' => $rows, 'bulk' => false], array_chunk($bucket['rows'], $max));

            foreach ($batches as $batch) {
                $result = $batch['bulk']
                    ? $this->sms->bulk($client, ['from' => $sender, 'contact_group_ids' => $bulkGroupIds, 'message' => $bucket['message']], 'system')
                    : $this->sms->quick($client, ['from' => $sender, 'to' => array_column($batch['rows'], 'phone'), 'message' => $bucket['message']], 'system');

                if ($result['in_error']) {
                    foreach ($batch['rows'] as $row) {
                        $failed[] = $row + ['reason' => (string) $result['message']];
                    }

                    continue;
                }

                $inboxId = isset($result['data']['id']) ? (int) $result['data']['id'] : null;
                $inboxId && $inboxIds[] = $inboxId;

                foreach ($batch['rows'] as $row) {
                    $sent[] = $row + ['inbox_id' => $inboxId];
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'inbox_ids' => $inboxIds];
    }

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    */

    /**
     * True when the text differs per contact (so it cannot go out as one bulk message).
     */
    public function isPersonal(string $template): bool
    {
        return (bool) preg_match('/\{\s*(first_?name|last_?name|name|full_?name)\s*\}/i', $template);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public function render(string $template, Contact $contact, Client $client, array $vars): string
    {
        $first = trim((string) $contact->first_name);
        $last = trim((string) $contact->last_name);

        // Known placeholders with nothing to show (no reward, no link…) disappear instead of being texted as {braces}.
        $values = $vars + array_fill_keys(['rewardamount', 'rewardname', 'rewardtype', 'bundlesize', 'link', 'ctaurl', 'surveyurl'], '') + [
            'firstname' => $first !== '' ? $first : 'there',
            'lastname' => $last,
            'name' => $first !== '' ? trim($first.' '.$last) : 'there',
            'fullname' => $first !== '' ? trim($first.' '.$last) : 'there',
            'businessname' => (string) ($client->business_name ?: trim($client->first_name.' '.$client->last_name)),
        ];

        $text = preg_replace_callback('/\{\s*([A-Za-z_]+)\s*\}/', function (array $m) use ($values) {
            $key = strtolower(str_replace('_', '', $m[1]));

            return array_key_exists($key, $values) ? (string) $values[$key] : $m[0];
        }, $template) ?? $template;

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text);
    }

    /**
     * Values that are the same for every recipient of a campaign / survey.
     *
     * @return array<string, string>
     */
    public function vars(Model $item): array
    {
        $reward = $item->reward_enabled && is_array($item->reward) ? $item->reward : null;
        $vars = [];

        if ($reward) {
            $type = (string) ($reward['type'] ?? '');
            $size = (string) ($reward['size'] ?? $reward['bundle_label'] ?? '');
            $amount = (float) ($reward['amount'] ?? 0);
            $money = 'GHS '.rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

            $vars += [
                'rewardamount' => $type === 'data' && $size !== '' ? $size : $money,
                'rewardname' => (string) ($reward['name'] ?? ''),
                'rewardtype' => $type,
                'bundlesize' => $size,
            ];
        }

        if ($item instanceof Survey) {
            $vars['surveyurl'] = $this->surveyUrl($item);
        }

        if ($item instanceof Campaign) {
            $cta = is_array($item->cta) ? $item->cta : [];
            $vars['link'] = (string) ($cta['url'] ?? $cta['value'] ?? '');
            $vars['ctaurl'] = $vars['link'];
        }

        return $vars;
    }

    public function surveyUrl(Survey $survey): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/').'/surveys/'.$survey->public_token;
    }

    /**
     * The SMS text. Surveys always carry their link, even when the text has no {surveyUrl}.
     */
    protected function template(Model $item): string
    {
        if ($item instanceof Survey) {
            $text = trim((string) ($item->body ?: $item->intro ?: $item->name));

            return preg_match('/\{\s*survey_?url\s*\}/i', $text) ? $text : $text.' {surveyUrl}';
        }

        return (string) $item->body;
    }

    /*
    |--------------------------------------------------------------------------
    | Claim / finish
    |--------------------------------------------------------------------------
    */

    protected function find(string $kind, int $id): Campaign|Survey|null
    {
        return $kind === 'survey' ? Survey::query()->find($id) : Campaign::query()->find($id);
    }

    /**
     * Take the blast (once). False when it already went out, is not due, or another worker has it.
     */
    protected function claim(Model $item): bool
    {
        return (bool) DB::transaction(function () use ($item) {
            $locked = $item::query()->lockForUpdate()->find($item->id);

            if (! $locked) {
                return false;
            }

            $analytics = is_array($locked->analytics) ? $locked->analytics : [];
            $live = in_array($locked->status, ['scheduled', 'active'], true);
            $due = ! $locked->send_at || $locked->send_at->lte(now());
            $busy = ! empty($analytics['blast_claimed_at']) && now()->lt(Carbon::parse($analytics['blast_claimed_at'])->addMinutes(self::CLAIM_MINUTES));

            if (! $live || ! $due || $busy || ! empty($analytics['blasted_at']) || ! empty($analytics['blast_failed_at'])) {
                return false;
            }

            $analytics['blast_claimed_at'] = now()->toDateTimeString();
            $locked->update(['analytics' => $analytics, 'status' => 'active']);

            return true;
        }, 3);
    }

    /**
     * @param  array{sent: list<array<string, mixed>>, failed: list<array<string, mixed>>, inbox_ids: list<int>}  $delivery
     */
    protected function finish(Model $item, array $delivery): void
    {
        $sent = count($delivery['sent']);

        if ($item instanceof Campaign) {
            $this->recordCampaignEvents($item, $delivery);
        }

        DB::transaction(function () use ($item, $delivery, $sent) {
            $locked = $item::query()->lockForUpdate()->find($item->id);
            $analytics = is_array($locked->analytics) ? $locked->analytics : [];

            unset($analytics['blast_claimed_at'], $analytics['blast_error'], $analytics['blast_next_attempt_at']);
            $analytics['blasted_at'] = now()->toDateTimeString();
            $analytics['blast_inbox_ids'] = $delivery['inbox_ids'];

            if ($item instanceof Campaign) {
                $analytics['sent'] = (int) ($analytics['sent'] ?? 0) + $sent;
                $analytics['failed'] = (int) ($analytics['failed'] ?? 0) + count($delivery['failed']);
                $locked->update(['analytics' => $analytics, 'inbox_id' => $delivery['inbox_ids'][0] ?? $locked->inbox_id]);

                return;
            }

            $analytics['blasted'] = (int) ($analytics['blasted'] ?? 0) + $sent;
            $analytics['sent'] = $analytics['blasted'];
            $locked->update(['analytics' => $analytics]);
        }, 3);

        // A reward without a CTA is paid when the SMS goes out.
        if ($item instanceof Campaign && $this->rewards->campaignTrigger($item->fresh()) === 'send') {
            foreach ($delivery['sent'] as $row) {
                $this->rewards->queueFromCampaignEvent($item->fresh(), $row['phone'], 'send');
            }
        }
    }

    /**
     * One `sent` / `failed` event per phone, so the campaign timeline matches what went out.
     *
     * @param  array{sent: list<array<string, mixed>>, failed: list<array<string, mixed>>, inbox_ids: list<int>}  $delivery
     */
    public function recordCampaignEvents(Campaign $campaign, array $delivery, ?int $occasionYear = null): void
    {
        $rows = [];

        foreach ($delivery['sent'] as $row) {
            $rows[] = $this->eventRow($campaign, CampaignEvent::SENT, $row, ['inbox_id' => $row['inbox_id'] ?? null], $occasionYear);
        }

        foreach ($delivery['failed'] as $row) {
            $rows[] = $this->eventRow($campaign, CampaignEvent::FAILED, $row, ['reason' => $row['reason'] ?? null], null);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CampaignEvent::query()->insertOrIgnore($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function eventRow(Campaign $campaign, string $event, array $row, array $meta, ?int $occasionYear): array
    {
        return [
            'campaign_id' => $campaign->id,
            'contact_id' => $row['contact_id'] ?? null,
            'phone' => $row['phone'],
            'event' => $event,
            'occasion_year' => $occasionYear,
            'meta' => json_encode($meta),
            'created_at' => now(),
        ];
    }

    /**
     * Let the next scheduler pass try again (e.g. not enough SMS units yet), up to MAX_ATTEMPTS.
     */
    protected function release(Model $item, string $reason): void
    {
        DB::transaction(function () use ($item, $reason) {
            $locked = $item::query()->lockForUpdate()->find($item->id);
            $analytics = is_array($locked->analytics) ? $locked->analytics : [];
            $attempts = (int) ($analytics['blast_attempts'] ?? 0) + 1;

            unset($analytics['blast_claimed_at']);
            $analytics['blast_attempts'] = $attempts;
            $analytics['blast_error'] = Str::limit($reason, 250, '');

            if ($attempts >= self::MAX_ATTEMPTS) {
                $analytics['blast_failed_at'] = now()->toDateTimeString();
            } else {
                $analytics['blast_next_attempt_at'] = now()->addMinutes(10 * $attempts)->toDateTimeString();
            }

            $locked->update(['analytics' => $analytics]);
        }, 3);
    }

    protected function giveUp(Model $item, string $reason): void
    {
        $analytics = is_array($item->analytics) ? $item->analytics : [];
        unset($analytics['blast_claimed_at']);
        $analytics['blast_error'] = $reason;
        $analytics['blast_failed_at'] = now()->toDateTimeString();
        $item->update(['analytics' => $analytics]);
    }

    /**
     * @return array{status: string, sent: int, failed: int, message: string|null}
     */
    protected function outcome(string $status, int $sent = 0, int $failed = 0, ?string $message = null): array
    {
        return ['status' => $status, 'sent' => $sent, 'failed' => $failed, 'message' => $message];
    }
}
