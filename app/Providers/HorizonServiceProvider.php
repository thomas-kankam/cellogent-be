<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::night();
    }

    /**
     * Local: anyone can open /horizon.
     * Production: restrict by email in HORIZON_EMAIL.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($this->app->environment('local')) {
                return true;
            }

            return $user && $user->email === env('HORIZON_EMAIL');
        });
    }
}
