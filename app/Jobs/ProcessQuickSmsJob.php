<?php

namespace App\Jobs;

use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\Sent;
use App\Services\Sms\KairosClient;
use App\Services\Sms\SentWriter;
use App\Services\Sms\SmsInboxProgress;
use App\Support\SmsThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a quick message (up to quick.max_recipients numbers) in one aggregator call.
 * Runs on sms-blast-quick (system) or sms-api (API clients), never on the bulk workers.
 */
class ProcessQuickSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $inboxId) {}

    public function handle(KairosClient $kairos, SentWriter $writer, SmsInboxProgress $progress): void
    {
        if (! Outbox::claim($this->inboxId)) {
            return;
        }

        $inbox = Inbox::query()->find($this->inboxId);

        if (! $inbox || $inbox->status !== Inbox::STATUS_QUEUED) {
            Outbox::query()->where('inbox_id', $this->inboxId)->delete();

            return;
        }

        $inbox->update(['status' => Inbox::STATUS_PROCESSING]);

        $recipients = $inbox->recipients();

        $writer->insertQueued($recipients, [
            'inbox_id' => $inbox->id,
            'client_id' => $inbox->client_id,
            'admin_id' => $inbox->admin_id,
            'request_type' => $inbox->request_type,
            'type' => $inbox->type,
            'from' => $inbox->from,
            'message' => $inbox->message,
            'units_used' => $inbox->parts,
            'aggregator' => $inbox->aggregator,
            'is_global' => $inbox->is_global,
        ]);

        [$submitted, $failed] = $inbox->mode === 'resend'
            ? $this->resend($inbox, $kairos, $writer)
            : $this->send($inbox, $recipients, $kairos, $writer);

        $progress->recordSubmission($inbox, $submitted, $failed);

        $firstMessageId = Sent::query()->where('inbox_id', $inbox->id)->whereNotNull('message_id')->value('message_id');

        if ($firstMessageId) {
            $inbox->update(['message_id' => $firstMessageId]);
        }

        $progress->finish($inbox->id);
    }

    public function failed(?Throwable $e): void
    {
        app(SmsInboxProgress::class)->failUnsent(Inbox::query()->find($this->inboxId));
    }

    /**
     * @param  list<string>  $recipients
     * @return array{0: int, 1: int}
     */
    protected function send(Inbox $inbox, array $recipients, KairosClient $kairos, SentWriter $writer): array
    {
        SmsThrottle::await('quick', count($recipients));

        $response = count($recipients) === 1
            ? $kairos->quick($recipients[0], $inbox->from, $inbox->message, $inbox->id)
            : $kairos->bulk(array_map(fn (string $to) => [
                'to' => $to,
                'from' => $inbox->from,
                'message' => $inbox->message,
            ], $recipients), $inbox->id);

        return $writer->apply($inbox->id, $recipients, $response);
    }

    /**
     * Prefer Kairos's resend endpoint (same recipients, new text). Their API currently
     * 404s with "Delivery receipt…doesn't exist" even for fresh transactionIds, so we
     * fall back to a normal quick send when that happens.
     *
     * @return array{0: int, 1: int}
     */
    protected function resend(Inbox $inbox, KairosClient $kairos, SentWriter $writer): array
    {
        $parentFrom = $inbox->parent_id
            ? Inbox::query()->whereKey($inbox->parent_id)->value('from')
            : null;
        $senderChanged = $parentFrom !== null && strcasecmp((string) $parentFrom, (string) $inbox->from) !== 0;

        $sources = $senderChanged
            ? collect()
            : Sent::query()
                ->where('inbox_id', $inbox->parent_id)
                ->when($inbox->client_id, fn ($q) => $q->where('client_id', $inbox->client_id))
                ->get(['to', 'message_id'])
                ->keyBy(fn (Sent $s) => (string) $s->to);

        $submitted = 0;
        $failed = 0;

        foreach ($inbox->recipients() as $to) {
            SmsThrottle::await('quick', 1);

            $source = $sources->get($to);
            $messageId = $source?->message_id ? (string) $source->message_id : '';

            // Kairos resend reuses the original sender ID, so when the client chose a new `from`,
            // send via quick() directly so the new approved sender ID is honored.
            $response = (! $senderChanged && $messageId !== '')
                ? $kairos->resend($messageId, $inbox->message, $inbox->id)
                : null;

            // Kairos docs say transactionId; live API often 404s or older rows have no message_id.
            // Fall back to a normal quick send so the resend always goes through.
            if (! $response || ! $response->ok) {
                $response = $kairos->quick($to, $inbox->from, $inbox->message, $inbox->id);
            }

            [$ok, $bad] = $writer->apply($inbox->id, [$to], $response);

            $submitted += $ok;
            $failed += $bad;
        }

        return [$submitted, $failed];
    }
}
