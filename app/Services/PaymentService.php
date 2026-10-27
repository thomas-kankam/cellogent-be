<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Facades\DB;

/**
 * Starts a payment (order + payment row) and fulfills it when marked paid.
 * Wallet checkout completes immediately. Other methods stay pending until admin completes them.
 */
class PaymentService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public function __construct(
        protected OrderService $orders,
        protected WalletService $wallets,
        protected SubscriptionService $subscriptions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'client_id' => $payment->client_id,
            'admin_id' => $payment->admin_id,
            'order_id' => $payment->order_id,
            'network' => $payment->network,
            'method' => $payment->method,
            'reference_id' => $payment->reference_id,
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'payment_link' => $payment->payment_link,
            'created_at' => optional($payment->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function initiate(Client $client, array $input, ?Admin $admin = null): array
    {
        $method = $input['method'] ?? 'momo';
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $total = (float) $input['unit_price'] * $quantity;

        if ($method === 'wallet') {
            $wallet = $this->wallets->forClient($client);
            if ((float) $wallet->balance < $total) {
                return $this->result(true, 'Insufficient wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                    'balance' => $wallet->balance,
                ]);
            }
        }

        return DB::transaction(function () use ($client, $input, $admin, $method) {
            $opened = $this->orders->open($client, $input, $admin?->id);
            $order = $opened['order'];
            $payment = $opened['payment'];

            if ($method === 'wallet') {
                $this->wallets->debit($client, (float) $order->total_price);

                return $this->complete($payment->fresh(), $admin);
            }

            $this->activity()->record(
                'Payment initiated',
                'client',
                'created',
                $admin ? 'admin' : 'client',
                $admin ?? $client,
                $client,
                ['payment_id' => $payment->id, 'purpose' => $payment->purpose],
            );

            return $this->result(false, 'Payment initiated.', ApiCode::CREATED, ApiReason::CREATED, [
                'payment' => $this->present($payment),
                'order' => $this->orders->present($order),
            ]);
        });
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function complete(Payment $payment, ?Admin $admin = null): array
    {
        if ($payment->status === 'paid') {
            return $this->result(true, 'This payment is already completed.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        if ($payment->status === 'failed') {
            return $this->result(true, 'This payment has failed.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $client = Client::query()->find($payment->client_id);
        if (! $client) {
            return $this->result(true, 'Client not found for this payment.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return DB::transaction(function () use ($payment, $admin, $client) {
            $payment->update([
                'status' => 'paid',
                'admin_id' => $admin?->id ?? $payment->admin_id,
            ]);

            if ($payment->order_id) {
                $order = Order::query()->find($payment->order_id);
                if ($order) {
                    $this->orders->markPaid($order);
                }
            }

            $this->fulfill($payment->fresh(), $client, $admin);

            $this->activity()->record(
                'Payment completed',
                'client',
                'updated',
                $admin ? 'admin' : 'client',
                $admin ?? $client,
                $client,
                ['payment_id' => $payment->id, 'purpose' => $payment->purpose],
            );

            return $this->result(false, 'Payment completed.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
                'payment' => $this->present($payment->fresh()),
            ]);
        });
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function fail(Payment $payment, Admin $admin): array
    {
        if ($payment->status !== 'pending') {
            return $this->result(true, 'Only pending payments can be failed.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        $payment->update([
            'status' => 'failed',
            'admin_id' => $admin->id,
        ]);

        if ($payment->order_id) {
            Order::query()->where('id', $payment->order_id)->update(['status' => 'failed']);
        }

        return $this->result(false, 'Payment marked as failed.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($payment->fresh()));
    }

    protected function fulfill(Payment $payment, Client $client, ?Admin $admin): void
    {
        if ($payment->purpose === 'wallet_top_up' && $payment->order_id) {
            $order = Order::query()->find($payment->order_id);
            if ($order) {
                $this->wallets->credit($client, (float) $order->total_price, $payment->id);
            }

            return;
        }

        if ($payment->purpose === 'sms_units' && $payment->order_id) {
            $order = Order::query()->with('items')->find($payment->order_id);
            $meta = $order?->items->first()?->meta_data ?? [];
            $planId = $meta['plan_id'] ?? null;
            $months = (int) ($meta['months'] ?? 1);
            $type = $meta['type'] ?? 'monthly';
            $plan = $planId ? Plan::query()->find($planId) : null;

            if ($plan) {
                $this->subscriptions->activatePlan(
                    $client,
                    $plan,
                    $months,
                    $type,
                    $payment->id,
                    $payment->reference_id,
                    'paid',
                    $admin,
                );
            }
        }
    }
}
