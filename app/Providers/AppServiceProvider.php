<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
