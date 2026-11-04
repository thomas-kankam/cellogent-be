<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_enabled',
        'priority',
        'methods',
        'consecutive_failures',
        'last_failure_at',
        'last_failure_reason',
        'last_success_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'methods' => 'array',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function supports(string $method): bool
    {
        return in_array($method, $this->methods ?? [], true);
    }
}
