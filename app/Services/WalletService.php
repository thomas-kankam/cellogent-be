<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
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

        return [
            'id' => $wallet->id,
            'client_id' => $wallet->client_id,
            'status' => $wallet->status,
            'balance' => $wallet->balance,
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
     * @return array<string, mixed>
     */
    public function presentTopUp(WalletTopUp $topUp): array
    {
        return [
            'id' => $topUp->id,
            'wallet_id' => $topUp->wallet_id,
            'client_id' => $topUp->client_id,
            'amount' => $topUp->amount,
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
    public function presentTransaction(WalletTransaction $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type,
            'purpose' => $row->purpose,
            'amount' => $row->amount,
            'balance_before' => $row->balance_before,
            'balance_after' => $row->balance_after,
            'payment_id' => $row->payment_id,
            'order_id' => $row->order_id,
            'description' => $row->description,
            'created_at' => optional($row->created_at)->toDateTimeString(),
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
                'payment_id' => $payment?->id,
                'order_id' => $payment?->order_id,
                'description' => $description,
            ]);

            return true;
        }, 3);
    }
}
