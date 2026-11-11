<?php

namespace App\Services;

use App\Concerns\ReturnsServiceResult;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Str;

class OrderService
{
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $order->loadMissing('items');

        $type = $order->type ?: $order->items->first()?->type;

        return [
            'id' => $order->id,
            'resource' => 'order',
            'client_id' => $order->client_id,
            'admin_id' => $order->admin_id,
            'type' => $type,
            'type_label' => Order::typeLabel($type),
            'quantity' => $order->quantity,
            'total_price' => $order->total_price,
            'status' => $order->status,
            'order_code' => $order->order_code,
            'items' => $order->items->map(fn (OrderItem $item) => $this->presentItem($item))->values()->all(),
            'created_at' => optional($order->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'unit_price' => $item->unit_price,
            'order_id' => $item->order_id,
            'order_code' => $item->order_code,
            'type' => $item->type,
            'type_label' => Order::typeLabel($item->type),
            'meta_data' => $this->publicMeta($item->meta_data ?? []),
        ];
    }

    /**
     * Utility purchases keep the provider cost and our profit in meta_data for fulfilment;
     * order responses (client and admin lists alike) never repeat them.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function publicMeta(array $meta): array
    {
        if (! isset($meta['utility']) || ! is_array($meta['utility'])) {
            return $meta;
        }

        $strip = function (array $spec): array {
            unset($spec['cost'], $spec['profit'], $spec['markup'], $spec['input']);

            return $spec;
        };

        $utility = $meta['utility'];
        $meta['utility'] = isset($utility['items']) && is_array($utility['items'])
            ? ['items' => array_map(fn ($item) => is_array($item) ? $strip($item) : $item, $utility['items'])]
            : $strip($utility);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{order: Order, payment: Payment}
     */
    public function open(Client $client, array $input, ?int $adminId = null): array
    {
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $unitPrice = (float) $input['unit_price'];
        $total = $unitPrice * $quantity;
        $code = $this->makeOrderCode();
        $type = $input['type'];

        $order = Order::create([
            'client_id' => $client->id,
            'admin_id' => $adminId,
            'quantity' => $quantity,
            'total_price' => $total,
            'status' => 'pending',
            'type' => $type,
            'order_code' => $code,
        ]);

        OrderItem::create([
            'name' => $input['name'] ?? Order::typeLabel($type) ?? $type,
            'unit_price' => $unitPrice,
            'order_id' => $order->id,
            'order_code' => $code,
            'type' => $type,
            'meta_data' => $input['meta_data'] ?? [],
        ]);

        $payment = Payment::create([
            'client_id' => $client->id,
            'admin_id' => $adminId,
            'order_id' => $order->id,
            'network' => $input['network'] ?? null,
            'method' => $input['method'] ?? null,
            'reference_id' => $code,
            'status' => 'pending',
            'purpose' => $type,
            'payment_link' => $input['payment_link'] ?? null,
        ]);

        return ['order' => $order->fresh('items'), 'payment' => $payment];
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function markPaid(Order $order): array
    {
        $order->update(['status' => 'paid']);

        return $this->result(false, 'Order paid.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($order->fresh('items')));
    }

    protected function makeOrderCode(): string
    {
        do {
            $code = 'ORD'.strtoupper(Str::random(8));
        } while (Order::query()->where('order_code', $code)->exists());

        return $code;
    }
}
