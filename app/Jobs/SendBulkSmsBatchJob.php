<?php

namespace App\Jobs;

use App\Models\Inbox;
use App\Models\Sent;
use App\Services\Sms\KairosClient;
use App\Services\Sms\SentWriter;
use App\Services\Sms\SmsInboxProgress;
use App\Support\LongQueue;
use App\Support\SmsThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one slice (id range) of a bulk inbox in aggregator-sized requests,
 * holding to the bulk TPS budget shared by every bulk worker.
 */
class SendBulkSmsBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $inboxId,
        public int $fromId,
        public int $toId,
    ) {
        $this->onConnection(LongQueue::connection());
        $this->onQueue('sms-blast-bulk');
    }

    public function handle(KairosClient $kairos, SentWriter $writer, SmsInboxProgress $progress): void
    {
        $inbox = Inbox::query()->find($this->inboxId);

        if (! $inbox) {
            return;
        }

        $size = max(1, (int) config('sms.bulk.request_size', 100));

        foreach (array_chunk($this->queuedRecipients(), $size) as $chunk) {
            SmsThrottle::await('bulk', count($chunk));

            $response = $kairos->bulk(array_map(fn (string $to) => [
                'to' => $to,
                'from' => $inbox->from,
                'message' => $inbox->message,
            ], $chunk), $inbox->id);

            [$submitted, $failed] = $writer->apply($inbox->id, $chunk, $response);
            $progress->recordSubmission($inbox, $submitted, $failed);

            // Surface a reference message_id on the inbox as soon as the first batch succeeds.
            if ($submitted > 0 && ! $inbox->message_id) {
                $messageId = Sent::query()
                    ->where('inbox_id', $inbox->id)
                    ->whereNotNull('message_id')
                    ->value('message_id');

                if ($messageId) {
                    $inbox->update(['message_id' => $messageId]);
                }
            }
        }

        $progress->batchDone($inbox->id);
    }

    public function failed(?Throwable $e): void
    {
        $inbox = Inbox::query()->find($this->inboxId);

        if (! $inbox) {
            return;
        }

        $left = $this->queuedRecipients();

        if ($left !== []) {
            app(SentWriter::class)->markFailed($inbox->id, $left, 'Not sent');
            app(SmsInboxProgress::class)->recordSubmission($inbox, 0, count($left));
        }

        app(SmsInboxProgress::class)->batchDone($inbox->id);
    }

    /**
     * @return list<string>
     */
    protected function queuedRecipients(): array
    {
        return DB::table('sents')
            ->where('inbox_id', $this->inboxId)
            ->whereBetween('id', [$this->fromId, $this->toId])
            ->where('status', Sent::QUEUED)
            ->pluck('to')
            ->map(fn ($to) => (string) $to)
            ->all();
    }
}
