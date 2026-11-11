<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\ProductMargin;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin profit margins. The provider's cost never leaves the admin side: clients only see `sell`.
 *
 * Most specific active rule wins: package → network → product type → config fallback percent.
 */
class MarginService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /** @var array<string, list<ProductMargin>> */
    protected array $memo = [];

    /** @var array<string, int> */
    protected array $memoAt = [];

    /**
     * @return array{cost: float, sell: float, profit: float, markup: array{type: string, value: float, source: string, margin_id: int|null}}
     */
    public function priceForClient(float $cost, string $productType, ?string $network = null, ?string $packageId = null): array
    {
        $cost = round(max(0, $cost), 2);
        $rule = $this->resolve($productType, $network, $packageId);

        if ($rule) {
            $type = $rule->markup_type;
            $value = (float) $rule->markup_value;
            $source = $rule->package_id ? 'package' : ($rule->network ? 'network' : 'product');
            $id = $rule->id;
        } else {
            $type = ProductMargin::PERCENT;
            $value = max(0, (float) config('swift.markup_percent', 0));
            $source = $value > 0 ? 'default' : 'none';
            $id = null;
        }

        $markup = $type === ProductMargin::FIXED ? round($value, 2) : round($cost * $value / 100, 2);
        $sell = round($cost + $markup, 2);

        return [
            'cost' => $cost,
            'sell' => $sell,
            'profit' => round($sell - $cost, 2),
            'markup' => ['type' => $type, 'value' => $value, 'source' => $source, 'margin_id' => $id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ProductMargin $margin): array
    {
        return [
            'id' => $margin->id,
            'product_type' => $margin->product_type,
            'network' => $margin->network,
            'package_id' => $margin->package_id,
            'markup_type' => $margin->markup_type,
            'markup_value' => (float) $margin->markup_value,
            'active' => (bool) $margin->active,
            'created_at' => optional($margin->created_at)->toDateTimeString(),
            'updated_at' => optional($margin->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  product_type, network, active
     */
    public function index(array $filters = []): Builder
    {
        $query = ProductMargin::query()->latest('id');

        if (! empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (! empty($filters['network'])) {
            $query->where('network', $filters['network']);
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function show(ProductMargin $margin): array
    {
        return $this->result(false, 'Margin loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($margin));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Admin $admin, array $input): array
    {
        $attributes = $this->attributes($input);

        if ($attributes['active'] && $this->duplicate($attributes)) {
            return $this->duplicateError();
        }

        $margin = ProductMargin::create($attributes);
        $this->forget();

        $this->activity()->record('Margin created', 'margin', 'created', 'admin', $admin, null, [
            'margin_id' => $margin->id,
            'product_type' => $margin->product_type,
        ]);

        return $this->result(false, 'Margin created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($margin));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $admin, ProductMargin $margin, array $input): array
    {
        $attributes = $this->attributes($input, $margin);

        if ($attributes['active'] && $this->duplicate($attributes, $margin->id)) {
            return $this->duplicateError();
        }

        $margin->update($attributes);
        $this->forget();

        $this->activity()->record('Margin updated', 'margin', 'updated', 'admin', $admin, null, [
            'margin_id' => $margin->id,
            'fields' => array_keys($input),
        ]);

        return $this->result(false, 'Margin updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($margin->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Admin $admin, ProductMargin $margin): array
    {
        $margin->delete();
        $this->forget();

        $this->activity()->record('Margin deleted', 'margin', 'deleted', 'admin', $admin, null, [
            'margin_id' => $margin->id,
            'product_type' => $margin->product_type,
        ]);

        return $this->result(false, 'Margin deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'product_types' => ProductMargin::PRODUCT_TYPES,
            'markup_types' => ProductMargin::MARKUP_TYPES,
        ];
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->memoAt = [];
    }

    protected function resolve(string $productType, ?string $network, ?string $packageId): ?ProductMargin
    {
        $networkKey = $this->key($network);
        $best = null;
        $bestScore = -1;

        foreach ($this->rules($productType) as $rule) {
            $score = $this->score($rule, $networkKey, $packageId);

            if ($score > $bestScore) {
                $best = $rule;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * -1 = does not apply; higher = more specific.
     */
    protected function score(ProductMargin $rule, string $networkKey, ?string $packageId): int
    {
        if ($rule->package_id) {
            if ($packageId === null || strcasecmp(trim($rule->package_id), trim($packageId)) !== 0) {
                return -1;
            }

            // A package rule may also pin a network; if it does it must match.
            if ($rule->network && $this->key($rule->network) !== $networkKey) {
                return -1;
            }

            return 3;
        }

        if ($rule->network) {
            return $this->key($rule->network) === $networkKey ? 2 : -1;
        }

        return 1;
    }

    /**
     * @return list<ProductMargin>
     */
    protected function rules(string $productType): array
    {
        // Short memo so a catalog of hundreds of plans costs one query, without going stale in workers.
        if (isset($this->memo[$productType]) && $this->memoAt[$productType] > time() - 30) {
            return $this->memo[$productType];
        }

        $this->memoAt[$productType] = time();

        return $this->memo[$productType] = ProductMargin::query()
            ->where('product_type', $productType)
            ->where('active', true)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function attributes(array $input, ?ProductMargin $existing = null): array
    {
        $get = fn (string $key, mixed $default = null) => array_key_exists($key, $input) ? $input[$key] : ($existing?->{$key} ?? $default);

        $network = trim((string) $get('network', ''));
        $package = trim((string) $get('package_id', ''));

        return [
            'product_type' => (string) $get('product_type'),
            'network' => $network !== '' ? mb_substr($network, 0, 60) : null,
            'package_id' => $package !== '' ? mb_substr($package, 0, 120) : null,
            'markup_type' => (string) $get('markup_type', ProductMargin::PERCENT),
            'markup_value' => round(max(0, (float) $get('markup_value', 0)), 4),
            'active' => filter_var($get('active', true), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function duplicate(array $attributes, ?int $ignoreId = null): bool
    {
        return ProductMargin::query()
            ->where('product_type', $attributes['product_type'])
            ->where('active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->when($attributes['network'] === null, fn ($q) => $q->whereNull('network'), fn ($q) => $q->where('network', $attributes['network']))
            ->when($attributes['package_id'] === null, fn ($q) => $q->whereNull('package_id'), fn ($q) => $q->where('package_id', $attributes['package_id']))
            ->exists();
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function duplicateError(): array
    {
        return $this->result(true, 'An active margin already exists for this product, network and package.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
            'product_type' => ['An active margin already exists for this product, network and package.'],
        ]);
    }

    protected function key(?string $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string) $value) ?? '');
    }
}
