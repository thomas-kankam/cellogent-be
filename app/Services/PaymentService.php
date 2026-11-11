<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Survey;
use App\Services\Payments\CheckoutService;
use App\Services\Utilities\UtilityService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Facades\DB;

/**
 * Completes / fails payments and delivers what was bought.
 * Completion is idempotent: webhook, poller and admin can race safely.
 */
class PaymentService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public function __construct(
        protected OrderService $orders,
        protected WalletService $wallets,
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * Client view: no gateway internals.
     *
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'resource' => 'payment',
            'client_id' => $payment->client_id,
            'order_id' => $payment->order_id,
            'reference_id' => $payment->reference_id,
            'purpose' => $payment->purpose,
            'method' => $payment->method,
            'network' => $payment->network,
            'msisdn' => $payment->msisdn,
            'amount' => $payment->amount,
            'charge' => $payment->charge,
            'total_amount' => $payment->total_amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_link' => $payment->status === Payment::PENDING ? $payment->payment_link : null,
            'failure_reason' => $payment->failure_reason,
            'paid_at' => optional($payment->paid_at)->toDateTimeString(),
            'created_at' => optional($payment->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForAdmin(Payment $payment): array
    {
        return $this->present($payment) + [
            'admin_id' => $payment->admin_id,
            'gateway' => $payment->gateway,
            'gateway_reference' => $payment->gateway_reference,
            'gateway_charge' => $payment->gateway_charge,
            'payment_link' => $payment->payment_link,
            'email' => $payment->email,
            'verify_attempts' => $payment->verify_attempts,
            'next_verify_at' => optional($payment->next_verify_at)->toDateTimeString(),
            'failed_at' => optional($payment->failed_at)->toDateTimeString(),
            'gateway_response' => $payment->gateway_response,
        ];
    }

    /**
     * Mark paid and deliver. Allowed from pending or failed (money can arrive after a timeout).
     *
     * @param  array<string, mixed>  $gatewayData
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function complete(Payment $payment, ?Admin $admin = null, array $gatewayData = []): array
    {
        $client = Client::query()->find($payment->client_id);

        if (! $client) {
            return $this->result(true, 'Client not found for this payment.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $done = DB::transaction(function () use ($payment, $admin, $client, $gatewayData) {
            $locked = Payment::query()->lockForUpdate()->find($payment->id);

            if (! $locked || $locked->status === Payment::PAID) {
                return false;
            }

            $changes = [
                'status' => Payment::PAID,
                'paid_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'next_verify_at' => null,
                'admin_id' => $admin?->id ?? $locked->admin_id,
            ];

            if (isset($gatewayData['gateway_charge'])) {
                $changes['gateway_charge'] = $gatewayData['gateway_charge'];
            }

            if (isset($gatewayData['raw'])) {
                $changes['gateway_response'] = $gatewayData['raw'];
            }

            // Backfill how the payer paid when checkout left method open (Paystack card/momo picker).
            if (! empty($gatewayData['method']) && blank($locked->method)) {
                $changes['method'] = $gatewayData['method'];
            }

            $locked->update($changes);

            if ($locked->order_id && $order = Order::query()->find($locked->order_id)) {
                $this->orders->markPaid($order);
            }

            $this->fulfill($locked->fresh(), $client, $admin);

            return true;
        }, 3);

        if (! $done) {
            return $this->result(true, 'This payment is already completed.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        $this->activity()->record('Payment completed', 'payment', 'updated', $admin ? 'admin' : 'client', $admin ?? $client, $client, [
            'payment_id' => $payment->id,
            'purpose' => $payment->purpose,
            'amount' => $payment->total_amount,
        ]);

        return $this->result(false, 'Payment completed.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($payment->fresh()));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function fail(Payment $payment, ?Admin $admin = null, string $reason = 'Payment failed.', ?array $raw = null): array
    {
        $changes = [
            'status' => Payment::FAILED,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 255),
            'next_verify_at' => null,
            'updated_at' => now(),
        ];

        if ($admin) {
            $changes['admin_id'] = $admin->id;
        }

        if ($raw) {
            $changes['gateway_response'] = json_encode($raw);
        }

        $updated = Payment::query()
            ->whereKey($payment->id)
            ->where('status', Payment::PENDING)
            ->update($changes);

        if (! $updated) {
            return $this->result(true, 'Only pending payments can be failed.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        if ($payment->order_id) {
            Order::query()->where('id', $payment->order_id)->where('status', 'pending')->update(['status' => 'failed']);
        }

        return $this->result(false, 'Payment marked as failed.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($payment->fresh()));
    }

    protected function fulfill(Payment $payment, Client $client, ?Admin $admin): void
    {
        if ($payment->purpose === 'wallet_top_up') {
            $this->wallets->credit($client, (float) $payment->amount, 'wallet_top_up', $payment, 'Wallet top up '.$payment->reference_id);

            return;
        }

        if (in_array($payment->purpose, CheckoutService::FUND_PURPOSES, true)) {
            $this->fulfillFunding($payment, $client);

            return;
        }

        if (in_array($payment->purpose, CheckoutService::UTILITY_PURPOSES, true)) {
            $this->fulfillUtility($payment);

            return;
        }

        if ($payment->purpose === 'sms_units' && $payment->order_id) {
            $order = Order::query()->with('items')->find($payment->order_id);
            $item = $order?->items->first();
            $meta = $item?->meta_data ?? [];
            $plan = isset($meta['plan_id']) ? Plan::query()->find($meta['plan_id']) : null;

            if (! $plan || ! isset($meta['units'], $meta['months'])) {
                // Rolls back the whole completion so money is never marked paid without delivery.
                throw new \RuntimeException("Cannot fulfil SMS plan for payment {$payment->id}: order details missing.");
            }

            $result = $this->subscriptions->activatePlan(
                $client,
                $plan,
                (int) $meta['months'],
                $meta['type'] ?? 'monthly',
                $payment->id,
                $payment->reference_id,
                'paid',
                $admin,
                false,
                [
                    'months' => (int) $meta['months'],
                    'units' => (int) $meta['units'],
                    'price' => (float) $item->unit_price,
                ],
            );

            if ($result['in_error']) {
                throw new \RuntimeException("Cannot fulfil SMS plan for payment {$payment->id}: {$result['message']}");
            }
        }
    }

    /**
     * Campaign / survey funding: the money lands in the wallet, then the reward budget is reserved
     * (held) for that campaign or survey. If the target changed or is gone, the money simply stays
     * in the wallet as available balance — it is never lost.
     */
    protected function fulfillFunding(Payment $payment, Client $client): void
    {
        $isCampaign = $payment->purpose === 'campaign_fund';
        $label = $isCampaign ? 'Campaign funding' : 'Survey funding';

        $this->wallets->credit($client, (float) $payment->amount, $payment->purpose, $payment, $label.' '.$payment->reference_id);

        $order = $payment->order_id ? Order::query()->with('items')->find($payment->order_id) : null;
        $meta = $order?->items->first()?->meta_data ?? [];
        $id = (int) ($meta[$isCampaign ? 'campaign_id' : 'survey_id'] ?? 0);

        $model = $id > 0
            ? ($isCampaign ? Campaign::class : Survey::class)::query()->where('client_id', $client->id)->lockForUpdate()->find($id)
            : null;

        if (! $model || $model->status !== 'draft' || $model->wallet_hold_id || ! $model->reward_enabled) {
            return;
        }

        $required = round((float) $model->estimated_cost, 2);

        if ($required <= 0 || (float) $payment->amount + 0.009 < $required) {
            return;
        }

        $hold = $this->wallets->hold($client, $required, $model, $payment->purpose, $payment, [
            ($isCampaign ? 'campaign_id' : 'survey_id') => $model->id,
        ]);

        if (! $hold) {
            return;
        }

        $model->update([
            'wallet_hold_id' => $hold->id,
            'funding_method' => $payment->method,
            'payment_id' => $payment->id,
            'reserved_amount' => $hold->amount,
            'status' => 'funded',
        ]);

        $this->activity()->record($label.' paid', $isCampaign ? 'campaign' : 'survey', 'funded', 'client', $client, $client, [
            ($isCampaign ? 'campaign_id' : 'survey_id') => $model->id,
            'payment_id' => $payment->id,
            'amount' => $required,
        ]);
    }

    /**
     * Utility purchase paid by momo/card: create the order(s) and queue them for the provider.
     * A provider failure refunds the client's wallet, so completion itself never fails here.
     */
    protected function fulfillUtility(Payment $payment): void
    {
        $order = $payment->order_id ? Order::query()->with('items')->find($payment->order_id) : null;
        $spec = $order?->items->first()?->meta_data['utility'] ?? null;

        if (! is_array($spec)) {
            // Rolls back the whole completion so money is never marked paid without delivery.
            throw new \RuntimeException("Cannot fulfil utility purchase for payment {$payment->id}: order details missing.");
        }

        app(UtilityService::class)->queueFromPayment($payment, $spec);
    }
}
