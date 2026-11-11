<?php

namespace App\Services\Utilities;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\ProcessUtilityOrderJob;
use App\Models\Client;
use App\Models\Payment;
use App\Models\UtilityOrder;
use App\Services\MarginService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\InsufficientWallet;
use App\Services\RewardDisbursementService;
use App\Services\WalletService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data, MashUp, airtime, ECG, TV and results checkers bought through the SwiftData developer API.
 * Prices always come from Swift's catalogue plus our admin margin — never from the request.
 * Clients only ever see the selling price; cost and profit are admin-only.
 */
class UtilityService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public const METHODS = ['wallet', 'momo', 'card'];

    /** Order/payment purpose → utility service. */
    protected const PURPOSE_SERVICE = [
        'data_bundle' => 'data',
        'mashup' => 'mashup',
        'airtime' => 'airtime',
        'ecg' => 'ecg',
        'gotv' => 'tv',
        'dstv' => 'tv',
        'starttimes' => 'tv',
        'results' => 'results',
    ];

    public function __construct(
        protected SwiftClient $swift,
        protected SwiftCatalog $catalog,
        protected MarginService $margins,
        protected WalletService $wallets,
        protected CheckoutService $checkout,
    ) {}

    public static function serviceForPurpose(string $purpose): ?string
    {
        return self::PURPOSE_SERVICE[$purpose] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */

    /**
     * Everything we can sell. Clients get selling prices only; admins also see Swift's cost,
     * our profit and the margin rule that produced it.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function catalog(bool $admin = false): array
    {
        $plans = $this->catalog->plans();
        $reachable = $plans !== null;

        $categories = [];
        $flat = [];

        foreach ($plans ?? [] as $plan) {
            $item = $this->presentPlan($plan, $admin);
            $flat[] = $item;
            $categories[$plan['network']][] = $item;
        }

        $networks = [];

        foreach ($categories as $network => $items) {
            $networks[] = [
                'network' => $network,
                'product_type' => $items[0]['product_type'],
                'plans' => $items,
            ];
        }

        $hasData = collect($flat)->contains(fn (array $p) => $p['available'] && $p['product_type'] === 'data_bundle');
        $hasMashup = collect($flat)->contains(fn (array $p) => $p['available'] && $p['product_type'] === 'mashup');
        $down = 'The provider is unavailable right now.';

        $services = [
            $this->serviceRow('data', 'data_bundle', $hasData, $reachable ? null : $down),
            $this->serviceRow('mashup', 'mashup', $hasMashup, $reachable ? null : $down),
            $this->serviceRow('airtime', 'airtime', true),
            $this->serviceRow('ecg', 'ecg', true),
            $this->serviceRow('tv', 'tv', true),
            $this->serviceRow('results', 'results', true),
        ];

        return $this->result(false, 'Utility catalog loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'currency' => config('payments.currency', 'GHS'),
            'provider_reachable' => $reachable,
            'services' => $services,
            'categories' => $categories === [] ? new \stdClass : $categories,
            'networks' => $networks,
            'plans' => $flat,
            'airtime' => $this->airtimeCatalog($admin),
            'tv' => $this->tvCatalog($admin),
            'ecg' => $this->ecgCatalog($admin),
            'results' => $this->resultsCatalog($admin),
        ]);
    }

    /**
     * What a client can pick as a campaign/survey reward. Selling prices only.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function rewardCatalog(): array
    {
        $plans = $this->catalog->plans();
        $categories = [];
        $flat = [];

        foreach ($plans ?? [] as $plan) {
            if (! $plan['available'] || $plan['product_type'] !== 'data_bundle') {
                continue;
            }

            $item = $this->presentPlan($plan, false);
            $flat[] = $item;
            $categories[$plan['network']][] = $item;
        }

        return $this->result(false, 'Rewards catalog loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'currency' => config('payments.currency', 'GHS'),
            'provider_reachable' => $plans !== null,
            'data' => [
                'categories' => $categories === [] ? new \stdClass : $categories,
                'plans' => $flat,
            ],
            'airtime' => $this->airtimeCatalog(false) + ['presets' => [1, 2, 5, 10, 20, 50]],
            'money' => [
                'currency' => config('payments.currency', 'GHS'),
                'method' => 'mobile_money',
            ],
        ]);
    }

    /**
     * Swift account balance (admin only).
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function providerBalance(): array
    {
        $response = $this->swift->balance();

        if (! $response->ok) {
            return $this->result(true, 'The provider balance could not be loaded.', ApiCode::UNAVAILABLE, ApiReason::PROVIDER_UNAVAILABLE, [
                'detail' => $response->error,
            ]);
        }

        $body = $response->body;
        $balance = $body['balance'] ?? ($body['data']['balance'] ?? null);

        return $this->result(false, 'Provider balance loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'balance' => is_numeric($balance) ? (float) $balance : null,
            'currency' => $body['currency'] ?? 'GHS',
            'details' => $body,
            'checked_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Customer-name lookup for ECG meters and TV smartcards before paying.
     *
     * @param  array<string, mixed>  $input  service (ecg|tv), beneficiary, bill_type (tv)
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function lookup(string $service, array $input): array
    {
        $number = $this->cleanAccount((string) ($input['beneficiary'] ?? ''));

        if ($number === null) {
            return $this->invalid(['beneficiary' => ['Enter a valid '.($service === 'ecg' ? 'meter / account' : 'smartcard').' number.']]);
        }

        if ($service === 'ecg') {
            $response = $this->swift->ecgLookup(['accountNumber' => $number]);
        } elseif ($service === 'tv') {
            $billType = $this->billType((string) ($input['bill_type'] ?? ''));

            if (! $billType) {
                return $this->invalid(['bill_type' => ['Allowed: '.implode(', ', array_column((array) config('swift.tv_bill_types'), 'code'))]]);
            }

            $response = $this->swift->validateBill(['customerNumber' => $number, 'billType' => $billType['code']]);
        } else {
            return $this->invalid(['service' => ['Lookup is only available for ecg and tv.']]);
        }

        if (! $response->ok) {
            return $this->result(true, $this->reason($response->error, $response->httpStatus), ApiCode::BAD_REQUEST, ApiReason::PROVIDER_UNAVAILABLE);
        }

        return $this->result(false, 'Account verified.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'service' => $service,
            'beneficiary' => $number,
            'customer_name' => $response->body['customerName'] ?? null,
            'validated_amount' => isset($response->body['validatedAmount']) ? (float) $response->body['validatedAmount'] : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Quotes and checkout
    |--------------------------------------------------------------------------
    */

    /**
     * Price breakdown for one item, or for `items[]`. Clients never see cost or profit.
     *
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function quote(array $input, bool $admin = false): array
    {
        $specs = $this->itemsFrom($input);

        if ($this->failed($specs)) {
            return $specs;
        }

        $prices = $this->priceAll($specs, false);

        if ($this->failed($prices)) {
            return $prices;
        }

        $items = array_map(fn (array $price) => $this->presentPrice($price, $admin), $prices);
        $total = round(array_sum(array_column($prices, 'sell')), 2);

        if (! isset($input['items'])) {
            return $this->result(false, 'Quote ready.', ApiCode::SUCCESS, ApiReason::SUCCESS, $items[0]);
        }

        $data = ['count' => count($items), 'amount' => $total, 'currency' => config('payments.currency', 'GHS'), 'items' => $items];

        if ($admin) {
            $data['cost'] = round(array_sum(array_column($prices, 'cost')), 2);
            $data['profit'] = round($total - $data['cost'], 2);
        }

        return $this->result(false, 'Quote ready.', ApiCode::SUCCESS, ApiReason::SUCCESS, $data);
    }

    /**
     * What CheckoutService needs to charge for a utility purpose (momo/card). Prices are rebuilt
     * from the request fields, never trusted from what the client sent back.
     *
     * @param  array<string, mixed>  $spec
     * @return array{name: string, amount: float, meta: array<string, mixed>}|string|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function checkoutItem(string $purpose, array $spec): array|string
    {
        if ($purpose === CheckoutService::UTILITY_BULK) {
            $items = $spec['items'] ?? null;

            if (! is_array($items) || $items === [] || count($items) > $this->bulkMax()) {
                return 'Add between 1 and '.$this->bulkMax().' items.';
            }

            $prices = $this->priceAll(array_map(fn ($item) => $this->specOf((array) $item), array_values($items)), true);

            if ($this->failed($prices)) {
                return $prices;
            }

            return [
                'name' => 'Utility purchase ('.count($prices).' items)',
                'amount' => round(array_sum(array_column($prices, 'sell')), 2),
                'meta' => ['utility' => ['items' => $prices]],
            ];
        }

        $service = self::serviceForPurpose($purpose);

        if (! $service) {
            return 'This purchase type is not available yet.';
        }

        $price = $this->price($service, $this->specOf($spec) + ['service' => $service], true);

        if ($this->failed($price)) {
            return $price;
        }

        if ($price['order_type'] !== $purpose) {
            return 'This purchase type does not match the selected provider.';
        }

        return ['name' => $price['name'], 'amount' => $price['sell'], 'meta' => ['utility' => $price]];
    }

    /**
     * Buy now. One item or `items[]` (max 50). Wallet: debit the available balance once, create an
     * order per item and send them to Swift. Momo/card: start one checkout; the orders are created
     * and sent when that payment completes.
     *
     * @param  array<string, mixed>  $input  item fields (or items[]) + method, phone_number, momo_network
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function purchase(Client $client, array $input): array
    {
        $method = (string) ($input['method'] ?? 'wallet');

        if (! in_array($method, self::METHODS, true)) {
            return $this->invalid(['method' => ['Allowed: '.implode(', ', self::METHODS)]]);
        }

        $bulk = isset($input['items']);
        $specs = $this->itemsFrom($input);

        if ($this->failed($specs)) {
            return $specs;
        }

        foreach ($specs as $i => $spec) {
            $specs[$i]['_client_phone'] = $client->phone_number;
        }

        $prices = $this->priceAll($specs, true);

        if ($this->failed($prices)) {
            return $prices;
        }

        $total = round(array_sum(array_column($prices, 'sell')), 2);

        if ($method !== 'wallet') {
            return $this->checkout->start($client, $bulk ? CheckoutService::UTILITY_BULK : $prices[0]['order_type'], [
                'method' => $method,
                'phone_number' => $input['phone_number'] ?? null,
                'network' => $input['momo_network'] ?? null,
                'utility' => $bulk ? ['items' => $prices] : $prices[0],
            ]);
        }

        $batchId = $bulk && count($prices) > 1 ? 'BLK-'.strtoupper(Str::random(10)) : null;

        try {
            $orders = DB::transaction(function () use ($client, $prices, $total, $batchId, $bulk) {
                $label = $bulk ? 'Utility purchase ('.count($prices).' items)' : $prices[0]['name'];

                if (! $this->wallets->debit($client, $total, $bulk ? CheckoutService::UTILITY_BULK : $prices[0]['order_type'], null, $label.' '.($batchId ?? ''))) {
                    throw new InsufficientWallet;
                }

                $created = [];

                foreach ($prices as $price) {
                    $created[] = $this->createOrder($client, $price, $this->newReference(), 'wallet', null, $batchId);
                }

                return $created;
            }, 3);
        } catch (InsufficientWallet) {
            $wallet = $this->wallets->forClient($client);

            return $this->result(true, 'Insufficient available wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'balance' => (float) $wallet->balance,
                'reserved' => $this->wallets->reservedTotal($client->id),
                'available' => $this->wallets->available($client),
                'required' => $total,
            ]);
        }

        $this->activity()->record('Utility purchase', 'utility', 'created', 'client', $client, $client, [
            'utility_order_ids' => array_map(fn (UtilityOrder $o) => $o->id, $orders),
            'batch_id' => $batchId,
            'items' => count($orders),
            'amount' => $total,
            'method' => 'wallet',
        ]);

        if (count($orders) > 1) {
            foreach ($orders as $order) {
                ProcessUtilityOrderJob::dispatch($order->id);
            }

            return $this->result(false, 'Purchase received. '.count($orders).' orders are being processed.', ApiCode::CREATED, ApiReason::CREATED, [
                'batch_id' => $batchId,
                'count' => count($orders),
                'amount' => $total,
                'currency' => config('payments.currency', 'GHS'),
                'orders' => array_map(fn (UtilityOrder $o) => $this->present($o), $orders),
            ]);
        }

        $order = $this->process($orders[0]);

        if ($order->status === 'failed') {
            return $this->result(true, 'The purchase could not be completed. You have been refunded.', ApiCode::UNAVAILABLE, ApiReason::PROVIDER_UNAVAILABLE, $this->present($order));
        }

        $message = $order->status === 'completed' ? 'Purchase completed.' : 'Purchase received and is being processed.';

        return $this->result(false, $message, ApiCode::CREATED, ApiReason::CREATED, $this->present($order));
    }

    /**
     * Called when a gateway payment for a utility purpose is confirmed. Creates the order(s) once
     * and queues them for the provider (idempotent per payment).
     *
     * @param  array<string, mixed>  $spec  the price breakdown saved at checkout (single) or ['items' => [...]]
     * @return list<UtilityOrder>
     */
    public function queueFromPayment(Payment $payment, array $spec): array
    {
        $existing = UtilityOrder::query()->where('payment_id', $payment->id)->get();

        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        $client = Client::query()->findOrFail($payment->client_id);
        $bulk = isset($spec['items']) && is_array($spec['items']);
        $prices = $bulk ? array_values($spec['items']) : [$spec];
        $batchId = $bulk && count($prices) > 1 ? 'PAY-'.$payment->id : null;
        $orders = [];

        foreach ($prices as $price) {
            $orders[] = $this->createOrder($client, (array) $price, $this->newReference(), (string) $payment->method, $payment, $batchId, ['bulk' => $bulk]);
        }

        foreach ($orders as $order) {
            ProcessUtilityOrderJob::dispatch($order->id)->afterCommit();
        }

        return $orders;
    }

    /**
     * Rewards (campaign/survey airtime and data): order funded from a wallet hold, so no wallet
     * debit or refund here — RewardDisbursementService consumes the hold when the order completes.
     *
     * @param  array<string, mixed>  $snapshot  reward snapshot (type, network, package_id, amount, unit_cost)
     * @return UtilityOrder|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function createRewardOrder(Client $client, array $snapshot, string $phone, string $reference): UtilityOrder|array
    {
        $existing = UtilityOrder::query()->where('reference', $reference)->first();

        if ($existing) {
            return $existing;
        }

        if (($snapshot['type'] ?? null) === 'data') {
            $price = $this->price('data', ['package_id' => $snapshot['package_id'] ?? $snapshot['bundle_id'] ?? '', 'beneficiary' => $phone], true);
        } else {
            $price = $this->price('airtime', ['network' => $snapshot['network'] ?? '', 'amount' => $snapshot['amount'] ?? 0, 'beneficiary' => $phone], true);
        }

        if ($this->failed($price)) {
            return $price;
        }

        // What the client funded for this recipient is the selling price; cost is whatever Swift charges now.
        $funded = round((float) ($snapshot['unit_cost'] ?? $snapshot['amount'] ?? $price['sell']), 2);
        $price['sell'] = $funded;
        $price['amount'] = $funded;
        $price['profit'] = round($funded - $price['cost'], 2);

        return $this->createOrder($client, $price, $reference, 'reward', null, null, ['reward' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Provider calls
    |--------------------------------------------------------------------------
    */

    /**
     * Send a pending order to the provider exactly once.
     */
    public function process(UtilityOrder $order): UtilityOrder
    {
        $claimed = DB::transaction(function () use ($order) {
            $locked = UtilityOrder::query()->lockForUpdate()->find($order->id);
            $meta = $locked?->utility_meta ?? [];

            if (! $locked || $locked->status !== 'pending' || ! empty($meta['submitted_at'])) {
                return null;
            }

            $meta['submitted_at'] = now()->toDateTimeString();
            $locked->update(['utility_meta' => $meta]);

            return $locked->fresh();
        }, 3);

        if (! $claimed) {
            return $order->fresh();
        }

        $response = $this->send($claimed);
        $this->apply($claimed, $response, true);

        return $claimed->fresh();
    }

    /**
     * Refresh a pending order from the provider.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function status(Client $client, UtilityOrder $order): array
    {
        if ($order->status === 'pending') {
            $order = $this->refresh($order);
        }

        return $this->result(false, 'Order loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($order));
    }

    /**
     * Not sent yet → send it. Sent and waiting → ask Swift what happened.
     */
    public function refresh(UtilityOrder $order): UtilityOrder
    {
        if ($order->status !== 'pending') {
            return $order;
        }

        $meta = $order->utility_meta ?? [];

        if (empty($meta['submitted_at'])) {
            return $this->process($order);
        }

        $this->reconcile($order);

        return $order->fresh();
    }

    /**
     * Swift's order webhook: order.fulfilled / order.failed. Always re-reads our own order, so a
     * replayed or forged-but-signed event can only move a pending order to the state Swift reports.
     *
     * @param  array<string, mixed>  $payload
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function handleWebhook(array $payload): array
    {
        $orderId = isset($payload['order_id']) && is_scalar($payload['order_id']) ? trim((string) $payload['order_id']) : '';
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';

        if ($orderId === '') {
            return $this->result(true, 'order_id is required.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR);
        }

        $order = UtilityOrder::query()->where('provider_reference', $orderId)->first();

        if (! $order) {
            return $this->result(false, 'No matching order.', ApiCode::SUCCESS, ApiReason::NOT_FOUND, ['order_id' => $orderId]);
        }

        $state = match ($event) {
            'order.fulfilled' => 'completed',
            'order.failed' => 'failed',
            default => $this->mapStatus($payload['status'] ?? null, []),
        };

        $order->update(['provider_response' => $payload + ['webhook_received_at' => now()->toDateTimeString()]]);

        if ($state === 'completed') {
            $this->complete($order);
        } elseif ($state === 'failed') {
            $this->failAndRefund($order, $this->reason($payload['message'] ?? $payload['error'] ?? null), $payload);
        }

        return $this->result(false, 'Webhook processed.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'order_id' => $orderId,
            'status' => $order->fresh()->status,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Orders and presentation
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters  status, service, product_type, batch_id, funding_method, client_id, from, to
     */
    public function orders(?Client $client = null, array $filters = []): Builder
    {
        $query = UtilityOrder::query()->latest('id');

        if ($client) {
            $query->where('client_id', $client->id);
        } elseif (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }

        foreach (['status', 'product_type', 'batch_id', 'funding_method'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['service'])) {
            $query->where('service_type', $filters['service']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }

    /**
     * Client view: selling price only, no provider internals.
     *
     * @return array<string, mixed>
     */
    public function present(UtilityOrder $order): array
    {
        $meta = $order->utility_meta ?? [];

        return [
            'id' => $order->id,
            'resource' => 'utility_order',
            'reference' => $order->reference,
            'batch_id' => $order->batch_id,
            'service' => $order->service_type,
            'product_type' => $order->product_type,
            'order_type' => $meta['order_type'] ?? null,
            'name' => $meta['name'] ?? null,
            'network' => $order->network,
            'beneficiary' => $order->beneficiary,
            'package_id' => $order->package_code,
            'size' => $meta['size'] ?? null,
            'size_gb' => $order->size_gb !== null ? (float) $order->size_gb : null,
            'provider_code' => $order->provider_code,
            'quantity' => $meta['quantity'] ?? null,
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'status' => $order->status,
            'funding_method' => $order->funding_method,
            'payment_id' => $order->payment_id,
            'order_id' => $order->order_id,
            'failure_reason' => $order->failure_reason,
            'refunded' => ! empty($meta['refunded_at']),
            'vouchers' => $order->status === 'completed' ? ($meta['vouchers'] ?? null) : null,
            'completed_at' => optional($order->completed_at)->toDateTimeString(),
            'created_at' => optional($order->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForAdmin(UtilityOrder $order): array
    {
        return $this->present($order) + [
            'client_id' => $order->client_id,
            'cost_amount' => (float) $order->cost_amount,
            'sell_amount' => (float) $order->sell_amount,
            'profit_amount' => (float) $order->profit_amount,
            'provider_reference' => $order->provider_reference,
            'utility_meta' => $order->utility_meta,
            'provider_payload' => $order->provider_payload,
            'provider_response' => $order->provider_response,
        ];
    }

    /**
     * Profit on completed utility orders (including reward purchases).
     *
     * @param  array<string, mixed>  $filters  from, to, product_type, client_id, funding_method
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function profitReport(array $filters = []): array
    {
        $base = fn () => $this->orders(null, $filters)->reorder();

        $sum = fn (Builder $q) => $q->selectRaw('COUNT(*) as orders, COALESCE(SUM(cost_amount),0) as cost, COALESCE(SUM(sell_amount),0) as sell, COALESCE(SUM(profit_amount),0) as profit');

        $totals = $sum($base()->where('status', 'completed'))->first();

        $byProduct = $sum($base()->where('status', 'completed')->addSelect('product_type')->groupBy('product_type'))
            ->orderBy('product_type')
            ->get()
            ->map(fn ($row) => $this->profitRow($row) + ['product_type' => $row->product_type])
            ->values()
            ->all();

        $byFunding = $sum($base()->where('status', 'completed')->addSelect('funding_method')->groupBy('funding_method'))
            ->get()
            ->map(fn ($row) => $this->profitRow($row) + ['funding_method' => $row->funding_method])
            ->values()
            ->all();

        $statuses = $base()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->result(false, 'Utility profit loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'currency' => config('payments.currency', 'GHS'),
            'totals' => $this->profitRow($totals),
            'by_product_type' => $byProduct,
            'by_funding_method' => $byFunding,
            'orders_by_status' => $statuses,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $price
     * @return array<string, mixed>
     */
    public function presentPrice(array $price, bool $admin = false): array
    {
        $out = [
            'service' => $price['service'],
            'order_type' => $price['order_type'],
            'product_type' => $price['product_type'],
            'name' => $price['name'],
            'network' => $price['network'],
            'package_id' => $price['package_id'],
            'size' => $price['size'],
            'bill_type' => $price['bill_type'],
            'checker_type' => $price['checker_type'],
            'quantity' => $price['quantity'],
            'beneficiary' => $price['beneficiary'],
            'amount' => $price['sell'],
            'currency' => $price['currency'],
        ];

        // Airtime, ECG and TV deliver the amount the client typed, so showing it reveals nothing.
        if (in_array($price['service'], ['airtime', 'ecg', 'tv'], true)) {
            $out['face_amount'] = $price['face_amount'];
        }

        if ($admin) {
            $out += ['cost' => $price['cost'], 'profit' => $price['profit'], 'markup' => $price['markup']];
        }

        return $out;
    }

    /**
     * Validated price breakdown, or an error result.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function price(string $service, array $input, bool $needBeneficiary): array
    {
        $service = (string) ($input['service'] ?? $service);

        if (! array_key_exists($service, (array) config('swift.services', []))) {
            return $this->result(true, 'Unknown utility service.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $fields = [
            'network' => null,
            'package_id' => null,
            'size' => null,
            'size_gb' => null,
            'bill_type' => null,
            'checker_type' => null,
            'quantity' => null,
            'provider_code' => null,
            'face_amount' => null,
            'account_name' => null,
            'customer_phone' => null,
        ];
        $marginNetwork = null;
        $marginPackage = null;

        switch ($service) {
            case 'data':
            case 'mashup':
                $packageId = trim((string) ($input['package_id'] ?? $input['package_code'] ?? ''));

                if ($packageId === '') {
                    return $this->invalid(['package_id' => ['Choose a data package.']]);
                }

                if ($this->catalog->plans() === null) {
                    return $this->unavailable('The provider is unavailable right now. Try again shortly.');
                }

                $plan = $this->catalog->plan($packageId);

                if (! $plan || ! $plan['available'] || ($service === 'mashup' && $plan['product_type'] !== 'mashup')) {
                    return $this->invalid(['package_id' => ['That package is not available.']]);
                }

                $productType = $plan['product_type'];
                $orderType = $productType === 'mashup' ? 'mashup' : 'data_bundle';
                $cost = $plan['cost'];
                $marginNetwork = $plan['network'];
                $marginPackage = $plan['package_id'];
                $name = trim($plan['network'].' '.$plan['size']);
                $fields = array_merge($fields, [
                    'network' => $plan['network'],
                    'package_id' => $plan['package_id'],
                    'size' => $plan['size'],
                    'size_gb' => $plan['size_gb'],
                    'face_amount' => $cost,
                ]);
                break;

            case 'airtime':
                $network = $this->airtimeNetwork((string) ($input['network'] ?? ''));
                $amount = round((float) ($input['amount'] ?? 0), 2);
                $min = (float) config('swift.airtime_limits.min', 1);
                $max = (float) config('swift.airtime_limits.max', 500);

                if (! $network) {
                    return $this->invalid(['network' => ['Allowed: '.implode(', ', array_column((array) config('swift.airtime_networks'), 'code'))]]);
                }

                if ($amount < $min || $amount > $max) {
                    return $this->invalid(['amount' => ["Amount must be between {$min} and {$max}."]]);
                }

                $productType = 'airtime';
                $orderType = 'airtime';
                $cost = $amount;
                $marginNetwork = $network['code'];
                $name = $network['name'].' airtime';
                $fields = array_merge($fields, ['network' => $network['code'], 'provider_code' => $network['code'], 'face_amount' => $amount]);
                break;

            case 'ecg':
                $amount = round((float) ($input['amount'] ?? 0), 2);
                $min = (float) config('swift.bill_limits.min', 1);
                $max = (float) config('swift.bill_limits.max', 5000);

                if ($amount < $min || $amount > $max) {
                    return $this->invalid(['amount' => ["Amount must be between {$min} and {$max}."]]);
                }

                $productType = 'ecg';
                $orderType = 'ecg';
                $cost = $amount;
                $name = 'ECG payment';
                $fields = array_merge($fields, ['face_amount' => $amount]);
                break;

            case 'tv':
                $billType = $this->billType((string) ($input['bill_type'] ?? $input['provider_code'] ?? ''));
                $amount = round((float) ($input['amount'] ?? 0), 2);
                $min = (float) config('swift.bill_limits.min', 1);
                $max = (float) config('swift.bill_limits.max', 5000);

                if (! $billType) {
                    return $this->invalid(['bill_type' => ['Allowed: '.implode(', ', array_column((array) config('swift.tv_bill_types'), 'code'))]]);
                }

                if ($amount < $min || $amount > $max) {
                    return $this->invalid(['amount' => ["Amount must be between {$min} and {$max}."]]);
                }

                $productType = 'tv';
                $orderType = $billType['order_type'];
                $cost = $amount;
                $marginNetwork = $billType['code'];
                $name = $billType['name'].' payment';
                $fields = array_merge($fields, ['bill_type' => $billType['code'], 'provider_code' => $billType['code'], 'face_amount' => $amount]);
                break;

            default: // results
                $checker = $this->checker((string) ($input['checker_type'] ?? ''));
                $quantity = (int) ($input['quantity'] ?? 1);
                $maxQty = (int) config('swift.results_max_quantity', 100);

                if (! $checker) {
                    return $this->invalid(['checker_type' => ['Allowed: '.implode(', ', array_column((array) config('swift.results_checkers'), 'type'))]]);
                }

                if ($quantity < 1 || $quantity > $maxQty) {
                    return $this->invalid(['quantity' => ["Quantity must be between 1 and {$maxQty}."]]);
                }

                $productType = 'results';
                $orderType = 'results';
                $cost = round((float) $checker['unit_cost'] * $quantity, 2);
                $marginNetwork = $checker['type'];
                $name = $checker['name'].($quantity > 1 ? " x{$quantity}" : '');
                $fields = array_merge($fields, [
                    'checker_type' => $checker['type'],
                    'provider_code' => $checker['type'],
                    'quantity' => $quantity,
                    'face_amount' => $cost,
                ]);
                break;
        }

        $beneficiary = null;

        if ($needBeneficiary) {
            $beneficiary = $this->beneficiary($service, $input);

            if (is_array($beneficiary)) {
                return $beneficiary;
            }

            if ($service === 'ecg') {
                $phone = GhanaPhone::toMobile((string) ($input['customer_phone'] ?? $input['_client_phone'] ?? ''));

                if (! $phone) {
                    return $this->invalid(['customer_phone' => ['Enter the customer\'s Ghana mobile number for ECG payments.']]);
                }

                $fields['customer_phone'] = $phone;
            }

            $fields['account_name'] = isset($input['account_name']) && trim((string) $input['account_name']) !== ''
                ? mb_substr(trim((string) $input['account_name']), 0, 120)
                : null;
        }

        $priced = $this->margins->priceForClient($cost, $productType, $marginNetwork, $marginPackage);

        return array_merge($fields, [
            'service' => $service,
            'order_type' => $orderType,
            'product_type' => $productType,
            'name' => $name,
            'beneficiary' => $beneficiary,
            'cost' => $priced['cost'],
            'sell' => $priced['sell'],
            'profit' => $priced['profit'],
            'markup' => $priced['markup'],
            'amount' => $priced['sell'],
            'currency' => config('payments.currency', 'GHS'),
            'input' => array_filter([
                'service' => $service,
                'package_id' => $fields['package_id'],
                'network' => $service === 'airtime' ? $fields['network'] : null,
                'bill_type' => $fields['bill_type'],
                'checker_type' => $fields['checker_type'],
                'quantity' => $fields['quantity'],
                'amount' => in_array($service, ['airtime', 'ecg', 'tv'], true) ? $cost : null,
                'beneficiary' => $beneficiary,
                'account_name' => $fields['account_name'],
                'customer_phone' => $fields['customer_phone'],
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * Prices every spec; the first failure is returned with its index in the error keys.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<array<string, mixed>>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function priceAll(array $specs, bool $needBeneficiary): array
    {
        $prices = [];

        foreach ($specs as $i => $spec) {
            $price = $this->price((string) ($spec['service'] ?? ''), $spec, $needBeneficiary);

            if ($this->failed($price)) {
                if (count($specs) > 1 && $price['reason'] === ApiReason::VALIDATION_ERROR) {
                    $errors = [];

                    foreach ($price['data'] as $field => $messages) {
                        $errors["items.{$i}.{$field}"] = $messages;
                    }

                    $price['data'] = $errors;
                } elseif (count($specs) > 1) {
                    $price['message'] = 'Item '.($i + 1).': '.$price['message'];
                }

                return $price;
            }

            $prices[] = $price;
        }

        return $prices;
    }

    /**
     * One item (the request itself) or items[], max bulk size.
     *
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function itemsFrom(array $input): array
    {
        if (! isset($input['items'])) {
            return [$input];
        }

        $items = $input['items'];

        if (! is_array($items) || $items === [] || count($items) > $this->bulkMax()) {
            return $this->invalid(['items' => ['Add between 1 and '.$this->bulkMax().' items.']]);
        }

        return array_values(array_map(fn ($item) => is_array($item) ? $item : [], $items));
    }

    /**
     * The fields price() needs: the saved `input` when present, otherwise the spec as given.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    protected function specOf(array $spec): array
    {
        return is_array($spec['input'] ?? null) ? $spec['input'] : $spec;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return string|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function beneficiary(string $service, array $input): string|array
    {
        $raw = (string) ($input['beneficiary'] ?? $input['phone'] ?? $input['meter'] ?? $input['smartcard'] ?? '');

        if (in_array($service, ['data', 'mashup', 'airtime', 'results'], true)) {
            $phone = GhanaPhone::toMobile($raw);

            return $phone ?? $this->invalid(['beneficiary' => ['Enter a valid Ghana mobile number.']]);
        }

        $clean = $this->cleanAccount($raw);
        $label = $service === 'ecg' ? 'meter / account number' : 'smartcard number';

        return $clean ?? $this->invalid(['beneficiary' => ["Enter a valid {$label}."]]);
    }

    protected function cleanAccount(string $raw): ?string
    {
        $clean = preg_replace('/[\s-]+/', '', $raw) ?? '';

        return preg_match('/^[A-Za-z0-9]{5,30}$/', $clean) ? $clean : null;
    }

    /**
     * @return array{code: string, name: string}|null
     */
    protected function airtimeNetwork(string $value): ?array
    {
        $key = $this->key($value);
        $key = match ($key) {
            'vodafone', 'vod' => 'telecel',
            'airteltigo', 'airtel', 'tigo', 'attigo' => 'at',
            default => $key,
        };

        foreach ((array) config('swift.airtime_networks', []) as $network) {
            if ($this->key($network['code']) === $key) {
                return $network;
            }
        }

        return null;
    }

    /**
     * @return array{code: string, name: string, order_type: string}|null
     */
    protected function billType(string $value): ?array
    {
        $key = $this->key($value);

        foreach ((array) config('swift.tv_bill_types', []) as $type) {
            if ($this->key($type['code']) === $key || $this->key($type['order_type']) === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, name: string, unit_cost: float}|null
     */
    protected function checker(string $value): ?array
    {
        $key = $this->key($value);

        foreach ((array) config('swift.results_checkers', []) as $checker) {
            if ($this->key($checker['type']) === $key) {
                return $checker;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $price
     * @param  array<string, mixed>  $metaExtra
     */
    protected function createOrder(Client $client, array $price, string $reference, string $method, ?Payment $payment = null, ?string $batchId = null, array $metaExtra = []): UtilityOrder
    {
        $sell = round((float) $price['sell'], 2);
        $cost = round((float) $price['cost'], 2);

        return UtilityOrder::create([
            'client_id' => $client->id,
            'payment_id' => $payment?->id,
            'order_id' => $payment?->order_id,
            'service_type' => $price['service'],
            'product_type' => $price['product_type'],
            'provider_code' => $price['provider_code'] ?? null,
            'network' => $price['network'],
            'beneficiary' => (string) $price['beneficiary'],
            'size_gb' => $price['size_gb'],
            'package_code' => $price['package_id'],
            'face_amount' => $price['face_amount'],
            'amount' => $sell,
            'cost_amount' => $cost,
            'sell_amount' => $sell,
            'profit_amount' => round($sell - $cost, 2),
            'currency' => $price['currency'] ?? 'GHS',
            'reference' => $reference,
            'batch_id' => $batchId,
            'status' => 'pending',
            'funding_method' => $method,
            'utility_meta' => $metaExtra + [
                'order_type' => $price['order_type'],
                'name' => $price['name'],
                'size' => $price['size'] ?? null,
                'quantity' => $price['quantity'] ?? null,
                'account_name' => $price['account_name'] ?? null,
                'customer_phone' => $price['customer_phone'] ?? null,
                'markup' => $price['markup'] ?? null,
            ],
        ]);
    }

    protected function send(UtilityOrder $order): SwiftResponse
    {
        $meta = $order->utility_meta ?? [];
        $face = (float) $order->face_amount;
        $local = $this->localPhone($order->beneficiary);

        switch ($order->service_type) {
            case 'data':
            case 'mashup':
                $payload = ['package_id' => $order->package_code, 'phone' => $local];
                break;
            case 'airtime':
                $payload = ['network' => $order->provider_code, 'phone' => $local, 'amount' => $face];
                break;
            case 'ecg':
                $payload = ['phoneNumber' => $meta['customer_phone'] ?? '', 'accountNumber' => $order->beneficiary, 'amount' => $face];
                break;
            case 'tv':
                $payload = [
                    'customerNumber' => $order->beneficiary,
                    'billType' => $order->provider_code,
                    'amount' => $face,
                    'senderName' => $this->senderName($order),
                ];
                break;
            default:
                $payload = [
                    'checker_type' => $order->provider_code,
                    'customer_phone' => $local,
                    'quantity' => (int) ($meta['quantity'] ?? 1),
                    'amount' => $face,
                ];
        }

        // The order reference doubles as Swift's idempotency key.
        $payload['request_id'] = $order->reference;
        $order->update(['provider_payload' => $payload]);

        return match ($order->service_type) {
            'data', 'mashup' => $this->swift->buyData($payload),
            'airtime' => $this->swift->buyAirtime($payload),
            'ecg' => $this->swift->payEcg($payload),
            'tv' => $this->swift->payBill($payload),
            default => $this->swift->resultsChecker($payload),
        };
    }

    /**
     * TV payments need the account holder's name: use the one given, else ask Swift, else our client's name.
     */
    protected function senderName(UtilityOrder $order): string
    {
        $given = trim((string) ($order->utility_meta['account_name'] ?? ''));

        if ($given !== '') {
            return mb_strtoupper($given);
        }

        $lookup = $this->swift->validateBill(['customerNumber' => $order->beneficiary, 'billType' => $order->provider_code]);

        if ($lookup->ok && ! empty($lookup->body['customerName'])) {
            return mb_strtoupper((string) $lookup->body['customerName']);
        }

        $client = Client::query()->find($order->client_id);

        return mb_strtoupper(trim(($client?->first_name ?? '').' '.($client?->last_name ?? ''))) ?: 'CUSTOMER';
    }

    /**
     * Turn Swift's answer into an order state.
     */
    protected function apply(UtilityOrder $order, SwiftResponse $response, bool $fromBuy): void
    {
        if ($response->ok) {
            $remote = ($response->order() ?? []) + $response->body;
            $status = $this->mapStatus($remote['status'] ?? null, $response->body);
            $reference = $this->pick($remote, ['order_id', 'id', 'transaction_id']);

            $order->update([
                'provider_reference' => $reference ? mb_substr((string) $reference, 0, 255) : $order->provider_reference,
                'provider_response' => $response->body,
            ]);

            if ($status === 'completed') {
                $this->complete($order, $this->vouchers($response->body));
            } elseif ($status === 'failed') {
                $this->failAndRefund($order, $this->reason($remote['message'] ?? $remote['error'] ?? null), $response->body);
            }

            return;
        }

        // 4xx (or a missing key) means Swift did not take the order. 5xx / timeouts are unknown: keep pending.
        $definite = ($response->httpStatus >= 400 && $response->httpStatus < 500)
            || str_starts_with((string) $response->error, 'Swift API key');

        if ($definite && $fromBuy) {
            $this->failAndRefund($order, $this->reason($response->error, $response->httpStatus), ['error' => $response->error, 'http_status' => $response->httpStatus] + $response->body);

            return;
        }

        if ($fromBuy) {
            $order->update(['provider_response' => ['error' => $response->error, 'http_status' => $response->httpStatus] + $response->body]);
        }
    }

    /**
     * Ask Swift what happened to an order we already sent.
     */
    protected function reconcile(UtilityOrder $order): void
    {
        if (! $order->provider_reference) {
            return;
        }

        $response = $this->swift->orderStatus((string) $order->provider_reference);

        if ($response->ok) {
            $this->apply($order, $response, false);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $vouchers
     */
    protected function complete(UtilityOrder $order, array $vouchers = []): void
    {
        $done = DB::transaction(function () use ($order, $vouchers) {
            $locked = UtilityOrder::query()->lockForUpdate()->find($order->id);

            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $meta = $locked->utility_meta ?? [];

            if ($vouchers !== []) {
                $meta['vouchers'] = $vouchers;
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'failure_reason' => null,
                'utility_meta' => $meta,
            ]);

            return true;
        }, 3);

        if ($done) {
            $this->notifyReward($order);
        }
    }

    /**
     * Mark failed and give the money back to the wallet, once. Reward orders were funded from a
     * hold that is only consumed on success, so there is nothing to refund.
     *
     * @param  array<string, mixed>|null  $raw
     */
    protected function failAndRefund(UtilityOrder $order, string $reason, ?array $raw = null): void
    {
        $changed = DB::transaction(function () use ($order, $reason, $raw) {
            $locked = UtilityOrder::query()->lockForUpdate()->find($order->id);

            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $meta = $locked->utility_meta ?? [];
            $refund = $locked->funding_method === 'reward' ? 0.0 : $this->refundAmount($locked, $meta);

            if (empty($meta['refunded_at']) && $refund > 0) {
                $this->wallets->credit(
                    Client::query()->findOrFail($locked->client_id),
                    $refund,
                    'utility_refund',
                    $locked->payment_id ? Payment::query()->find($locked->payment_id) : null,
                    'Refund '.$locked->reference,
                );
                $meta['refunded_at'] = now()->toDateTimeString();
            }

            $locked->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($reason, 0, 255),
                'completed_at' => now(),
                'utility_meta' => $meta,
                'provider_response' => $raw ?? $locked->provider_response,
            ]);

            return true;
        }, 3);

        if ($changed) {
            $this->notifyReward($order);
        }
    }

    /**
     * Wallet purchases refund the selling price. Gateway-paid single orders refund everything
     * collected (including the gateway charge); bulk orders get their share of it.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function refundAmount(UtilityOrder $order, array $meta): float
    {
        $payment = $order->payment_id ? Payment::query()->find($order->payment_id) : null;

        if (! $payment) {
            return (float) $order->amount;
        }

        if (empty($meta['bulk'])) {
            return (float) $payment->total_amount;
        }

        $paid = (float) $payment->amount;
        $share = $paid > 0 ? round((float) $payment->charge * (float) $order->amount / $paid, 2) : 0.0;

        return round((float) $order->amount + $share, 2);
    }

    protected function notifyReward(UtilityOrder $order): void
    {
        if ($order->funding_method === 'reward') {
            app(RewardDisbursementService::class)->syncFromUtilityOrder($order->fresh());
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function mapStatus(mixed $status, array $body): string
    {
        $status = strtolower(trim((string) $status));

        if ($status === '') {
            // Bill payments answer synchronously with a transaction id; results checkers with vouchers.
            return (! empty($body['transaction_id']) || ! empty($body['vouchers'])) ? 'completed' : 'pending';
        }

        return match (true) {
            in_array($status, ['fulfilled', 'completed', 'complete', 'success', 'successful', 'delivered', 'done'], true) => 'completed',
            in_array($status, ['fulfillment_failed', 'failed', 'failure', 'error', 'cancelled', 'canceled', 'refunded', 'rejected'], true) => 'failed',
            default => 'pending',
        };
    }

    /**
     * Only what the buyer needs: serial, PIN and type.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    protected function vouchers(array $body): array
    {
        $rows = $body['vouchers'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn ($v) => [
            'serial' => $v['serial'] ?? null,
            'pin' => $v['pin'] ?? null,
            'type' => $v['type'] ?? null,
        ], array_filter($rows, 'is_array')));
    }

    /**
     * A message safe to show the client (provider balance and similar details stay private).
     */
    protected function reason(mixed $error, int $http = 0): string
    {
        $text = strtolower((string) $error);

        if ($http === 402 || str_contains($text, 'balance') || str_contains($text, 'insufficient') || str_contains($text, 'api key') || $http === 401 || $http === 403) {
            return 'The service is temporarily unavailable. Try again later.';
        }

        $clean = trim((string) $error);

        return $clean !== '' ? mb_substr($clean, 0, 200) : 'The provider could not complete this order.';
    }

    /*
    |--------------------------------------------------------------------------
    | Catalog helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function presentPlan(array $plan, bool $admin): array
    {
        $priced = $this->margins->priceForClient($plan['cost'], $plan['product_type'], $plan['network'], $plan['package_id']);

        $item = [
            'package_id' => $plan['package_id'],
            'network' => $plan['network'],
            'product_type' => $plan['product_type'],
            'size' => $plan['size'],
            'size_gb' => $plan['size_gb'],
            'price' => $priced['sell'],
            'available' => $plan['available'],
        ];

        if ($admin) {
            $item += [
                'api_price' => $priced['cost'],
                'sell' => $priced['sell'],
                'profit' => $priced['profit'],
                'markup' => $priced['markup'],
            ];
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    protected function airtimeCatalog(bool $admin): array
    {
        $networks = [];

        foreach ((array) config('swift.airtime_networks', []) as $network) {
            $row = $network + [
                'min_amount' => (float) config('swift.airtime_limits.min', 1),
                'max_amount' => (float) config('swift.airtime_limits.max', 500),
            ];

            if ($admin) {
                $row['markup'] = $this->margins->priceForClient(100, 'airtime', $network['code'])['markup'];
            }

            $networks[] = $row;
        }

        return ['networks' => $networks];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tvCatalog(bool $admin): array
    {
        $types = [];

        foreach ((array) config('swift.tv_bill_types', []) as $type) {
            $row = [
                'code' => $type['code'],
                'name' => $type['name'],
                'order_type' => $type['order_type'],
                'min_amount' => (float) config('swift.bill_limits.min', 1),
                'max_amount' => (float) config('swift.bill_limits.max', 5000),
            ];

            if ($admin) {
                $row['markup'] = $this->margins->priceForClient(100, 'tv', $type['code'])['markup'];
            }

            $types[] = $row;
        }

        return ['bill_types' => $types];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ecgCatalog(bool $admin): array
    {
        $row = [
            'min_amount' => (float) config('swift.bill_limits.min', 1),
            'max_amount' => (float) config('swift.bill_limits.max', 5000),
        ];

        if ($admin) {
            $row['markup'] = $this->margins->priceForClient(100, 'ecg')['markup'];
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultsCatalog(bool $admin): array
    {
        $checkers = [];

        foreach ((array) config('swift.results_checkers', []) as $checker) {
            $priced = $this->margins->priceForClient((float) $checker['unit_cost'], 'results', $checker['type']);
            $row = ['type' => $checker['type'], 'name' => $checker['name'], 'unit_price' => $priced['sell'], 'available' => true];

            if ($admin) {
                $row += ['api_price' => $priced['cost'], 'profit' => $priced['profit'], 'markup' => $priced['markup']];
            }

            $checkers[] = $row;
        }

        return ['checkers' => $checkers, 'max_quantity' => (int) config('swift.results_max_quantity', 100)];
    }

    /**
     * @return array{service: string, order_type: string, supported: bool, available: bool, reason: string|null}
     */
    protected function serviceRow(string $service, string $productType, bool $available, ?string $reason = null): array
    {
        return [
            'service' => $service,
            'order_type' => (string) config("swift.services.{$service}.order_type", $productType),
            'product_type' => $productType,
            'supported' => true,
            'available' => $available,
            'reason' => $available ? null : ($reason ?? 'No packages are available right now.'),
        ];
    }

    /**
     * @return array{orders: int, cost: float, sell: float, profit: float}
     */
    protected function profitRow(mixed $row): array
    {
        return [
            'orders' => (int) ($row->orders ?? 0),
            'cost' => round((float) ($row->cost ?? 0), 2),
            'sell' => round((float) ($row->sell ?? 0), 2),
            'profit' => round((float) ($row->profit ?? 0), 2),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Small helpers
    |--------------------------------------------------------------------------
    */

    protected function bulkMax(): int
    {
        return max(1, (int) config('swift.bulk_max', 50));
    }

    /**
     * 233241234567 → 0241234567 (the format Swift expects for phones).
     */
    protected function localPhone(string $mobile): string
    {
        return str_starts_with($mobile, '233') ? '0'.substr($mobile, 3) : $mobile;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected function pick(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && ! is_array($row[$key])) {
                return $row[$key];
            }
        }

        return $default;
    }

    protected function key(string $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '');
    }

    protected function newReference(): string
    {
        do {
            $reference = 'UTL-'.strtoupper(uniqid());
        } while (UtilityOrder::query()->where('reference', $reference)->exists());

        return $reference;
    }

    protected function failed(mixed $value): bool
    {
        return is_array($value) && array_key_exists('in_error', $value);
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function unavailable(string $message): array
    {
        return $this->result(true, $message, ApiCode::UNAVAILABLE, ApiReason::PROVIDER_UNAVAILABLE);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function invalid(array $errors): array
    {
        return $this->result(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $errors);
    }
}
