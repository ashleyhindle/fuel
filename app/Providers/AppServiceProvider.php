<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ProcessManagerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Prompts\ReviewPrompt;
use App\Repositories\EpicRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\RunRepository;
use App\Repositories\TaskRepository;
use App\Services\AgentHealthTracker;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\ReviewService;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Contracts\Foundation\Application;
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

        // DatabaseService must be registered before TaskService (TaskService depends on it)
        $this->app->singleton(DatabaseService::class, fn (Application $app): DatabaseService => new DatabaseService(
            $app->make(FuelContext::class)->getDatabasePath()
        ));

        // Register repositories - all repositories depend on DatabaseService
        $this->app->singleton(TaskRepository::class);
        $this->app->singleton(EpicRepository::class);
        $this->app->singleton(RunRepository::class);
        $this->app->singleton(ReviewRepository::class);

        $this->app->singleton(TaskService::class, fn (Application $app): TaskService => new TaskService(
            $app->make(DatabaseService::class),
            $app->make(TaskRepository::class),
            $app->make(EpicRepository::class)
        ));

        $this->app->singleton(EpicService::class, fn (Application $app): EpicService => new EpicService(
            $app->make(DatabaseService::class),
            $app->make(TaskService::class),
            $app->make(EpicRepository::class)
        ));

        $this->app->singleton(ConfigService::class, fn (Application $app): ConfigService => new ConfigService(
            $app->make(FuelContext::class)
        ));

        $this->app->singleton(RunService::class, fn (Application $app): RunService => new RunService(
            $app->make(RunRepository::class),
            $app->make(TaskRepository::class)
        ));

        $this->app->singleton(ProcessManagerInterface::class, fn (Application $app): ProcessManager => new ProcessManager(
            configService: $app->make(ConfigService::class),
            fuelContext: $app->make(FuelContext::class),
            healthTracker: $app->make(AgentHealthTrackerInterface::class),
        ));

        $this->app->singleton(ProcessManager::class, fn (Application $app): ProcessManager => $app->make(ProcessManagerInterface::class));

        $this->app->singleton(AgentHealthTrackerInterface::class, AgentHealthTracker::class);

        $this->app->singleton(ReviewServiceInterface::class, fn (Application $app): ReviewService => new ReviewService(
            processManager: $app->make(ProcessManagerInterface::class),
            taskService: $app->make(TaskService::class),
            configService: $app->make(ConfigService::class),
            reviewPrompt: new ReviewPrompt,
            databaseService: $app->make(DatabaseService::class),
            runService: $app->make(RunService::class),
            reviewRepository: $app->make(ReviewRepository::class),
            runRepository: $app->make(RunRepository::class),
        ));
    }
}
