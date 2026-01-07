<?php

namespace App\Providers;

use App\Services\ConfigService;
use App\Services\TaskService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TaskService::class, function () {
            return new TaskService(getcwd().'/.fuel/tasks.jsonl');
        });

        $this->app->singleton(ConfigService::class, function () {
            return new ConfigService(getcwd().'/.fuel/config.yaml');
        });
    }
}
