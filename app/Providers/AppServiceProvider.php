<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agents\AgentDriverRegistry;
use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ProcessManagerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Daemon\CompletionHandler;
use App\Daemon\IpcCommandDispatcher;
use App\Daemon\LifecycleManager;
use App\Daemon\ReviewManager;
use App\Daemon\SnapshotManager;
use App\Daemon\TaskSpawner;
use App\Prompts\ReviewPrompt;
use App\Services\AgentHealthTracker;
use App\Services\BrowserDaemonManager;
use App\Services\ConfigService;
use App\Services\ConsumeIpcServer;
use App\Services\ConsumeRunner;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\NotificationService;
use App\Services\ProcessManager;
use App\Services\ReviewService;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;
use App\Services\UpdateRealityService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Eloquent to use the dynamic database path from FuelContext
        // This allows each test to use its own isolated database
        static::configureDatabasePath();
    }

    private function resolveBasePath(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, '--cwd=')) {
                return substr((string) $arg, 6).'/.fuel';
            }
        }

        $cwdIndex = array_search('--cwd', $argv, true);
        if ($cwdIndex !== false && isset($argv[$cwdIndex + 1])) {
            return $argv[$cwdIndex + 1].'/.fuel';
        }

        $currentDir = getcwd();
        $maxLevels = 5;

        for ($i = 0; $i < $maxLevels; $i++) {
            $fuelDir = $currentDir.'/.fuel';
            if (is_dir($fuelDir)) {
                return $fuelDir;
            }

            $gitDir = $currentDir.'/.git';
            if (is_dir($gitDir)) {
                break;
            }

            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break;
            }

            $currentDir = $parentDir;
        }

        return getcwd().'/.fuel';
    }

    /**
     * Configure the database path from FuelContext.
     * Can be called from tests to reconfigure the database path.
     */
    public static function configureDatabasePath(?FuelContext $context = null): void
    {
        // In testing environment, use the database configured in phpunit.xml.dist (:memory:)
        if (app()->environment('testing')) {
            return;
        }

        $fuelContext = $context ?? app(FuelContext::class);
        $databasePath = $fuelContext->getDatabasePath();

        // Update the database configuration to use the .fuel/agent.db path
        config(['database.connections.sqlite.database' => $databasePath]);

        // Purge any existing connection to force reconnection with new config
        DB::purge('sqlite');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // FuelContext must be registered first - all path-dependent services use it
        $this->app->singleton(FuelContext::class, fn (): FuelContext => new FuelContext($this->resolveBasePath()));

        // DatabaseService must be registered before TaskService (TaskService depends on it)
        $this->app->singleton(DatabaseService::class, fn (Application $app): DatabaseService => new DatabaseService(
            $app->make(FuelContext::class)->getDatabasePath()
        ));

        $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService);

        $this->app->singleton(EpicService::class, fn (Application $app): EpicService => new EpicService(
            $app->make(TaskService::class)
        ));

        $this->app->singleton(AgentDriverRegistry::class, fn (): AgentDriverRegistry => new AgentDriverRegistry);

        $this->app->singleton(ConfigService::class, fn (Application $app): ConfigService => new ConfigService(
            $app->make(FuelContext::class),
            $app->make(AgentDriverRegistry::class)
        ));

        $this->app->singleton(RunService::class, fn (Application $app): RunService => new RunService);

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
            reviewPrompt: $app->make(ReviewPrompt::class),
            runService: $app->make(RunService::class),
            fuelContext: $app->make(FuelContext::class),
        ));

        $this->app->singleton(TaskPromptBuilder::class);

        $this->app->singleton(UpdateRealityService::class, fn (Application $app): UpdateRealityService => new UpdateRealityService(
            configService: $app->make(ConfigService::class),
            fuelContext: $app->make(FuelContext::class),
            processManager: $app->make(ProcessManagerInterface::class),
        ));

        $this->app->singleton(NotificationService::class);

        $this->app->singleton(BrowserDaemonManager::class, fn (): BrowserDaemonManager => BrowserDaemonManager::getInstance());

        $this->app->singleton(LifecycleManager::class, fn (Application $app): LifecycleManager => new LifecycleManager(
            fuelContext: $app->make(FuelContext::class),
        ));

        // ConsumeIpcServer MUST be a singleton - all daemon components share the same server instance
        $this->app->singleton(ConsumeIpcServer::class);

        $this->app->singleton(TaskSpawner::class, fn (Application $app): TaskSpawner => new TaskSpawner(
            taskService: $app->make(TaskService::class),
            configService: $app->make(ConfigService::class),
            runService: $app->make(RunService::class),
            processManager: $app->make(ProcessManagerInterface::class),
            fuelContext: $app->make(FuelContext::class),
            healthTracker: $app->make(AgentHealthTrackerInterface::class),
        ));

        $this->app->singleton(ReviewManager::class, fn (Application $app): ReviewManager => new ReviewManager(
            reviewService: $app->make(ReviewServiceInterface::class),
            taskService: $app->make(TaskService::class),
            taskSpawner: $app->make(TaskSpawner::class),
            ipcServer: $app->make(ConsumeIpcServer::class),
            lifecycleManager: $app->make(LifecycleManager::class),
        ));

        $this->app->singleton(CompletionHandler::class, fn (Application $app): CompletionHandler => new CompletionHandler(
            processManager: $app->make(ProcessManagerInterface::class),
            taskService: $app->make(TaskService::class),
            runService: $app->make(RunService::class),
            configService: $app->make(ConfigService::class),
            healthTracker: $app->make(AgentHealthTrackerInterface::class),
            reviewService: $app->make(ReviewServiceInterface::class),
        ));

        $this->app->singleton(IpcCommandDispatcher::class, fn (Application $app): IpcCommandDispatcher => new IpcCommandDispatcher(
            ipcServer: $app->make(ConsumeIpcServer::class),
            lifecycleManager: $app->make(LifecycleManager::class),
            completionHandler: $app->make(CompletionHandler::class),
            configService: $app->make(ConfigService::class),
        ));

        $this->app->singleton(SnapshotManager::class, fn (Application $app): SnapshotManager => new SnapshotManager(
            ipcServer: $app->make(ConsumeIpcServer::class),
            taskService: $app->make(TaskService::class),
            processManager: $app->make(ProcessManager::class),
            healthTracker: $app->make(AgentHealthTrackerInterface::class),
            lifecycleManager: $app->make(LifecycleManager::class),
        ));

        $this->app->singleton(ConsumeRunner::class, fn (Application $app): ConsumeRunner => new ConsumeRunner(
            ipcServer: $app->make(ConsumeIpcServer::class),
            processManager: $app->make(ProcessManager::class),
            taskService: $app->make(TaskService::class),
            configService: $app->make(ConfigService::class),
            runService: $app->make(RunService::class),
            lifecycleManager: $app->make(LifecycleManager::class),
            taskSpawner: $app->make(TaskSpawner::class),
            completionHandler: $app->make(CompletionHandler::class),
            ipcCommandDispatcher: $app->make(IpcCommandDispatcher::class),
            snapshotManager: $app->make(SnapshotManager::class),
            browserDaemonManager: $app->make(BrowserDaemonManager::class),
            reviewManager: $app->make(ReviewManager::class),
            epicService: $app->make(EpicService::class),
            notificationService: $app->make(NotificationService::class),
        ));
    }
}
