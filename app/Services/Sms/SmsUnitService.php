<?php

namespace App\Services\Sms;

use App\Models\SmsUnitLedger;
use App\Models\Subscription;
use App\Support\SmsParts;
use Illuminate\Support\Facades\DB;

/**
 * Client SMS units live on the client's single subscription row.
 * Every change goes through here so the ledger always matches the balance.
 */
class SmsUnitService
{
    /**
     * @return array<string, mixed>
     */
    public function balance(int $clientId): array
    {
        $subscription = Subscription::query()->where('client_id', $clientId)->first();

        if (! $subscription) {
            return [
                'units' => 0,
                'plan_name' => null,
                'status' => 'none',
                'expires_at' => null,
                'can_send' => false,
                'chars_per_unit' => SmsParts::charsPerUnit(),
                'message_max_length' => (int) config('sms.message_max_length', 1600),
            ];
        }

        $expired = $subscription->ends_at && $subscription->ends_at->isPast();

        return [
            'units' => (int) $subscription->units,
            'plan_name' => $subscription->plan_name,
            'status' => $expired ? 'expired' : $subscription->status,
            'expires_at' => optional($subscription->ends_at)->toDateTimeString(),
            'can_send' => ! $expired && $subscription->status === 'active' && $subscription->units > 0,
            'chars_per_unit' => SmsParts::charsPerUnit(),
            'message_max_length' => (int) config('sms.message_max_length', 1600),
        ];
    }

    /**
     * Take units from an active, unexpired subscription. Must run inside a transaction.
     *
     * @return array{ok: bool, reason?: string, previous?: int}
     */
    public function debit(int $clientId, int $units, ?int $inboxId, string $description): array
    {
        $subscription = Subscription::query()->where('client_id', $clientId)->lockForUpdate()->first();

        if (! $subscription || $subscription->status !== 'active') {
            return ['ok' => false, 'reason' => 'You do not have an active SMS plan.'];
        }

        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            return ['ok' => false, 'reason' => 'Your SMS plan has expired. Renew it to keep sending.'];
        }

        $previous = (int) $subscription->units;

        if ($previous < $units) {
            return ['ok' => false, 'reason' => "Not enough units. This send needs {$units} units and you have {$previous}."];
        }

        $subscription->forceFill(['units' => $previous - $units])->save();

        SmsUnitLedger::create([
            'client_id' => $clientId,
            'inbox_id' => $inboxId,
            'type' => 'debit',
            'units' => $units,
            'balance_before' => $previous,
            'balance_after' => $previous - $units,
            'description' => $description,
        ]);

        return ['ok' => true, 'previous' => $previous];
    }

    public function refund(int $clientId, int $units, ?int $inboxId, string $description): void
    {
        if ($units <= 0) {
            return;
        }

        DB::transaction(function () use ($clientId, $units, $inboxId, $description) {
            $subscription = Subscription::query()->where('client_id', $clientId)->lockForUpdate()->first();

            if (! $subscription) {
                return;
            }

            $previous = (int) $subscription->units;
            $subscription->forceFill(['units' => $previous + $units])->save();

            SmsUnitLedger::create([
                'client_id' => $clientId,
                'inbox_id' => $inboxId,
                'type' => 'refund',
                'units' => $units,
                'balance_before' => $previous,
                'balance_after' => $previous + $units,
                'description' => $description,
            ]);

            if ($inboxId) {
                DB::table('inboxes')->where('id', $inboxId)->increment('units_refunded', $units);
            }
        }, 3);
    }
}
