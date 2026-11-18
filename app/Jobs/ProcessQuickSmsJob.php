<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\Sent;
use App\Services\Sms\KairosClient;
use App\Services\Sms\SentWriter;
use App\Services\Sms\SmsInboxProgress;
use App\Services\Sms\SmsUnitService;
use App\Support\ContactPlaceholders;
use App\Support\SmsThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

    public function handle(KairosClient $kairos, SentWriter $writer, SmsInboxProgress $progress, SmsUnitService $units): void
    {
        if (! Outbox::claim($this->inboxId)) {
            return;
        }

        $inbox = Inbox::query()->find($this->inboxId);

        if (! $inbox) {
            Outbox::query()->where('inbox_id', $this->inboxId)->delete();

            return;
        }

        $inbox->update(['status' => Inbox::STATUS_PROCESSING]);

        $recipients = $inbox->recipients();
        $messagesByPhone = $this->resolveMessages($inbox, $recipients);

        $skipped = count($recipients) - count($messagesByPhone);
        $recipients = array_keys($messagesByPhone);

        if ($skipped > 0 && $inbox->client_id) {
            $units->refund($inbox->client_id, $skipped * (int) $inbox->parts, $inbox->id, 'Refund for contacts missing required placeholder fields');
            $inbox->update(['recipients_count' => count($recipients)]);
        }

        if ($recipients === []) {
            $progress->finish($inbox->id);

            return;
        }

        $now = now();
        $rows = [];

        foreach ($messagesByPhone as $to => $message) {
            $rows[] = [
                'inbox_id' => $inbox->id,
                'client_id' => $inbox->client_id,
                'admin_id' => $inbox->admin_id,
                'request_type' => $inbox->request_type,
                'type' => $inbox->type,
                'from' => $inbox->from,
                'to' => $to,
                'message' => $message,
                'units_used' => $inbox->parts,
                'aggregator' => $inbox->aggregator,
                'is_global' => $inbox->is_global,
                'status' => Sent::QUEUED,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $batch) {
            DB::table('sents')->insertOrIgnore($batch);
        }

        [$submitted, $failed] = $inbox->mode === 'resend'
            ? $this->resend($inbox, $messagesByPhone, $kairos, $writer)
            : $this->send($inbox, $messagesByPhone, $kairos, $writer);

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
     * Resolve the message text per recipient. When contact placeholders are present,
     * only contacts that have all required fields are included.
     *
     * @param  list<string>  $recipients
     * @return array<string, string>
     */
    protected function resolveMessages(Inbox $inbox, array $recipients): array
    {
        if (! $inbox->client_id || ! ContactPlaceholders::hasContactPlaceholders($inbox->message)) {
            $out = [];

            foreach ($recipients as $to) {
                $out[$to] = (string) $inbox->message;
            }

            return $out;
        }

        $client = Client::query()->find($inbox->client_id);
        $contacts = ContactPlaceholders::applyRequiredFilter(
            Contact::query()
                ->visible()
                ->where('client_id', $inbox->client_id)
                ->whereIn('phone_number', $recipients)
                ->where(fn ($q) => $q->whereNull('dnd_status')->orWhere('dnd_status', '!=', 'active')),
            $inbox->message,
        )
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Contact $c) => (string) $c->phone_number);

        $out = [];

        foreach ($recipients as $to) {
            $contact = $contacts->get($to);

            if (! $contact) {
                continue;
            }

            $out[$to] = ContactPlaceholders::render($inbox->message, $contact, $client);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $messagesByPhone
     * @return array{0: int, 1: int}
     */
    protected function send(Inbox $inbox, array $messagesByPhone, KairosClient $kairos, SentWriter $writer): array
    {
        $recipients = array_keys($messagesByPhone);
        SmsThrottle::await('quick', count($recipients));

        $response = count($recipients) === 1
            ? $kairos->quick($recipients[0], $inbox->from, $messagesByPhone[$recipients[0]], $inbox->id)
            : $kairos->bulk(array_map(fn (string $to) => [
                'to' => $to,
                'from' => $inbox->from,
                'message' => $messagesByPhone[$to],
            ], $recipients), $inbox->id);

        return $writer->apply($inbox->id, $recipients, $response);
    }

    /**
     * Prefer Kairos's resend endpoint (same recipients, new text). Their API currently
     * 404s with "Delivery receipt…doesn't exist" even for fresh transactionIds, so we
     * fall back to a normal quick send when that happens.
     *
     * @param  array<string, string>  $messagesByPhone
     * @return array{0: int, 1: int}
     */
    protected function resend(Inbox $inbox, array $messagesByPhone, KairosClient $kairos, SentWriter $writer): array
    {
        $sources = Sent::query()
            ->where('inbox_id', $inbox->parent_id)
            ->whereNotNull('message_id')
            ->get(['to', 'message_id']);

        $submitted = 0;
        $failed = 0;

        foreach ($sources as $source) {
            $to = (string) $source->to;

            if (! array_key_exists($to, $messagesByPhone)) {
                continue;
            }

            SmsThrottle::await('quick', 1);

            $text = $messagesByPhone[$to];
            $response = $kairos->resend((string) $source->message_id, $text, $inbox->id);

            // Kairos docs say transactionId; live API often 404s. Retry once as a new quick send.
            if (! $response->ok) {
                $response = $kairos->quick($to, $inbox->from, $text, $inbox->id);
            }

            [$ok, $bad] = $writer->apply($inbox->id, [$to], $response);

            $submitted += $ok;
            $failed += $bad;
        }

        $known = $sources->pluck('to')->map(fn ($to) => (string) $to)->all();
        $orphans = array_values(array_diff(array_keys($messagesByPhone), $known));

        if ($orphans !== []) {
            $writer->markFailed($inbox->id, $orphans, 'Original message not found');
            $failed += count($orphans);
        }

        return [$submitted, $failed];
    }
}
