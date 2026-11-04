<?php

namespace App\Services\Payments\Gateways;

final class GatewayStatus
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $state,
        public ?float $amountPaid = null,
        public ?float $gatewayCharge = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
