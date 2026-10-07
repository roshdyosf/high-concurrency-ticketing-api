<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // L1: public GET (listing/details) - per IP
        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute(120)->by($this->ip($request)));

        // L2: register + login - per IP (one shared bucket)
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(5)->by($this->ip($request)));

        // L2: login attempts - per email (case-insensitive)
        RateLimiter::for('login-email', function (Request $request): Limit {
            $email = $request->input('email');
            $key = is_string($email) && trim($email) !== ''
                ? Str::lower(trim($email))
                : $this->ip($request);

            return Limit::perMinute(5)->by($key);
        });

        // forgot/reset password - per IP, separate bucket (decision 50)
        RateLimiter::for('password', fn (Request $request): Limit => Limit::perMinute(5)->by($this->ip($request)));

        // L5: remaining authenticated routes - per user
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(60)->by($user !== null ? 'user:' . $user->getAuthIdentifier() : $this->ip($request));
        });
    }

    private function ip(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }
}
