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
                'plan_type' => null,
                'status' => 'none',
                'is_non_expiry' => false,
                'is_expired' => false,
                'expires_at' => null,
                'expiry_message' => 'You do not have an active SMS package.',
                'can_send' => false,
                'chars_per_unit' => SmsParts::charsPerUnit(),
                'message_max_length' => (int) config('sms.message_max_length', 1600),
            ];
        }

        $isNonExpiry = $subscription->type === 'non_expiry' || $subscription->ends_at === null;
        $expired = ! $isNonExpiry && $subscription->ends_at->isPast();
        $expiresAt = $isNonExpiry ? 'non_expiry' : $subscription->ends_at->toDateTimeString();

        if ($isNonExpiry) {
            $expiryMessage = 'You are on a non-expiry package; your SMS units do not expire.';
        } elseif ($expired) {
            $expiryMessage = "Your SMS package expired on {$expiresAt}.";
        } else {
            $expiryMessage = "Your SMS package expires on {$expiresAt}.";
        }

        return [
            'units' => (int) $subscription->units,
            'plan_name' => $subscription->plan_name,
            'plan_type' => $subscription->type ?? ($isNonExpiry ? 'non_expiry' : 'monthly'),
            'status' => $expired ? 'expired' : $subscription->status,
            'is_non_expiry' => $isNonExpiry,
            'is_expired' => $expired,
            'expires_at' => $expiresAt,
            'expiry_message' => $expiryMessage,
            'can_send' => ! $expired && $subscription->status === 'active' && $subscription->units > 0,
            'chars_per_unit' => SmsParts::charsPerUnit(),
            'message_max_length' => (int) config('sms.message_max_length', 1600),
        ];
    }

    /**
     * Check that the client has an active, non-expired SMS plan with at least `$requiredUnits`.
     *
     * @return array{ok: bool, reason?: string, required_units: int, available_units: int, shortfall_units: int}
     */
    public function checkAvailability(int $clientId, int $requiredUnits, string $actionLabel = 'send this message'): array
    {
        $requiredUnits = max(1, $requiredUnits);
        $subscription = Subscription::query()->where('client_id', $clientId)->first();

        if (! $subscription || $subscription->status !== 'active') {
            return [
                'ok' => false,
                'reason' => "You do not have an active SMS plan. Please purchase or top up your SMS units to {$actionLabel}.",
                'required_units' => $requiredUnits,
                'available_units' => 0,
                'shortfall_units' => $requiredUnits,
            ];
        }

        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            return [
                'ok' => false,
                'reason' => "Your SMS plan has expired. Please renew or top up your SMS units to {$actionLabel}.",
                'required_units' => $requiredUnits,
                'available_units' => 0,
                'shortfall_units' => $requiredUnits,
            ];
        }

        $available = (int) $subscription->units;

        if ($available < $requiredUnits) {
            $shortfall = $requiredUnits - $available;

            return [
                'ok' => false,
                'reason' => "Not enough SMS units to {$actionLabel}. Required: {$requiredUnits} units, available: {$available} units (shortfall: {$shortfall} units). Please top up your SMS units first.",
                'required_units' => $requiredUnits,
                'available_units' => $available,
                'shortfall_units' => $shortfall,
            ];
        }

        return [
            'ok' => true,
            'required_units' => $requiredUnits,
            'available_units' => $available,
            'shortfall_units' => 0,
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
            if ($inboxId) {
                $inbox = DB::table('inboxes')
                    ->where('id', $inboxId)
                    ->lockForUpdate()
                    ->first(['units_used', 'units_refunded']);

                if ($inbox) {
                    $refundable = max(0, (int) $inbox->units_used - (int) $inbox->units_refunded);
                    $units = min($units, $refundable);

                    if ($units <= 0) {
                        return;
                    }
                }
            }

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
