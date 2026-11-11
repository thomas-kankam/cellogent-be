<?php

namespace App\Services\Sms;

use App\Models\Sent;
use Illuminate\Support\Facades\DB;

/**
 * Set-based writes on the sents table so large blasts update hundreds of rows per query.
 */
class SentWriter
{
    /**
     * Apply an aggregator response to the recipients it was sent for.
     *
     * @param  list<string>  $recipients
     * @return array{0: int, 1: int} [submitted, failed]
     */
    public function apply(int $inboxId, array $recipients, KairosResponse $response): array
    {
        // Timeout / 5xx: Kairos may already have sent. Keep the charge (no refund) and
        // mark submitted without ids so we never retry the same recipients.
        if ($response->uncertain) {
            $this->markUnconfirmed($inboxId, $recipients, $response->error ?? 'Unconfirmed (gateway timeout)');

            return [count($recipients), 0];
        }

        if (! $response->ok) {
            $this->markFailed($inboxId, $recipients, $response->error ?? 'Not accepted by aggregator');

            return [0, count($recipients)];
        }

        $accepted = $response->messagesByRecipient();

        // Single-message responses may only carry ids at the top level.
        if ($accepted === [] && count($recipients) === 1) {
            $accepted[$recipients[0]] = [
                'sequence_no' => $response->sequenceNo(),
                'message_id' => $response->transactionId(),
                'job_id' => null,
            ];
        }

        $submitted = array_intersect_key($accepted, array_flip($recipients));
        $missing = array_values(array_diff($recipients, array_keys($submitted)));

        $this->markSubmitted($inboxId, $submitted);

        if ($missing !== []) {
            $this->markFailed($inboxId, $missing, 'Not returned by aggregator');
        }

        return [count($submitted), count($missing)];
    }

    /**
     * @param  array<string, array{sequence_no: ?string, message_id: ?string, job_id: ?string}>  $accepted
     */
    public function markSubmitted(int $inboxId, array $accepted): void
    {
        $now = now();
        $nextCheck = $now->copy()->addSeconds((int) config('sms.dlr.first_check_after', 60));
        $pdo = DB::connection()->getPdo();

        foreach (array_chunk($accepted, 500, true) as $chunk) {
            $columns = [];

            foreach (['sequence_no', 'message_id', 'job_id'] as $column) {
                $sql = 'CASE `to`';
                foreach ($chunk as $to => $ids) {
                    $value = $ids[$column] ?? null;
                    $sql .= ' WHEN '.$pdo->quote((string) $to).' THEN '.($value === null ? 'NULL' : $pdo->quote((string) $value));
                }
                $columns[$column] = DB::raw($sql.' END');
            }

            DB::table('sents')
                ->where('inbox_id', $inboxId)
                ->whereIn('to', array_map('strval', array_keys($chunk)))
                ->update($columns + [
                    'status' => Sent::SUBMITTED,
                    'submitted_at' => $now,
                    'next_dlr_at' => $nextCheck,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @param  list<string>  $recipients
     */
    public function markFailed(int $inboxId, array $recipients, string $error): void
    {
        foreach (array_chunk($recipients, 1000) as $chunk) {
            DB::table('sents')
                ->where('inbox_id', $inboxId)
                ->whereIn('to', $chunk)
                ->where('status', Sent::QUEUED)
                ->update([
                    'status' => Sent::FAILED,
                    'error' => mb_substr($error, 0, 255),
                    'units_refunded' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Gateway outcome unknown: charge stands, no retry, no DLR ids.
     *
     * @param  list<string>  $recipients
     */
    public function markUnconfirmed(int $inboxId, array $recipients, string $error): void
    {
        $now = now();

        foreach (array_chunk($recipients, 1000) as $chunk) {
            DB::table('sents')
                ->where('inbox_id', $inboxId)
                ->whereIn('to', $chunk)
                ->where('status', Sent::QUEUED)
                ->update([
                    'status' => Sent::SUBMITTED,
                    'error' => mb_substr($error, 0, 255),
                    'units_refunded' => false,
                    'submitted_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $base
     */
    public function insertQueued(array $recipients, array $base): void
    {
        $now = now();
        $rows = array_map(fn (string $to) => $base + [
            'to' => $to,
            'status' => Sent::QUEUED,
            'created_at' => $now,
            'updated_at' => $now,
        ], $recipients);

        foreach (array_chunk($rows, 1000) as $batch) {
            DB::table('sents')->insertOrIgnore($batch);
        }
    }
}
