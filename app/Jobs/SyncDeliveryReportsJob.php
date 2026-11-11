<?php

namespace App\Jobs;

use App\Models\Sent;
use App\Services\Sms\KairosClient;
use App\Services\Sms\SmsUnitService;
use App\Support\SmsThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Polls delivery receipts for a set of submitted messages.
 * Undelivered / rejected / expired messages are marked failed and their units refunded,
 * so the client can re-blast them.
 */
class SyncDeliveryReportsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    protected const FAILED_STATUSES = ['UNDELIVERED', 'REJECTED', 'EXPIRED'];

    /**
     * @param  list<int>  $sentIds
     */
    public function __construct(public array $sentIds)
    {
        $this->onQueue('sms-dlr');
    }

    public function handle(KairosClient $kairos, SmsUnitService $units): void
    {
        $rows = Sent::query()
            ->whereIn('id', $this->sentIds)
            ->where('status', Sent::SUBMITTED)
            ->get(['id', 'inbox_id', 'client_id', 'sequence_no', 'units_used', 'dlr_attempts']);

        $delivered = [];
        $failed = [];      // dlr status => ids
        $retry = [];       // attempts => ids
        $stop = [];
        $inboxDelivered = [];
        $inboxFailed = [];
        $refunds = [];     // "client:inbox" => units
        $backoff = (array) config('sms.dlr.backoff', [60, 300, 900, 3600]);

        foreach ($rows as $row) {
            if (! $row->sequence_no) {
                $stop[] = $row->id;

                continue;
            }

            SmsThrottle::await('dlr', 1);

            $response = $kairos->deliveryReceipt($row->sequence_no);
            $status = strtoupper((string) ($response->data()['deliveryStatus'] ?? ''));

            if ($response->ok && $status === 'DELIVERED') {
                $delivered[] = $row->id;

                continue;
            }

            if ($response->ok && in_array($status, self::FAILED_STATUSES, true)) {
                $failed[$status][] = $row->id;

                continue;
            }

            // dlr_attempts is how many polls already ran; next wait uses backoff[attempts].
            $attempts = (int) $row->dlr_attempts;

            if ($attempts >= count($backoff)) {
                $stop[] = $row->id;
            } else {
                $retry[$attempts][] = $row->id;
            }
        }

        $now = now();
        $byId = $rows->keyBy('id');

        DB::transaction(function () use ($delivered, $failed, $retry, $stop, &$inboxDelivered, &$inboxFailed, &$refunds, $backoff, $now, $byId) {
            // Each row is flipped only if it is still submitted, so an overlapping job
            // (lease expired, retry) can never count or refund the same message twice.
            $inboxDelivered = [];
            $inboxFailed = [];
            $refunds = [];

            foreach ($delivered as $id) {
                $changed = Sent::query()->whereKey($id)->where('status', Sent::SUBMITTED)->update([
                    'status' => Sent::DELIVERED,
                    'dlr_status' => 'DELIVERED',
                    'delivered_at' => $now,
                    'next_dlr_at' => null,
                ]);

                if ($changed) {
                    $this->bump($inboxDelivered, $byId[$id]->inbox_id);
                }
            }

            foreach ($failed as $status => $ids) {
                foreach ($ids as $id) {
                    $changed = Sent::query()->whereKey($id)->where('status', Sent::SUBMITTED)->update([
                        'status' => Sent::FAILED,
                        'dlr_status' => $status,
                        'units_refunded' => true,
                        'next_dlr_at' => null,
                    ]);

                    if (! $changed) {
                        continue;
                    }

                    $row = $byId[$id];
                    $this->bump($inboxFailed, $row->inbox_id);

                    if ($row->client_id) {
                        $key = $row->client_id.':'.($row->inbox_id ?? 0);
                        $refunds[$key] = ($refunds[$key] ?? 0) + (int) $row->units_used;
                    }
                }
            }

            foreach ($retry as $attempts => $ids) {
                Sent::query()->whereIn('id', $ids)->update([
                    'dlr_attempts' => $attempts + 1,
                    'next_dlr_at' => $now->copy()->addSeconds((int) $backoff[$attempts]),
                ]);
            }

            if ($stop !== []) {
                Sent::query()->whereIn('id', $stop)->update(['next_dlr_at' => null]);
            }

            foreach ($inboxDelivered as $inboxId => $count) {
                DB::table('inboxes')->where('id', $inboxId)->increment('delivered_count', $count);
            }

            foreach ($inboxFailed as $inboxId => $count) {
                DB::table('inboxes')->where('id', $inboxId)->increment('failed_count', $count);
            }
        }, 3);

        foreach ($refunds as $key => $amount) {
            [$clientId, $inboxId] = array_map('intval', explode(':', $key));
            $units->refund($clientId, $amount, $inboxId ?: null, 'Refund for undelivered messages');
        }
    }

    /**
     * @param  array<int, int>  $counts
     */
    protected function bump(array &$counts, ?int $inboxId): void
    {
        if ($inboxId) {
            $counts[$inboxId] = ($counts[$inboxId] ?? 0) + 1;
        }
    }
}
