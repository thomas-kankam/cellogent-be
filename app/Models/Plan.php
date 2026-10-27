<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'details',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /**
     * @return list<array{months: int, units: int, price: float}>
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
            return [
                'months' => (int) ($option['months'] ?? 1),
                'units' => (int) ($option['units'] ?? 0),
                'price' => (float) ($option['price'] ?? 0),
            ];
        }, $details));
    }

    /**
     * @return array{months: int, units: int, price: float}|null
     */
    public function optionForMonths(int $months): ?array
    {
        foreach ($this->options() as $option) {
            if ($option['months'] === $months) {
                return $option;
            }
        }

        return null;
    }
}
