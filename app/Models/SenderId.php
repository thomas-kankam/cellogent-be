<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SenderId extends Model
{
    protected $fillable = [
        'name',
        'client_id',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
