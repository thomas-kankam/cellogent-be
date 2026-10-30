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
            return $this->canViewHorizon($request);
        });

        \Laravel\Horizon\Horizon::night();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $this->canViewHorizon(request());
        });
    }

    protected function canViewHorizon($request): bool
    {
        if ($this->horizonIsOpen($request)) {
            return true;
        }

        $token = (string) config('horizon.token', '');

        if ($token !== '' && hash_equals($token, (string) $request->query('token', ''))) {
            $request->session()->put('horizon.authenticated', true);

            return true;
        }

        if ($request->hasSession() && $request->session()->get('horizon.authenticated') === true) {
            return true;
        }

        $user = $request->user();
        $emails = array_values(array_filter(array_map('trim', explode(',', (string) env('HORIZON_EMAIL', '')))));

        return $user !== null && in_array($user->email, $emails, true);
    }

    /**
     * Browser dashboard on this machine (artisan serve / local env).
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
