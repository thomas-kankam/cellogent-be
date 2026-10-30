<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Client;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Support\ApiCode;
use App\Support\ApiReason;

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
            'previous_balance' => $topUp->previous_balance,
            'payment_id' => $topUp->payment_id,
            'created_at' => optional($topUp->created_at)->toDateTimeString(),
        ];
    }

    public function credit(Client $client, float $amount, ?int $paymentId = null): Wallet
    {
        $wallet = $this->forClient($client);
        $previous = (float) $wallet->balance;

        $wallet->update(['balance' => $previous + $amount]);

        WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'client_id' => $client->id,
            'amount' => $amount,
            'previous_balance' => $previous,
            'payment_id' => $paymentId,
        ]);

        $this->activity()->record(
            'Wallet topped up',
            'client',
            'updated',
            'client',
            $client,
            $client,
            ['amount' => $amount, 'payment_id' => $paymentId],
        );

        return $wallet->fresh();
    }

    public function debit(Client $client, float $amount): array
    {
        $wallet = $this->forClient($client);

        if ((float) $wallet->balance < $amount) {
            return $this->result(true, 'Insufficient wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'balance' => $wallet->balance,
            ]);
        }

        $wallet->update(['balance' => (float) $wallet->balance - $amount]);

        return $this->result(false, 'Wallet debited.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($wallet->fresh()));
    }
}
