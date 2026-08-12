<?php

namespace App\Providers;

use App\Services\Humanity\FakeHumanityStaffClient;
use App\Services\Humanity\HumanityStaffClient;
use App\Services\Humanity\HumanityStaffClientInterface;
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
        // The fake is a singleton so tests can seed it and see the same
        // instance the code under test uses.
        $this->app->singleton(FakeHumanityStaffClient::class);

        $this->app->bind(HumanityStaffClientInterface::class, function ($app) {
            return config('humanity.driver') === 'http'
                ? $app->make(HumanityStaffClient::class)
                : $app->make(FakeHumanityStaffClient::class);
        });

        // TCP Manager+ — the system of record for employees, and where the
        // push actually goes now. Its own connector carries them to Humanity.
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
