<?php

namespace App\Providers;

use App\Contracts\ProcessManagerInterface;
use App\Services\ConfigService;
use App\Services\ProcessManager;
use App\Services\RunService;
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

        $this->app->singleton(RunService::class, function () {
            return new RunService(getcwd().'/.fuel/runs');
        });

        $this->app->singleton(ProcessManagerInterface::class, ProcessManager::class);
    }
}
