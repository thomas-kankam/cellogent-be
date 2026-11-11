<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The client's money held by us. Every change locks the wallet row and writes a
 * wallet_transactions row, so balance and history always agree.
 */
class WalletService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public function forClient(Client $client): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['client_id' => $client->id],
            ['balance' => 0, 'currency' => 'GHS', 'status' => 'active'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Wallet $wallet): array
    {
        $wallet->loadMissing('client');
        $client = $wallet->client;
        $reserved = $this->reservedTotal($wallet->client_id);
        $balance = (float) $wallet->balance;

        return [
            'id' => $wallet->id,
            'client_id' => $wallet->client_id,
            'status' => $wallet->status,
            'balance' => $balance,
            'reserved' => $reserved,
            'available' => round(max(0, $balance - $reserved), 2),
            'currency' => $wallet->currency,
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'email' => $client->email,
                'phone_number' => $client->phone_number,
                'account_type' => $client->account_type,
                'business_name' => $client->business_name,
                'status' => $client->status,
                'is_verified' => (bool) $client->is_verified,
                'profile_image' => $client->profileImageUrl(),
            ] : null,
        ];
    }

    /**
     * Spendable money = ledger balance minus active campaign/survey holds.
     */
    public function available(Client $client): float
    {
        $wallet = $this->forClient($client);

        return round(max(0, (float) $wallet->balance - $this->reservedTotal($client->id)), 2);
    }

    public function reservedTotal(int $clientId): float
    {
        return round((float) WalletHold::query()
            ->where('client_id', $clientId)
            ->where('status', WalletHold::ACTIVE)
            ->sum('remaining'), 2);
    }

    /**
     * Reserve funds for an active campaign/survey so other spends cannot drain them.
     * Does not change ledger balance — only reduces available.
     */
    public function hold(
        Client $client,
        float $amount,
        Model $holdable,
        string $purpose,
        ?Payment $payment = null,
        ?array $meta = null,
    ): ?WalletHold {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return null;
        }

        $this->forClient($client);

        return DB::transaction(function () use ($client, $amount, $holdable, $purpose, $payment, $meta) {
            $wallet = Wallet::query()->where('client_id', $client->id)->lockForUpdate()->first();
            $reserved = $this->reservedTotal($client->id);
            $available = round((float) $wallet->balance - $reserved, 2);

            if ($wallet->status !== 'active' || $available + 0.00001 < $amount) {
                return null;
            }

            return WalletHold::create([
                'client_id' => $client->id,
                'wallet_id' => $wallet->id,
                'holdable_type' => $holdable->getMorphClass(),
                'holdable_id' => $holdable->getKey(),
                'amount' => $amount,
                'remaining' => $amount,
                'currency' => $wallet->currency ?? 'GHS',
                'status' => WalletHold::ACTIVE,
                'purpose' => $purpose,
                'payment_id' => $payment?->id,
                'meta' => $meta,
            ]);
        }, 3);
    }

    /**
     * Free unused reserved funds (campaign/survey closed or cancelled).
     */
    public function release(WalletHold $hold): WalletHold
    {
        return DB::transaction(function () use ($hold) {
            $hold = WalletHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($hold->status !== WalletHold::ACTIVE) {
                return $hold;
            }

            $hold->update([
                'status' => ((float) $hold->remaining) <= 0.00001 ? WalletHold::CONSUMED : WalletHold::RELEASED,
                'remaining' => 0,
            ]);

            return $hold->fresh();
        }, 3);
    }

    /**
     * Mark part of a hold as spent (reward disbursed). Pair with debit() in the same transaction.
     */
    public function consume(WalletHold $hold, float $amount): WalletHold
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($hold, $amount) {
            $hold = WalletHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($hold->status !== WalletHold::ACTIVE || $amount <= 0) {
                return $hold;
            }

            $remaining = round(max(0, (float) $hold->remaining - $amount), 2);
            $hold->update([
                'remaining' => $remaining,
                'status' => $remaining <= 0.00001 ? WalletHold::CONSUMED : WalletHold::ACTIVE,
            ]);

            return $hold->fresh();
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTopUp(WalletTopUp $topUp): array
    {
        $topUp->loadMissing('client');
        $client = $topUp->client;

        return [
            'id' => $topUp->id,
            'wallet_id' => $topUp->wallet_id,
            'client_id' => $topUp->client_id,
            'amount' => $topUp->amount,
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'email' => $client->email,
                'phone_number' => $client->phone_number,
                'account_type' => $client->account_type,
                'business_name' => $client->business_name,
                'status' => $client->status,
                'is_verified' => (bool) $client->is_verified,
                'profile_image' => $client->profileImageUrl(),
            ] : null,
            'charge' => $topUp->charge,
            'amount_paid' => $topUp->amount_paid,
            'previous_balance' => $topUp->previous_balance,
            'balance_after' => $topUp->balance_after,
            'payment_id' => $topUp->payment_id,
            'created_at' => optional($topUp->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTransaction(WalletTransaction $transaction): array
    {
        $transaction->loadMissing('client');
        $client = $transaction->client;

        return [
            'id' => $transaction->id,
            'client_id' => $transaction->client_id,
            'type' => $transaction->type,
            'purpose' => $transaction->purpose,
            'amount' => $transaction->amount,
            'balance_before' => $transaction->balance_before,
            'balance_after' => $transaction->balance_after,
            'payment_id' => $transaction->payment_id,
            'order_id' => $transaction->order_id,
            'description' => $transaction->description,
            'created_at' => optional($transaction->created_at)->toDateTimeString(),
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'email' => $client->email,
                'phone_number' => $client->phone_number,
                'account_type' => $client->account_type,
                'business_name' => $client->business_name,
                'status' => $client->status,
                'is_verified' => (bool) $client->is_verified,
                'profile_image' => $client->profileImageUrl(),
            ] : null,
        ];
    }

    /**
     * Add money. For top-ups, also writes the wallet_top_ups history row.
     */
    public function credit(Client $client, float $amount, string $purpose, ?Payment $payment = null, ?string $description = null): Wallet
    {
        $this->forClient($client);

        $wallet = DB::transaction(function () use ($client, $amount, $purpose, $payment, $description) {
            $wallet = Wallet::query()->where('client_id', $client->id)->lockForUpdate()->first();
            $previous = (float) $wallet->balance;
            $after = round($previous + $amount, 2);

            $wallet->update(['balance' => $after]);

            WalletTransaction::create([
                'client_id' => $client->id,
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'purpose' => $purpose,
                'amount' => $amount,
                'balance_before' => $previous,
                'balance_after' => $after,
                'payment_id' => $payment?->id,
                'order_id' => $payment?->order_id,
                'description' => $description,
            ]);

            if ($purpose === 'wallet_top_up') {
                WalletTopUp::create([
                    'wallet_id' => $wallet->id,
                    'client_id' => $client->id,
                    'amount' => $amount,
                    'charge' => (float) ($payment?->charge ?? 0),
                    'amount_paid' => (float) ($payment?->total_amount ?? $amount),
                    'previous_balance' => $previous,
                    'balance_after' => $after,
                    'payment_id' => $payment?->id,
                ]);
            }

            return $wallet;
        }, 3);

        $this->activity()->record('Wallet credited', 'wallet', 'updated', 'client', $client, $client, [
            'amount' => $amount,
            'purpose' => $purpose,
            'payment_id' => $payment?->id,
        ]);

        return $wallet->fresh();
    }

    /**
     * Take money. Returns false (nothing changed) if the balance is too low.
     * Call inside the caller's transaction when the debit must roll back with other work.
     */
    public function debit(Client $client, float $amount, string $purpose, ?Payment $payment = null, ?string $description = null): bool
    {
        $this->forClient($client);

        return DB::transaction(function () use ($client, $amount, $purpose, $payment, $description) {
            $wallet = Wallet::query()->where('client_id', $client->id)->lockForUpdate()->first();
            $previous = (float) $wallet->balance;
            // Active campaign/survey holds cannot be spent on utilities or other checkout.
            $available = round($previous - $this->reservedTotal($client->id), 2);

            if ($wallet->status !== 'active' || $available + 0.00001 < $amount) {
                return false;
            }

            $after = round($previous - $amount, 2);
            $wallet->update(['balance' => $after]);

            WalletTransaction::create([
                'client_id' => $client->id,
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'purpose' => $purpose,
                'amount' => $amount,
                'balance_before' => $previous,
                'balance_after' => $after,
                'payment_id' => $payment?->id,
                'order_id' => $payment?->order_id,
                'description' => $description,
            ]);

            return true;
        }, 3);
    }

    /**
     * Debit against a hold (reward payout). Uses ledger balance, then shrinks the hold so
     * available for other spends stays correct.
     */
    public function debitFromHold(Client $client, WalletHold $hold, float $amount, string $purpose, ?string $description = null): bool
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($client, $hold, $amount, $purpose, $description) {
            $hold = WalletHold::query()->whereKey($hold->id)->lockForUpdate()->first();

            if (! $hold || $hold->status !== WalletHold::ACTIVE || (float) $hold->remaining + 0.00001 < $amount) {
                return false;
            }

            $wallet = Wallet::query()->where('client_id', $client->id)->lockForUpdate()->first();
            $previous = (float) $wallet->balance;

            if ($wallet->status !== 'active' || $previous + 0.00001 < $amount) {
                return false;
            }

            $after = round($previous - $amount, 2);
            $wallet->update(['balance' => $after]);

            WalletTransaction::create([
                'client_id' => $client->id,
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'purpose' => $purpose,
                'amount' => $amount,
                'balance_before' => $previous,
                'balance_after' => $after,
                'payment_id' => $hold->payment_id,
                'order_id' => null,
                'description' => $description,
            ]);

            $remaining = round((float) $hold->remaining - $amount, 2);
            $hold->update([
                'remaining' => $remaining,
                'status' => $remaining <= 0.00001 ? WalletHold::CONSUMED : WalletHold::ACTIVE,
            ]);

            return true;
        }, 3);
    }
}
