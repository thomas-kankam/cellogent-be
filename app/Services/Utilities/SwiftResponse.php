<?php

namespace App\Services\Utilities;

final class SwiftResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public bool $ok,
        public int $httpStatus,
        public array $body,
        public ?string $error,
        public int $durationMs,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function order(): ?array
    {
        $order = $this->body['order'] ?? null;

        return is_array($order) ? $order : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        $packages = $this->body['packages'] ?? [];

        return is_array($packages) ? array_values($packages) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        $products = $this->body['products'] ?? [];

        return is_array($products) ? array_values($products) : [];
    }
}
