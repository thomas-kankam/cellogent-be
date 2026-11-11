<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'details',
        'status',
        'is_featured',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * months 0 = non-expiry package on this plan.
     *
     * @return list<array{months: int, units: int, price: float, non_expiry: bool}>
     */
    public function options(): array
    {
        $details = $this->details ?? [];

        if ($details === []) {
            return [];
        }

        if (isset($details['months']) || isset($details['price']) || isset($details['units'])) {
            $details = [$details];
        }

        return array_values(array_map(function ($option) {
            $months = array_key_exists('months', $option) && $option['months'] !== null && $option['months'] !== ''
                ? max(0, (int) $option['months'])
                : 0;

            return [
                'months' => $months,
                'units' => (int) ($option['units'] ?? 0),
                'price' => (float) ($option['price'] ?? 0),
                'non_expiry' => $months === 0,
            ];
        }, $details));
    }

    /**
     * @return array{months: int, units: int, price: float, non_expiry: bool}|null
     */
    public function optionForMonths(int $months): ?array
    {
        $months = max(0, $months);

        foreach ($this->options() as $option) {
            if ($option['months'] === $months) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Non-expiry package on this plan (months = 0), if any.
     *
     * @return array{months: int, units: int, price: float, non_expiry: bool}|null
     */
    public function nonExpiryOption(): ?array
    {
        return $this->optionForMonths(0);
    }
}
