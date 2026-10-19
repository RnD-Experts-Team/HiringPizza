<?php

namespace App\Providers;

use App\Services\Tcp\FakeTcpEmployeeClient;
use App\Services\Tcp\TcpEmployeeClient;
use App\Services\Tcp\TcpEmployeeClientInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TCP Manager+ — the system of record for employees. Its own connector
        // carries them into Humanity; this service never writes Humanity.
        // The fake is a singleton so tests can seed it and see the same
        // instance the code under test uses.
        $this->app->singleton(FakeTcpEmployeeClient::class);

        $this->app->bind(TcpEmployeeClientInterface::class, function ($app) {
            return config('tcp.driver') === 'http'
                ? $app->make(TcpEmployeeClient::class)
                : $app->make(FakeTcpEmployeeClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();
    }

    /**
     * Rate limits, applied by ObservableThrottleRequests (the `throttle` alias).
     *
     * Every throttle runs BEFORE auth.token.store - the framework sorts it ahead
     * of the api group's SubstituteBindings - so a throttled request never costs
     * a pizzasys round-trip. That also means no user id exists yet: limits are
     * keyed on a hash of the bearer token, or the IP when there is none.
     *
     * Phase 1: the real limits are generous and the `target:` limits are the
     * numbers we expect to end on - they only log when passed. Phase 2 sets the
     * real limits from those logs and drops the targets.
     */
    private function configureRateLimiting(): void
    {
        // Every route: per token, reads and writes counted apart, plus a
        // per-IP ceiling for floods of made-up tokens (a store's staff share
        // one NAT address, hence the size).
        RateLimiter::for('api', function (Request $request) {
            $token = self::tokenKey($request);
            $kind = $request->isMethodSafe() ? 'r:' : 'w:';
            $ip = 'ip:'.$request->ip();

            return [
                Limit::perMinute($request->isMethodSafe() ? 600 : 300)->by($kind.$token),
                Limit::perMinute(5000)->by($ip),
                Limit::perMinute($request->isMethodSafe() ? 120 : 60)->by('target:'.$kind.$token),
                Limit::perMinute(1200)->by('target:'.$ip),
            ];
        });

        RateLimiter::for('api-heavy', fn (Request $request) => [
            Limit::perMinute(60)->by(self::tokenKey($request)),
            Limit::perMinute(20)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(200)->by('target:h:'.self::tokenKey($request)),
        ]);

        // GET v1/reports: any number of stores over any date range.
        RateLimiter::for('api-heavy-multi', fn (Request $request) => [
            Limit::perMinute(20)->by(self::tokenKey($request)),
            Limit::perMinute(5)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(30)->by('target:h:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-export', fn (Request $request) => [
            Limit::perMinute(10)->by('m:'.self::tokenKey($request)),
            Limit::perHour(100)->by('h:'.self::tokenKey($request)),
            Limit::perMinute(3)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(20)->by('target:h:'.self::tokenKey($request)),
            Limit::perDay(60)->by('target:d:'.self::tokenKey($request)),
        ]);

        // auth.secret.key routes: per secret and IP. The secret is read the
        // way CheckSecretKeyMiddleware reads it, and only its hash is kept.
        RateLimiter::for('service-secret', function (Request $request) {
            $secret = (string) $request->header('X-Secret-Key', '');
            if ($secret === '') {
                $secret = (string) $request->input('X-Secret-Key', $request->input('x_secret_key', ''));
            }

            $key = 's:'.hash('sha256', $secret).'|'.$request->ip();

            return [
                Limit::perMinute(60)->by($key),
                Limit::perMinute(10)->by('target:m:'.$key),
                Limit::perHour(60)->by('target:h:'.$key),
            ];
        });
    }

    /**
     * The caller's bearer token, hashed - never stored or logged raw - or the
     * IP when there is no token.
     */
    private static function tokenKey(Request $request): string
    {
        $token = (string) $request->bearerToken();

        return $token !== '' ? 't:'.hash('sha256', $token) : 'ip:'.$request->ip();
    }
}
