<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ProcessManagerInterface;
use App\Services\AgentHealthTracker;
use App\Services\ConfigService;
use App\Services\DatabaseService;
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
        $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService(getcwd().'/.fuel/tasks.jsonl'));

        $this->app->singleton(ConfigService::class, fn (): ConfigService => new ConfigService(getcwd().'/.fuel/config.yaml'));

        $this->app->singleton(RunService::class, fn (): RunService => new RunService(getcwd().'/.fuel/runs'));

        $this->app->singleton(ProcessManagerInterface::class, fn ($app): ProcessManager => new ProcessManager(
            configService: $app->make(ConfigService::class),
            healthTracker: $app->make(AgentHealthTrackerInterface::class),
        ));

        $this->app->singleton(ProcessManager::class, fn ($app): ProcessManager => $app->make(ProcessManagerInterface::class));

        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => new DatabaseService(getcwd().'/.fuel/agent.db'));

        $this->app->singleton(AgentHealthTrackerInterface::class, AgentHealthTracker::class);
    }
}
