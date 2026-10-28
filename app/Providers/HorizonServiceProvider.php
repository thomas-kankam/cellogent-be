<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Horizon is optional. Windows PHP has no pcntl, and WSL may not have
 * `composer install` yet. This provider no-ops until the package exists.
 */
class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return;
        }

        $this->gate();

        \Laravel\Horizon\Horizon::auth(function ($request) {
            if ($this->horizonIsOpen($request)) {
                return true;
            }

            $user = $request->user();

            return $user !== null && Gate::allows('viewHorizon', $user);
        });

        \Laravel\Horizon\Horizon::night();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($this->horizonIsOpen(request())) {
                return true;
            }

            return $user && $user->email === env('HORIZON_EMAIL');
        });
    }

    /**
     * Browser dashboard on this machine (artisan serve / local env).
     * Gate::check() must not run first for guests — that 403s before the local check.
     */
    protected function horizonIsOpen($request): bool
    {
        if ($this->app->environment(['local', 'development'])) {
            return true;
        }

        $host = $request->getHost();
        $ip = $request->ip();

        return in_array($host, ['127.0.0.1', 'localhost'], true)
            || in_array($ip, ['127.0.0.1', '::1'], true);
    }
}
