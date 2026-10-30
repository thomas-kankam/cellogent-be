<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'client_id',
        'contact_group_id',
        'first_name',
        'last_name',
        'email',
        'gender',
        'phone_number',
        'date_of_birth',
        'region',
        'city',
        'gps_address',
        'address',
        'dnd_status',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function group()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }

    public function canReceiveSms(): bool
    {
        return $this->dnd_status !== 'active';
    }
}
