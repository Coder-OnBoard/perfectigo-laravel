<?php

namespace Perfectigo\Laravel;

use Illuminate\Support\ServiceProvider;
use Perfectigo\Client;

class PerfectigoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merged rather than required, so an application that never publishes
        // the config still boots — with no secret key, which is "off".
        $this->mergeConfigFrom(__DIR__.'/../../config/perfectigo.php', 'perfectigo');

        $this->app->singleton(Client::class, fn () => new Client(
            (string) config('perfectigo.base_url', 'https://api.perfectigo.com'),
            (string) config('perfectigo.secret_key', ''),
            (int) config('perfectigo.timeout', 15),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/perfectigo.php' => config_path('perfectigo.php'),
            ], 'perfectigo-config');
        }
    }
}
