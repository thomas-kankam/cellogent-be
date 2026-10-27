<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'code',
        'status',
    ];

    public function referrer()
    {
        return $this->belongsTo(Client::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(Client::class, 'referred_id');
    }
}
