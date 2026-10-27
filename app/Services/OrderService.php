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

        return [
            'id' => $order->id,
            'client_id' => $order->client_id,
            'admin_id' => $order->admin_id,
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
            'meta_data' => $item->meta_data ?? [],
        ];
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
            'order_code' => $code,
        ]);

        OrderItem::create([
            'name' => $input['name'] ?? $type,
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
