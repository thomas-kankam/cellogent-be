<?php

namespace App\Services\Payments;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\VerifyPaymentJob;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Survey;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Utilities\UtilityService;
use App\Services\WalletService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Support\Facades\DB;

/**
 * Every client purchase starts here. Prices come from our data (never from the request),
 * the admin's charge rate is added, then the first available gateway collects the total.
 */
class CheckoutService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /** Pay for campaign / survey rewards by momo or card (wallet funding uses the fund endpoints). */
    public const FUND_PURPOSES = ['campaign_fund', 'survey_fund'];

    /** Several utility orders paid together (bulk purchase by momo/card). */
    public const UTILITY_BULK = 'utility_bulk';

    /** Utilities sold through the Swift developer API (single purposes + the bulk bundle). */
    public const UTILITY_PURPOSES = ['data_bundle', 'airtime', 'ecg', 'gotv', 'dstv', 'starttimes', 'mashup', 'results', self::UTILITY_BULK];

    public const PURPOSES = ['wallet_top_up', 'sms_units', ...self::FUND_PURPOSES, ...self::UTILITY_PURPOSES];

    public const METHODS = ['momo', 'card', 'wallet'];

    public function __construct(
        protected OrderService $orders,
        protected PaymentService $payments,
        protected WalletService $wallets,
        protected GatewayManager $gateways,
    ) {}

    public static function chargePercent(string $purpose): float
    {
        return max(0, (float) (Setting::get("charges.{$purpose}") ?? 0));
    }

    /**
     * What the client will pay. Wallet payments carry no charge.
     *
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function quote(string $purpose, array $input, string $method = 'momo'): array
    {
        $item = match (true) {
            $purpose === 'wallet_top_up' => $this->topUpItem($input),
            $purpose === 'sms_units' => $this->planItem($input),
            in_array($purpose, self::FUND_PURPOSES, true) => $this->fundItem($purpose, $input),
            in_array($purpose, self::UTILITY_PURPOSES, true) => app(UtilityService::class)->checkoutItem($purpose, (array) ($input['utility'] ?? [])),
            default => 'This purchase type is not available yet.',
        };

        // Utility pricing can fail with its own status (e.g. provider unavailable).
        if (is_array($item) && ($item['in_error'] ?? false)) {
            return $item;
        }

        if (is_string($item)) {
            return $this->result(true, $item, ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $percent = $method === 'wallet' ? 0.0 : self::chargePercent($purpose);
        $charge = round($item['amount'] * $percent / 100, 2);

        return $this->result(false, 'Quote ready.', ApiCode::SUCCESS, ApiReason::SUCCESS, $item + [
            'purpose' => $purpose,
            'method' => $method,
            'charge_percent' => $percent,
            'charge' => $charge,
            'total_amount' => round($item['amount'] + $charge, 2),
            'currency' => config('payments.currency', 'GHS'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input  purpose-specific fields + method, phone_number, network
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function start(Client $client, string $purpose, array $input): array
    {
        $method = $input['method'] ?? 'momo';

        if ($purpose === 'wallet_top_up' && $method === 'wallet') {
            return $this->result(true, 'Cannot top up a wallet with wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        // Funding and utilities paid from the wallet have their own endpoints (hold / debit + provider call).
        if ($method === 'wallet' && (in_array($purpose, self::FUND_PURPOSES, true) || in_array($purpose, self::UTILITY_PURPOSES, true))) {
            return $this->result(true, 'Pay this from your wallet using its own endpoint.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $quote = $this->quote($purpose, $input, $method);

        if ($quote['in_error']) {
            return $quote;
        }

        if (in_array($purpose, self::FUND_PURPOSES, true) && ! $this->ownsFundTarget($client, $purpose, $input)) {
            return $this->result(true, $purpose === 'campaign_fund' ? 'Campaign not found.' : 'Survey not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $q = $quote['data'];
        $msisdn = null;

        if ($method === 'momo') {
            $msisdn = GhanaPhone::toMobile($input['phone_number'] ?? $client->phone_number);

            if (! $msisdn) {
                return $this->result(true, 'Enter a valid mobile money number.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                    'phone_number' => ['Enter a valid mobile money number.'],
                ]);
            }
        }

        if ($method !== 'wallet' && $this->gateways->candidates($method) === []) {
            return $this->result(true, 'This payment method is not available right now. Try another method.', ApiCode::BAD_REQUEST, ApiReason::GATEWAY_UNAVAILABLE);
        }

        try {
            // Wallet: debit + mark paid + deliver in ONE transaction, so a failure never leaves money taken without delivery.
            $payment = DB::transaction(function () use ($client, $purpose, $input, $method, $q, $msisdn) {
                $payment = $this->open($client, $purpose, $input, $method, $q, $msisdn);

                if ($method === 'wallet') {
                    $done = $this->payments->complete($payment->fresh());

                    if ($done['in_error']) {
                        throw new \RuntimeException('Wallet payment could not be completed: '.$done['message']);
                    }
                }

                return $payment;
            }, 3);
        } catch (InsufficientWallet) {
            return $this->result(true, 'Insufficient wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'balance' => $this->wallets->forClient($client)->balance,
                'required' => $q['total_amount'],
            ]);
        }

        if ($method === 'wallet') {
            return $this->respond($payment->fresh(), 'Payment completed from your wallet.');
        }

        return $this->collect($client, $payment->fresh());
    }

    /**
     * Order + pending payment (and the wallet debit for wallet payments) in one transaction.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $q
     */
    protected function open(Client $client, string $purpose, array $input, string $method, array $q, ?string $msisdn): Payment
    {
        return DB::transaction(function () use ($client, $purpose, $input, $method, $q, $msisdn) {
            $opened = $this->orders->open($client, [
                'type' => $purpose,
                'name' => $q['name'],
                'unit_price' => $q['amount'],
                'quantity' => 1,
                'network' => $input['network'] ?? null,
                'method' => $method,
                'meta_data' => $q['meta'],
            ]);

            $payment = $opened['payment'];
            $payment->update([
                'amount' => $q['amount'],
                'charge' => $q['charge'],
                'total_amount' => $q['total_amount'],
                'currency' => $q['currency'],
                'msisdn' => $msisdn,
                'email' => $this->emailFor($client),
                'gateway' => $method === 'wallet' ? 'wallet' : null,
            ]);

            if ($method === 'wallet' && ! $this->wallets->debit($client, (float) $q['total_amount'], $purpose, $payment, $q['name'])) {
                throw new InsufficientWallet;
            }

            return $payment;
        });
    }

    /**
     * Try each gateway for the method; fall back to the next one when a gateway is unavailable.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function collect(Client $client, Payment $payment): array
    {
        $lastError = 'Payment service is unavailable. Try again shortly.';

        foreach ($this->gateways->candidates($payment->method) as $gateway) {
            $result = $this->gateways->driver($gateway->code)->initiate($payment);

            if ($result->ok) {
                $this->gateways->recordSuccess($gateway->code);

                $payment->update([
                    'gateway' => $gateway->code,
                    'gateway_reference' => $result->reference,
                    'gateway_charge' => $result->gatewayCharge,
                    'payment_link' => $result->redirectUrl,
                    'gateway_response' => $result->raw,
                    'next_verify_at' => now()->addSeconds((int) (config('payments.verify_backoff')[0] ?? 20)),
                ]);

                VerifyPaymentJob::dispatch($payment->id)->delay(now()->addSeconds((int) (config('payments.verify_backoff')[0] ?? 20)));

                $this->activity()->record('Payment initiated', 'payment', 'created', 'client', $client, $client, [
                    'payment_id' => $payment->id,
                    'purpose' => $payment->purpose,
                    'total_amount' => $payment->total_amount,
                ]);

                return $this->respond($payment->fresh(), $result->message ?? 'Payment started.', $result->redirectUrl);
            }

            if ($result->retryable) {
                $this->gateways->recordFailure($gateway->code, (string) $result->message);
                $lastError = 'Payment service is unavailable. Try again shortly.';

                continue;
            }

            $this->payments->fail($payment, null, (string) $result->message, $result->raw);

            return $this->result(true, $result->message ?: 'Payment could not be started.', ApiCode::BAD_REQUEST, ApiReason::INVALID, $this->payments->present($payment->fresh()));
        }

        $this->payments->fail($payment, null, $lastError);

        return $this->result(true, $lastError, ApiCode::UNAVAILABLE, ApiReason::GATEWAY_UNAVAILABLE, $this->payments->present($payment->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function respond(Payment $payment, string $message, ?string $redirectUrl = null): array
    {
        $next = match (true) {
            $payment->status === Payment::PAID => ['type' => 'none'],
            $redirectUrl !== null => ['type' => 'redirect', 'url' => $redirectUrl],
            default => ['type' => 'momo_prompt', 'msisdn' => $payment->msisdn, 'message' => $message],
        };

        return $this->result(false, $message, ApiCode::CREATED, ApiReason::CREATED, $this->payments->present($payment) + [
            'next_action' => $next,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, amount: float, meta: array<string, mixed>}|string
     */
    protected function topUpItem(array $input): array|string
    {
        $amount = round((float) ($input['amount'] ?? 0), 2);
        $min = (float) config('payments.wallet_top_up.min', 1);
        $max = (float) config('payments.wallet_top_up.max', 50000);

        if ($amount < $min || $amount > $max) {
            return "Top-up amount must be between {$min} and {$max}.";
        }

        return ['name' => 'Wallet top up', 'amount' => $amount, 'meta' => ['amount' => $amount]];
    }

    /**
     * Campaign / survey funding: the amount is what the client must reserve; fulfilment re-checks it.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, amount: float, meta: array<string, mixed>}|string
     */
    protected function fundItem(string $purpose, array $input): array|string
    {
        $amount = round((float) ($input['amount'] ?? 0), 2);

        if ($amount < 0.01) {
            return 'Enter the amount to fund (at least 0.01).';
        }

        $key = $purpose === 'campaign_fund' ? 'campaign_id' : 'survey_id';
        $id = (int) ($input[$key] ?? 0);

        if ($id < 1) {
            return $purpose === 'campaign_fund' ? 'Choose the campaign to fund.' : 'Choose the survey to fund.';
        }

        $default = $purpose === 'campaign_fund' ? 'Campaign funding' : 'Survey funding';
        $name = trim((string) ($input['name'] ?? ''));

        return [
            'name' => $name !== '' ? mb_substr($name, 0, 120) : $default,
            'amount' => $amount,
            'meta' => [$key => $id, 'amount' => $amount],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function ownsFundTarget(Client $client, string $purpose, array $input): bool
    {
        $model = $purpose === 'campaign_fund' ? Campaign::class : Survey::class;
        $id = (int) ($input[$purpose === 'campaign_fund' ? 'campaign_id' : 'survey_id'] ?? 0);

        return $model::query()->where('client_id', $client->id)->whereKey($id)->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, amount: float, meta: array<string, mixed>}|string
     */
    protected function planItem(array $input): array|string
    {
        $plan = Plan::query()->find((int) ($input['plan_id'] ?? 0));

        if (! $plan || $plan->status !== 'active') {
            return 'Plan not found.';
        }

        // months 0 / null = non-expiry package on this plan.
        $months = array_key_exists('months', $input) && $input['months'] !== null && $input['months'] !== ''
            ? max(0, (int) $input['months'])
            : 0;
        $option = $plan->optionForMonths($months);

        if (! $option) {
            return $months === 0
                ? 'This plan has no non-expiry package.'
                : 'This plan has no option for that duration.';
        }

        $nonExpiry = $months === 0 || ! empty($option['non_expiry']);

        return [
            'name' => $nonExpiry
                ? $plan->name.' non-expiry'
                : $plan->name.' '.$months.' month plan',
            'amount' => round((float) $option['price'], 2),
            'meta' => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'months' => $months,
                'units' => $option['units'],
                'type' => $nonExpiry ? 'non_expiry' : 'monthly',
                'non_expiry' => $nonExpiry,
            ],
        ];
    }

    protected function emailFor(Client $client): string
    {
        return filter_var($client->email, FILTER_VALIDATE_EMAIL)
            ? $client->email
            : 'client'.$client->id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'cellogent.app');
    }
}
