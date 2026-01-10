<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ProcessManagerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Prompts\ReviewPrompt;
use App\Services\AgentHealthTracker;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\ReviewService;
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
        // FuelContext must be registered first - all path-dependent services use it
        $this->app->singleton(FuelContext::class, fn (): FuelContext => new FuelContext);

        $this->app->singleton(TaskService::class, fn ($app): TaskService => new TaskService(
            $app->make(FuelContext::class)->getTasksJsonlPath()
        ));

        $this->app->singleton(ConfigService::class, fn ($app): ConfigService => new ConfigService(
            $app->make(FuelContext::class)->getConfigPath()
        ));

        $this->app->singleton(RunService::class, fn ($app): RunService => new RunService(
            $app->make(FuelContext::class)->getRunsPath()
        ));

        $this->app->singleton(ProcessManagerInterface::class, fn ($app): ProcessManager => new ProcessManager(
            configService: $app->make(ConfigService::class),
            healthTracker: $app->make(AgentHealthTrackerInterface::class),
        ));

        $this->app->singleton(ProcessManager::class, fn ($app): ProcessManager => $app->make(ProcessManagerInterface::class));

        $this->app->singleton(DatabaseService::class, fn ($app): DatabaseService => new DatabaseService(
            $app->make(FuelContext::class)->getDatabasePath()
        ));

        $this->app->singleton(AgentHealthTrackerInterface::class, AgentHealthTracker::class);

        $this->app->singleton(ReviewServiceInterface::class, fn ($app): ReviewService => new ReviewService(
            processManager: $app->make(ProcessManagerInterface::class),
            taskService: $app->make(TaskService::class),
            configService: $app->make(ConfigService::class),
            reviewPrompt: new ReviewPrompt,
            databaseService: $app->make(DatabaseService::class),
            runService: $app->make(RunService::class),
        ));
    }
}
