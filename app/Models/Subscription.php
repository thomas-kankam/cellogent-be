<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    /**
     * One live pack per client. History goes to subscription_archieves.
     *
     * type = monthly|custom → ends_at is set; renewing early stacks leftover units and remaining days.
     * type = non_expiry → ends_at is null (SMS top-up); renewing only stacks units.
     */
    protected $fillable = [
        'client_id',
        'plan_id',
        'plan_name',
        'status',
        'price',
        'units',
        'starts_at',
        'ends_at',
        'type',
        'duration_in_months',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
