<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $fillable = [
        'client_id',
        'token',
        'token_prefix',
        'status',
        'last_used_at',
    ];

    protected $hidden = [
        'token',
    ];

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
