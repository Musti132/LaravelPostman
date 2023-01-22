<?php
namespace Musti\LaravelPostman;

use Illuminate\Support\ServiceProvider;
use Musti\LaravelPostman\Console\Commands\ExportRoutesCommand;

class LaravelPostmanServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExportRoutesCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/laravel-postman.php' => config_path('laravel-postman.php'),
        ], 'laravel-postman');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-postman.php', 'laravel-postman'
        );
    }
}

?>