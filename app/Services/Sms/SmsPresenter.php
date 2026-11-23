<?php

namespace App\Services\Sms;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Inbox;
use App\Models\Sent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Client output never includes aggregator names, ids or raw errors.
 * Admin output adds them on top of the client shape.
 */
class SmsPresenter
{
    /** @var array<int, ContactGroup> */
    protected array $groupsById = [];

    /** @var array<int, list<array{code: string, label: string, count: int}>> */
    protected array $failureReasonsByInbox = [];

    /** @var array<int, int> */
    protected array $unretriedFailedByInbox = [];

    /**
     * Prefetch failure reasons and unretried failed counts for a page of inboxes (avoids N+1).
     *
     * @param  iterable<Inbox>  $inboxes
     */
    public function warmFailureReasons(iterable $inboxes): void
    {
        $ids = [];

        foreach ($inboxes as $inbox) {
            if (! isset($this->failureReasonsByInbox[$inbox->id])) {
                $ids[] = (int) $inbox->id;
                $this->failureReasonsByInbox[$inbox->id] = [];
                $this->unretriedFailedByInbox[$inbox->id] = 0;
            }
        }

        if ($ids === []) {
            return;
        }

        $rows = Sent::query()
            ->whereIn('inbox_id', $ids)
            ->where('status', Sent::FAILED)
            ->select(
                'inbox_id',
                DB::raw('UPPER(COALESCE(dlr_status, "")) as dlr'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN retry_inbox_id IS NULL THEN 1 ELSE 0 END) as unretried'),
            )
            ->groupBy('inbox_id', 'dlr')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $inboxId = (int) $row->inbox_id;
            $code = $this->failureCodeFromDlr((string) $row->dlr);
            $grouped[$inboxId][$code] = ($grouped[$inboxId][$code] ?? 0) + (int) $row->total;
            $this->unretriedFailedByInbox[$inboxId] = ($this->unretriedFailedByInbox[$inboxId] ?? 0) + (int) $row->unretried;
        }

        foreach ($grouped as $inboxId => $counts) {
            $reasons = [];

            foreach ($counts as $code => $count) {
                $reasons[] = [
                    'code' => $code,
                    'label' => $this->failureLabel($code),
                    'count' => $count,
                ];
            }

            $this->failureReasonsByInbox[$inboxId] = $reasons;
        }
    }

    /**
     * Prefetch contact groups for a page of inboxes (avoids N+1 on index).
     *
     * @param  iterable<Inbox>  $inboxes
     */
    public function warmGroups(iterable $inboxes): void
    {
        $ids = [];

        foreach ($inboxes as $inbox) {
            foreach ($inbox->contact_group_ids ?? [] as $id) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return;
        }

        $this->groupsById += ContactGroup::withTrashed()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'client_id', 'name', 'description', 'count_of_contacts', 'deleted_at'])
            ->keyBy('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forClient(Inbox $inbox): array
    {
        $recipients = (int) $inbox->recipients_count;
        $sent = (int) $inbox->submitted_count;
        $delivered = (int) $inbox->delivered_count;
        $failed = (int) $inbox->failed_count;
        // Not in a final state yet (scheduled, sending, or awaiting delivery confirmation).
        $pending = $inbox->status === Inbox::STATUS_CANCELLED
            ? 0
            : max(0, $recipients - $delivered - $failed);
        $deliveryRate = $recipients > 0 ? round(($delivered / $recipients) * 100, 2) : 0.0;
        $failureReasons = $this->failureReasonsForInbox($inbox);
        $failureSummary = $failureReasons === []
            ? null
            : implode(', ', array_map(fn (array $r) => "{$r['count']} ".str_replace('_', ' ', $r['code']), $failureReasons));

        return [
            'id' => $inbox->id,
            'type' => $inbox->type,
            'mode' => $inbox->mode ?? 'send',
            'parent_id' => $inbox->parent_id,
            'request_type' => $inbox->request_type,
            'from' => $inbox->from,
            'to' => $inbox->isQuick() ? $inbox->recipients() : null,
            'contact_groups' => $this->presentGroups($inbox->contact_group_ids),
            'message' => $inbox->message,
            'message_id' => $inbox->message_id,
            'parts' => (int) $inbox->parts,
            'recipients' => $recipients,
            'units_used' => (int) $inbox->units_used,
            'units_refunded' => (int) $inbox->units_refunded,
            'units_net' => max(0, (int) $inbox->units_used - (int) $inbox->units_refunded),
            'status' => $inbox->status,
            'delivery' => [
                'sent' => $sent,           // accepted by network
                'delivered' => $delivered, // handset confirmed
                'failed' => $failed,       // rejected or undelivered/expired
                'pending' => $pending,     // still sending or awaiting handset confirmation
                'delivery_rate' => $deliveryRate,
            ],
            'failure_reasons' => $failureReasons,
            'failure_summary' => $failureSummary,
            'scheduled_at' => optional($inbox->scheduled_at)->toDateTimeString(),
            'created_at' => optional($inbox->created_at)->toDateTimeString(),
            'completed_at' => optional($inbox->completed_at)->toDateTimeString(),
        ];
    }

    /**
     * Detailed single-SMS view for `GET sms/{inbox}` (adds available follow-up actions).
     *
     * @return array<string, mixed>
     */
    public function forClientShow(Inbox $inbox): array
    {
        $this->warmGroups([$inbox]);
        $this->warmFailureReasons([$inbox]);

        $finished = in_array($inbox->status, [Inbox::STATUS_COMPLETED, Inbox::STATUS_FAILED], true);
        $unretriedFailed = $this->unretriedFailedByInbox[$inbox->id] ?? 0;

        return $this->forClient($inbox) + [
            'actions' => [
                'can_reblast' => $finished,
                'can_reblast_failed' => $finished && $unretriedFailed > 0,
                'unretried_failed_count' => $unretriedFailed,
                'can_resend' => $finished && $inbox->isQuick(),
                'can_cancel' => $inbox->status === Inbox::STATUS_SCHEDULED,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(Inbox $inbox): array
    {
        return $this->forClient($inbox) + [
            'client_id' => $inbox->client_id,
            'admin_id' => $inbox->admin_id,
            'mode' => $inbox->mode,
            'parent_id' => $inbox->parent_id,
            'units_before' => $inbox->previous_units !== null ? (int) $inbox->previous_units : null,
            'aggregator' => $inbox->aggregator,
            'is_global' => (bool) $inbox->is_global,
            'batches' => [
                'total' => (int) $inbox->batches_total,
                'done' => (int) $inbox->batches_done,
            ],
        ];
    }

    /**
     * Map phone → contact groups that were part of this blast (bulk / reblast from groups).
     *
     * @param  Collection<int, Sent>  $sents
     * @return array<string, list<array<string, mixed>>>
     */
    public function recipientGroupsForInbox(Inbox $inbox, Collection $sents): array
    {
        $groupIds = array_values(array_map('intval', $inbox->contact_group_ids ?? []));
        $phones = $sents->pluck('to')->filter()->map(fn ($to) => (string) $to)->unique()->values()->all();

        if ($groupIds === [] || $phones === [] || ! $inbox->client_id) {
            return [];
        }

        $this->warmGroups([$inbox]);

        $contacts = Contact::query()
            ->where('client_id', $inbox->client_id)
            ->whereIn('contact_group_id', $groupIds)
            ->whereIn('phone_number', $phones)
            ->get(['phone_number', 'contact_group_id', 'first_name', 'last_name']);

        $map = [];

        foreach ($contacts as $contact) {
            $group = $this->groupsById[(int) $contact->contact_group_id] ?? null;
            $phone = (string) $contact->phone_number;

            $map[$phone][] = [
                'id' => (int) $contact->contact_group_id,
                'name' => $group?->name,
                'contact' => [
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                ],
            ];
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $contactGroups
     * @return array<string, mixed>
     */
    public function recipientForClient(Sent $sent, ?Inbox $inbox = null, array $contactGroups = []): array
    {
        return [
            'id' => $sent->id,
            'inbox_id' => $sent->inbox_id,
            'to' => $sent->to,
            'from' => $sent->from,
            'message' => $sent->message,
            'type' => $sent->type,
            'request_type' => $sent->request_type,
            'message_id' => $sent->message_id,
            'status' => $sent->status,
            'failure_reason' => $this->failureReason($sent),
            'units_used' => (int) $sent->units_used,
            'units_refunded' => (bool) $sent->units_refunded,
            'contact_groups' => $contactGroups,
            'sent_at' => optional($sent->submitted_at)->toDateTimeString(),
            'delivered_at' => optional($sent->delivered_at)->toDateTimeString(),
            'created_at' => optional($sent->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $contactGroups
     * @return array<string, mixed>
     */
    public function recipientForAdmin(Sent $sent, ?Inbox $inbox = null, array $contactGroups = []): array
    {
        return $this->recipientForClient($sent, $inbox, $contactGroups) + [
            'client_id' => $sent->client_id,
            'mode' => $inbox?->mode,
            'parent_id' => $inbox?->parent_id,
            'aggregator' => $sent->aggregator,
            'sequence_no' => $sent->sequence_no,
            'job_id' => $sent->job_id,
            'dlr_status' => $sent->dlr_status,
            'dlr_attempts' => (int) $sent->dlr_attempts,
            'error' => $sent->error,
        ];
    }

    /**
     * @param  list<int|string>|null  $ids
     * @return list<array<string, mixed>>
     */
    protected function presentGroups(?array $ids): array
    {
        if ($ids === null || $ids === []) {
            return [];
        }

        $missing = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if (! isset($this->groupsById[$id])) {
                $missing[$id] = true;
            }
        }

        if ($missing !== []) {
            $this->groupsById += ContactGroup::withTrashed()
                ->whereIn('id', array_keys($missing))
                ->get(['id', 'client_id', 'name', 'description', 'count_of_contacts', 'deleted_at'])
                ->keyBy('id')
                ->all();
        }

        $out = [];

        foreach ($ids as $id) {
            $group = $this->groupsById[(int) $id] ?? null;

            $out[] = $group ? [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'count_of_contacts' => (int) $group->count_of_contacts,
                'deleted' => $group->trashed(),
            ] : [
                'id' => (int) $id,
                'name' => null,
                'description' => null,
                'count_of_contacts' => null,
                'deleted' => true,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{code: string, label: string, count: int}>
     */
    protected function failureReasonsForInbox(Inbox $inbox): array
    {
        if ((int) $inbox->failed_count === 0 && ! isset($this->failureReasonsByInbox[$inbox->id])) {
            return [];
        }

        if (! isset($this->failureReasonsByInbox[$inbox->id])) {
            $this->warmFailureReasons([$inbox]);
        }

        $reasons = $this->failureReasonsByInbox[$inbox->id] ?? [];

        if ($reasons === [] && (int) $inbox->failed_count > 0) {
            return [[
                'code' => 'not_sent',
                'label' => $this->failureLabel('not_sent'),
                'count' => (int) $inbox->failed_count,
            ]];
        }

        return $reasons;
    }

    protected function failureCodeFromDlr(string $dlrStatus): string
    {
        return match (strtoupper(trim($dlrStatus))) {
            'UNDELIVERED' => 'undelivered',
            'REJECTED' => 'rejected',
            'EXPIRED' => 'expired',
            default => 'not_sent',
        };
    }

    protected function failureLabel(string $code): string
    {
        return match ($code) {
            'undelivered' => 'Undelivered — recipient phone unreachable, powered off, or out of coverage.',
            'rejected' => 'Rejected by the mobile network or invalid/blocked recipient number.',
            'expired' => 'Expired before the recipient handset came online to receive the SMS.',
            default => 'Could not be submitted to the network (units refunded).',
        };
    }

    protected function failureReason(Sent $sent): ?string
    {
        if ($sent->status !== Sent::FAILED) {
            return null;
        }

        return $this->failureCodeFromDlr((string) $sent->dlr_status);
    }
}
