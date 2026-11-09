<?php

namespace App\Services\Sms;

use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\Sent;
use Illuminate\Support\Facades\DB;

/**
 * Counters, refunds and completion for an inbox while its jobs run.
 */
class SmsInboxProgress
{
    public function __construct(protected SmsUnitService $units) {}

    public function recordSubmission(Inbox $inbox, int $submitted, int $failed): void
    {
        if ($submitted === 0 && $failed === 0) {
            return;
        }

        DB::table('inboxes')->where('id', $inbox->id)->incrementEach([
            'submitted_count' => $submitted,
            'failed_count' => $failed,
        ], ['updated_at' => now()]);

        if ($failed > 0 && $inbox->client_id) {
            $this->units->refund($inbox->client_id, $failed * (int) $inbox->parts, $inbox->id, 'Refund for messages that could not be sent');
        }
    }

    public function batchDone(int $inboxId): void
    {
        DB::table('inboxes')->where('id', $inboxId)->increment('batches_done');

        $inbox = Inbox::query()->find($inboxId);

        if ($inbox && $inbox->batches_done >= $inbox->batches_total) {
            $this->finish($inboxId);
        }
    }

    public function finish(int $inboxId): void
    {
        // Bulk (and any path that skipped it) never set inbox.message_id during send;
        // copy the first aggregator id from sents so list/show/resend have a reference.
        $messageId = Sent::query()
            ->where('inbox_id', $inboxId)
            ->whereNotNull('message_id')
            ->value('message_id');

        $updates = [
            'status' => DB::raw("CASE WHEN submitted_count > 0 THEN 'completed' ELSE 'failed' END"),
            'completed_at' => now(),
            'updated_at' => now(),
        ];

        if ($messageId) {
            $updates['message_id'] = $messageId;
        }

        Inbox::query()
            ->whereKey($inboxId)
            ->whereIn('status', [Inbox::STATUS_QUEUED, Inbox::STATUS_PROCESSING])
            ->update($updates);

        Outbox::query()->where('inbox_id', $inboxId)->delete();
    }

    /**
     * After a job crash: everything not yet accepted by the aggregator is failed and refunded.
     */
    public function failUnsent(?Inbox $inbox, string $error = 'Not sent'): void
    {
        if (! $inbox) {
            return;
        }

        $queued = DB::table('sents')
            ->where('inbox_id', $inbox->id)
            ->where('status', Sent::QUEUED)
            ->update([
                'status' => Sent::FAILED,
                'error' => $error,
                'units_refunded' => true,
                'updated_at' => now(),
            ]);

        $existing = DB::table('sents')->where('inbox_id', $inbox->id)->count();
        $missing = max(0, (int) $inbox->recipients_count - $existing);

        $this->recordSubmission($inbox, 0, $queued + $missing);
        $this->finish($inbox->id);
    }
}
