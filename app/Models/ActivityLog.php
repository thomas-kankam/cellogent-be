<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    /**
     * Stable catalogue for frontend filter dropdowns (even when no rows exist yet).
     */
    public const ACTORS = ['admin', 'client', 'system'];

    public const MODULES = [
        'onboarding',
        'otp',
        'profile',
        'account_status',
        'admin',
        'client',
        'role',
        'permission',
        'api_key',
        'sender_id',
        'plan',
        'subscription',
        'wallet',
        'payment',
        'payment_gateway',
        'settings',
        'order',
        'sms',
        'contact_group',
        'activity_log',
        'reward',
        'campaign',
        'survey',
        'utility',
        'margin',
        'reward_disbursement',
    ];

    public const ACTIONS = [
        'created',
        'updated',
        'deleted',
        'assigned',
        'requested',
        'verified',
        'invalid',
        'expired',
        'approved',
        'rejected',
        'blacklisted',
        'unblacklisted',
        'funded',
        'activated',
        'paused',
        'resumed',
        'completed',
        'cancelled',
    ];

    protected $fillable = [
        'title',
        'module',
        'action',
        'actor',
        'transmitter_type',
        'transmitter_id',
        'receiver_type',
        'receiver_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function transmitter()
    {
        return $this->morphTo();
    }

    public function receiver()
    {
        return $this->morphTo();
    }
}
