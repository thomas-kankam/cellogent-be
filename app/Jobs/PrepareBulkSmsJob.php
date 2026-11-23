<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\Sent;
use App\Services\Sms\SmsInboxProgress;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsUnitService;
use App\Support\ContactPlaceholders;
use App\Support\LongQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Expands a bulk inbox into one sents row per recipient (deduped by the inbox+to unique index),
 * then fans out SendBulkSmsBatchJob jobs. Never inserts more recipients than were paid for.
 */
class PrepareBulkSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    protected const READ_CHUNK = 5000;

    protected const INSERT_SIZE = 1000;

    public function __construct(public int $inboxId)
    {
        $this->onConnection(LongQueue::connection());
        $this->onQueue('sms-blast-bulk');
    }

    public function handle(SmsService $sms, SmsUnitService $units, SmsInboxProgress $progress): void
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

        $reserved = (int) $inbox->recipients_count;
        $remaining = $reserved;
        $now = now()->toDateTimeString();
        $base = [
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
            'status' => Sent::QUEUED,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $personalized = ContactPlaceholders::hasContactPlaceholders($inbox->message);
        $client = ($personalized && $inbox->client_id) ? Client::query()->find($inbox->client_id) : null;

        $collect = function ($rows, string $column, ?callable $messageFor = null) use ($base, &$remaining) {
            $buffer = [];

            foreach ($rows as $row) {
                $message = $messageFor ? $messageFor($row) : $base['message'];

                if ($message === null) {
                    continue;
                }

                $buffer[] = array_merge($base, [
                    'to' => (string) $row->{$column},
                    'message' => $message,
                ]);
            }

            while ($buffer !== [] && $remaining > 0) {
                $slice = array_splice($buffer, 0, min(self::INSERT_SIZE, $remaining));
                $remaining -= DB::transaction(fn () => DB::table('sents')->insertOrIgnore($slice), 3);
            }

            return $remaining > 0;
        };

        if ($inbox->mode === 'reblast') {
            DB::table('sents')
                ->where('retry_inbox_id', $inbox->id)
                ->when($inbox->client_id, fn ($q) => $q->where('client_id', $inbox->client_id))
                ->select(['id', 'to', 'message'])
                ->chunkById(self::READ_CHUNK, function ($rows) use ($collect, $personalized, $inbox, $client) {
                    if (! $personalized || ! $inbox->client_id) {
                        return $collect($rows, 'to', fn ($row) => $inbox->message ?: (string) $row->message);
                    }

                    $phones = $rows->pluck('to')->map(fn ($p) => (string) $p)->unique()->all();
                    $contacts = ContactPlaceholders::applyRequiredFilter(
                        Contact::query()
                            ->visible()
                            ->where('client_id', $inbox->client_id)
                            ->whereIn('phone_number', $phones),
                        $inbox->message,
                    )
                        ->orderBy('id')
                        ->get()
                        ->keyBy(fn (Contact $c) => (string) $c->phone_number);

                    return $collect($rows, 'to', function ($row) use ($contacts, $inbox, $client) {
                        $contact = $contacts->get((string) $row->to);

                        if ($contact) {
                            return ContactPlaceholders::render($inbox->message, $contact, $client);
                        }

                        return ! ContactPlaceholders::hasContactPlaceholders((string) $row->message)
                            ? (string) $row->message
                            : null;
                    });
                });
        } else {
            $columns = $personalized
                ? array_merge(['contacts.id'], array_map(fn (string $c) => "contacts.{$c}", ContactPlaceholders::CONTACT_COLUMNS))
                : ['contacts.id', 'contacts.phone_number'];

            $sms->audience((int) $inbox->client_id, $inbox->contact_group_ids ?? [], $inbox->message)
                ->toBase()
                ->select($columns)
                ->chunkById(
                    self::READ_CHUNK,
                    fn ($rows) => $collect(
                        $rows,
                        'phone_number',
                        $personalized ? fn ($row) => ContactPlaceholders::render($inbox->message, $row, $client) : null,
                    ),
                    'contacts.id',
                    'id',
                );
        }

        $inserted = DB::table('sents')->where('inbox_id', $inbox->id)->count();

        if ($inserted < $reserved && $inbox->client_id) {
            $units->refund($inbox->client_id, ($reserved - $inserted) * (int) $inbox->parts, $inbox->id, 'Refund for duplicate or removed contacts');
        }

        $jobSize = max(1, (int) config('sms.bulk.job_size', 2000));

        $inbox->update([
            'recipients_count' => $inserted,
            'batches_total' => (int) ceil($inserted / $jobSize),
        ]);

        if ($inserted === 0) {
            $progress->finish($inbox->id);

            return;
        }

        DB::table('sents')
            ->where('inbox_id', $inbox->id)
            ->select('id')
            ->chunkById($jobSize, function ($rows) use ($inbox) {
                SendBulkSmsBatchJob::dispatch($inbox->id, (int) $rows->first()->id, (int) $rows->last()->id);
            });
    }

    public function failed(?Throwable $e): void
    {
        app(SmsInboxProgress::class)->failUnsent(Inbox::query()->find($this->inboxId));
    }
}
