<?php

namespace App\Models;

class Client extends Actor
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'date_of_birth',
        'gender',
        'account_type',
        'business_name',
        'region',
        'city',
        'address',
        'status',
        'profile_image',
        'email_verified_at',
        'last_login_at',
        'onboarding_type',
        'onboarding_by',
        'referral_code',
    ];

    protected $hidden = [
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function guardName(): string
    {
        return 'client';
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function onboardedBy()
    {
        return $this->belongsTo(Admin::class, 'onboarding_by');
    }

    public function senderIds()
    {
        return $this->hasMany(SenderId::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class);
    }
}
