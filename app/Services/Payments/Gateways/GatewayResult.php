<?php

namespace App\Services\Payments\Gateways;

final class GatewayResult
{
    /**
     * @param  bool  $retryable  true when the gateway itself is unavailable, so the fallback should be tried
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $ok,
        public bool $retryable = false,
        public ?string $reference = null,
        public ?string $redirectUrl = null,
        public ?float $gatewayCharge = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function unavailable(string $message, array $raw = []): self
    {
        return new self(false, true, message: $message, raw: $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function rejected(string $message, array $raw = []): self
    {
        return new self(false, false, message: $message, raw: $raw);
    }
}
