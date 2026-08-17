<?php

namespace App\Providers;

use App\Services\Tcp\FakeTcpEmployeeClient;
use App\Services\Tcp\TcpEmployeeClient;
use App\Services\Tcp\TcpEmployeeClientInterface;
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
    }
}
