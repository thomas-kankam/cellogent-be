<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'description',
        'count_of_contacts',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'contact_group_id');
    }
}
