<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        @ini_set('pcre.jit', '0');

        // PHP 8.4 tempnam() warns (Laravel turns it into ErrorException) when the
        // target folder is missing or not writable and it falls back to /tmp.
        $this->ensureWritableDirectories();
    }

    public function boot(): void
    {
        // Personal-access tokens issued after OTP verify.
        Passport::personalAccessTokensExpireIn(now()->addDays(30));
        Passport::tokensExpireIn(now()->addDays(30));
        Passport::refreshTokensExpireIn(now()->addDays(60));

        RateLimiter::for('sms-api', function (Request $request) {
            return Limit::perMinute((int) config('sms.api_rate_per_minute', 300))
                ->by('sms-api:'.($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute((int) config('payments.checkout_per_minute', 10))
                ->by('checkout:'.($request->user()?->id ?? $request->ip()));
        });
    }

    protected function ensureWritableDirectories(): void
    {
        foreach ([
            storage_path('app/tmp'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
        }

        $tmp = storage_path('app/tmp');

        if (is_dir($tmp) && is_writable($tmp)) {
            putenv('TMPDIR='.$tmp);
            ini_set('sys_temp_dir', $tmp);
        }
    }
}
