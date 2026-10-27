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

    public function months(): int
    {
        return (int) ($this->details['months'] ?? 1);
    }

    public function units(): int
    {
        return (int) ($this->details['units'] ?? 0);
    }

    public function price(): float
    {
        return (float) ($this->details['price'] ?? 0);
    }
}
