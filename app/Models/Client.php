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
        'is_verified',
        'verified_at',
        'profile_image',
        'last_login_at',
        'onboarding_type',
        'onboarding_by',
    ];

    protected $hidden = [
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
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

    public function walletTopUps()
    {
        return $this->hasMany(WalletTopUp::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function subscriptionArchives()
    {
        return $this->hasMany(SubscriptionArchive::class);
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

    public function contactGroups()
    {
        return $this->hasMany(ContactGroup::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }
}
