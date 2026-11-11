<?php

namespace App\Services\Utilities;

use Illuminate\Support\Facades\Cache;

/**
 * Swift's data/MashUp plan list (GET /plans), normalised and cached. Costs here are Swift's
 * `api_price`; selling prices are always produced by MarginService.
 */
class SwiftCatalog
{
    protected const CACHE_KEY = 'swift:plans:v2';

    public function __construct(protected SwiftClient $swift) {}

    /**
     * Every plan, or null when Swift cannot be reached.
     *
     * @return list<array{package_id: string, network: string, network_key: string, size: string, size_gb: float|null, cost: float, available: bool, product_type: string}>|null
     */
    public function plans(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->swift->plans();

        if (! $response->ok) {
            return null;
        }

        $rows = $response->body['plans'] ?? ($response->body['data']['plans'] ?? []);
        $plans = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || empty($row['package_id'])) {
                continue;
            }

            $plans[] = $this->normalize($row);
        }

        Cache::put(self::CACHE_KEY, $plans, max(30, (int) config('swift.catalog_ttl', 300)));

        return $plans;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(string $packageId): ?array
    {
        foreach ($this->plans() ?? [] as $plan) {
            if (strcasecmp($plan['package_id'], trim($packageId)) === 0) {
                return $plan;
            }
        }

        return null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{package_id: string, network: string, network_key: string, size: string, size_gb: float|null, cost: float, available: bool, product_type: string}
     */
    protected function normalize(array $row): array
    {
        $network = trim((string) ($row['network'] ?? ''));
        $packageId = (string) $row['package_id'];
        $size = trim((string) ($row['package_size'] ?? ''));
        $mashup = (bool) preg_match('/mash/i', $network.' '.$packageId);

        return [
            'package_id' => $packageId,
            'network' => $network,
            'network_key' => strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $network) ?? ''),
            'size' => $size,
            'size_gb' => $this->gigabytes($size),
            'cost' => round((float) ($row['api_price'] ?? 0), 2),
            'available' => empty($row['is_unavailable']),
            'product_type' => $mashup ? 'mashup' : 'data_bundle',
        ];
    }

    /**
     * "5GB" → 5, "500MB" → 0.49, "1.2GB + 50 Mins" → 1.2. Null when no data amount is in the label.
     */
    protected function gigabytes(string $size): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*GB/i', $size, $m)) {
            return (float) $m[1];
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*MB/i', $size, $m)) {
            return round((float) $m[1] / 1024, 2);
        }

        return null;
    }
}
