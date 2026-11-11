<?php

namespace App\Services\Engagement;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Client;
use App\Models\Contact;
use App\Services\RewardDisbursementService;
use App\Services\Sms\SmsService;
use App\Support\GhanaPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Occasion campaigns (birthday, anniversary, holiday, custom).
 *
 * - birthday + repeat_yearly: each day, texts only the contacts whose date_of_birth (month-day)
 *   matches today shifted by the trigger offset.
 * - other occasions: on their custom_date (every year when repeat_yearly) texts the whole group.
 *
 * One SMS per campaign + phone + year: the `sent` row is claimed first (unique key on
 * campaign_id, occasion_year, phone), so re-running the command never texts anyone twice.
 */
class OccasionService
{
    public const DEFAULT_SEND_TIME = '09:00';

    public function __construct(
        protected EngagementBlastService $blasts,
        protected RewardDisbursementService $rewards,
        protected SmsService $sms,
    ) {}

    /**
     * Run every occasion campaign that is due now.
     *
     * @return array{campaigns: int, sent: int, skipped: int, failed: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $totals = ['campaigns' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        Campaign::query()
            ->where('type', Campaign::TYPE_OCCASION)
            ->whereIn('status', [Campaign::STATUS_SCHEDULED, Campaign::STATUS_ACTIVE])
            ->orderBy('id')
            ->chunkById(100, function ($campaigns) use ($now, &$totals) {
                foreach ($campaigns as $campaign) {
                    try {
                        $result = $this->runCampaign($campaign, $now);
                    } catch (Throwable $e) {
                        report($e);

                        continue;
                    }

                    if ($result === null) {
                        continue;
                    }

                    $totals['campaigns']++;
                    $totals['sent'] += $result['sent'];
                    $totals['skipped'] += $result['skipped'];
                    $totals['failed'] += $result['failed'];
                }
            });

        return $totals;
    }

    /**
     * @return array{sent: int, skipped: int, failed: int}|null null when nothing is due for this campaign
     */
    public function runCampaign(Campaign $campaign, Carbon $now): ?array
    {
        $today = $now->copy()->startOfDay();

        if ($now->lt($today->copy()->setTimeFromTimeString($this->sendTime($campaign)))) {
            return null;
        }

        $eventDate = $today->copy()->subDays($this->shiftDays($campaign));
        $year = $this->claimYear($campaign, $eventDate);

        $client = Client::query()->find($campaign->client_id);

        if (! $client) {
            return null;
        }

        $groupIds = array_map('intval', $campaign->contact_group_ids ?? []);
        $contacts = $this->sms->audience($client->id, $groupIds);

        if ($campaign->occasion_type === 'birthday') {
            if (! $campaign->repeat_yearly) {
                return null;
            }

            $this->whereBirthdayOn($contacts, $eventDate);
        } elseif (! $this->dateOccasionDue($campaign, $eventDate)) {
            return null;
        }

        $contacts = $contacts->orderBy('id')->get()->unique('phone_number');

        if ($contacts->isEmpty()) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // Claim each phone for this year. Only the ones we claimed are texted.
        $mine = [];
        $skipped = 0;

        foreach ($contacts as $contact) {
            $phone = GhanaPhone::toMobile((string) $contact->phone_number);

            if (! $phone) {
                continue;
            }

            $claimed = DB::table('campaign_events')->insertOrIgnore([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'phone' => $phone,
                'event' => CampaignEvent::SENT,
                'occasion_year' => $year,
                'meta' => json_encode(['occasion' => $campaign->occasion_type, 'event_date' => $eventDate->toDateString()]),
                'created_at' => now(),
            ]);

            $claimed ? $mine[] = $contact : $skipped++;
        }

        if ($mine === []) {
            return ['sent' => 0, 'skipped' => $skipped, 'failed' => 0];
        }

        if ($campaign->status === Campaign::STATUS_SCHEDULED) {
            $campaign->update(['status' => Campaign::STATUS_ACTIVE]);
        }

        $delivery = $this->blasts->deliver(
            $client,
            (string) $campaign->sender,
            (string) $campaign->body,
            $mine,
            $this->blasts->vars($campaign),
        );

        // Not sent (no units, sender problem…): give the claim back so the next run tries again today.
        if ($delivery['failed'] !== []) {
            DB::table('campaign_events')
                ->where('campaign_id', $campaign->id)
                ->where('occasion_year', $year)
                ->whereIn('phone', array_column($delivery['failed'], 'phone'))
                ->delete();
        }

        $this->afterDelivery($campaign, $delivery, $year);

        return ['sent' => count($delivery['sent']), 'skipped' => $skipped, 'failed' => count($delivery['failed'])];
    }

    /**
     * @param  array{sent: list<array<string, mixed>>, failed: list<array<string, mixed>>, inbox_ids: list<int>}  $delivery
     */
    protected function afterDelivery(Campaign $campaign, array $delivery, int $year): void
    {
        $sent = count($delivery['sent']);

        DB::transaction(function () use ($campaign, $delivery, $sent) {
            $locked = Campaign::query()->lockForUpdate()->find($campaign->id);
            $analytics = is_array($locked->analytics) ? $locked->analytics : [];

            $analytics['sent'] = (int) ($analytics['sent'] ?? 0) + $sent;
            $analytics['last_occasion_run_at'] = now()->toDateTimeString();

            if ($delivery['failed'] !== []) {
                $analytics['blast_error'] = Str::limit((string) $delivery['failed'][0]['reason'], 250, '');
            } else {
                unset($analytics['blast_error']);
            }

            $locked->update(['analytics' => $analytics, 'inbox_id' => $delivery['inbox_ids'][0] ?? $locked->inbox_id]);
        }, 3);

        // Tie each claimed row to the message that went out.
        foreach ($delivery['sent'] as $row) {
            if (! empty($row['inbox_id'])) {
                CampaignEvent::query()
                    ->where('campaign_id', $campaign->id)
                    ->where('occasion_year', $year)
                    ->where('phone', $row['phone'])
                    ->update(['meta' => ['inbox_id' => $row['inbox_id']]]);
            }
        }

        // A reward without a CTA is paid when the SMS goes out; one per phone per year.
        if ($this->rewards->campaignTrigger($campaign) === 'send') {
            foreach ($delivery['sent'] as $row) {
                $this->rewards->queueFromCampaignEvent($campaign->fresh(), $row['phone'], 'send', (string) $year);
            }
        }
    }

    /**
     * Whole days the SMS goes out after the occasion (negative = before it).
     */
    public function shiftDays(Campaign $campaign): int
    {
        $custom = max(0, (int) $campaign->custom_offset_days);

        return match ($campaign->trigger_offset) {
            'one_day_before' => -1,
            'three_days_before' => -3,
            'one_week_before' => -7,
            'before', 'custom' => -($custom > 0 ? $custom : 1),
            'after' => $custom > 0 ? $custom : 1,
            default => 0, // on, same_day, null
        };
    }

    /**
     * HH:MM the SMS may go out from on the day.
     */
    public function sendTime(Campaign $campaign): string
    {
        $time = (string) $campaign->send_time;

        return preg_match('/^\d{1,2}:\d{2}/', $time) ? substr($time, 0, 5) : self::DEFAULT_SEND_TIME;
    }

    /**
     * Key for "already sent": the year of the occasion for yearly campaigns, 0 for one-time ones
     * (a one-time campaign never texts the same phone twice).
     */
    protected function claimYear(Campaign $campaign, Carbon $eventDate): int
    {
        return $campaign->repeat_yearly ? (int) $eventDate->year : 0;
    }

    /**
     * Contacts whose date_of_birth (stored Y-m-d) falls on this month and day. Someone born
     * on 29 Feb is greeted on 28 Feb in years that have no 29 Feb.
     *
     * @param  Builder<Contact>  $query
     */
    protected function whereBirthdayOn(Builder $query, Carbon $date): void
    {
        $days = [$date->format('m-d')];

        if ($date->format('m-d') === '02-28' && ! $date->isLeapYear()) {
            $days[] = '02-29';
        }

        $query->whereNotNull('date_of_birth')
            ->whereIn(DB::raw('SUBSTR(date_of_birth, 6, 5)'), $days);
    }

    /**
     * Holiday / anniversary / custom occasions fire on custom_date (every year when it repeats).
     */
    protected function dateOccasionDue(Campaign $campaign, Carbon $eventDate): bool
    {
        if (! $campaign->custom_date) {
            return false;
        }

        $date = $campaign->custom_date;

        return $campaign->repeat_yearly
            ? $date->format('m-d') === $eventDate->format('m-d') && $eventDate->year >= $date->year
            : $date->isSameDay($eventDate);
    }
}
