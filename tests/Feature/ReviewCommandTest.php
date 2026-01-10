<?php

declare(strict_types=1);

use App\Contracts\ReviewServiceInterface;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn () => $context);

    $this->configPath = $context->getConfigPath();
    $this->runsPath = $context->getRunsPath();

    // Create runs directory
    mkdir($this->runsPath, 0755, true);

    // Bind test services
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn () => $databaseService);

    $this->app->singleton(TaskService::class, function () use ($databaseService) {
        return new TaskService($databaseService);
    });

    $this->app->singleton(RunService::class, function () use ($databaseService) {
        return new RunService($databaseService);
    });

    $this->app->singleton(ConfigService::class, function () use ($context) {
        return new ConfigService($context);
    });

    // Create a mock ReviewServiceInterface
    $this->mockReviewService = \Mockery::mock(ReviewServiceInterface::class);
    $this->app->instance(ReviewServiceInterface::class, $this->mockReviewService);

    $this->taskService = $this->app->make(TaskService::class);
    $this->runService = $this->app->make(RunService::class);
    $this->configService = $this->app->make(ConfigService::class);

    // Initialize storage
    $this->taskService->initialize();

    // Create minimal config
    $config = [
        'agents' => [
            'test-agent' => ['command' => 'test-agent'],
        ],
        'complexity' => [
            'trivial' => 'test-agent',
        ],
        'primary' => 'test-agent',
    ];
    file_put_contents($this->configPath, Yaml::dump($config));
});

afterEach(function (): void {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    };

    $deleteDir($this->tempDir);
    \Mockery::close();
});

it('triggers review for valid task', function (): void {
    // Create a task and set it to in_progress
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'in_progress']);

    // Log a run with an agent
    $this->runService->logRun($task['id'], [
        'agent' => 'test-agent',
        'started_at' => now()->toIso8601String(),
    ]);

    // Expect triggerReview to be called
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'test-agent')
        ->andReturn(true);

    $exitCode = Artisan::call('review', ['taskId' => $task['id']]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain("Triggering review for {$task['id']}...");
    expect($output)->toContain('Review spawned. Check `fuel board` for status.');
});

it('shows error for non-existent task', function (): void {
    $exitCode = Artisan::call('review', ['taskId' => 'f-nonexistent']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Task not found: f-nonexistent');
});

it('shows error for open task', function (): void {
    // Create a task that hasn't been started (default status is 'open')
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);

    $exitCode = Artisan::call('review', ['taskId' => $task['id']]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Cannot review a task that has not been started');
});

it('uses agent from latest run when available', function (): void {
    // Create a task and set it to in_progress
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'in_progress']);

    // Log multiple runs with different agents
    $this->runService->logRun($task['id'], [
        'agent' => 'first-agent',
        'started_at' => now()->toIso8601String(),
    ]);

    $this->runService->logRun($task['id'], [
        'agent' => 'latest-agent',
        'started_at' => now()->toIso8601String(),
    ]);

    // Expect triggerReview to be called with latest agent
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'latest-agent')
        ->andReturn(true);

    Artisan::call('review', ['taskId' => $task['id']]);
});

it('uses config review agent when no run exists', function (): void {
    // Update config with review agent
    $config = [
        'agents' => [
            'test-agent' => ['command' => 'test-agent'],
            'review-agent' => ['command' => 'review-agent'],
        ],
        'complexity' => [
            'trivial' => 'test-agent',
        ],
        'primary' => 'test-agent',
        'review' => 'review-agent',
    ];
    file_put_contents($this->configPath, Yaml::dump($config));

    // Reload config service (create new instance to clear cache)
    $context = $this->app->make(FuelContext::class);
    $this->app->singleton(ConfigService::class, function () use ($context) {
        return new ConfigService($context);
    });
    $this->configService = $this->app->make(ConfigService::class);

    // Create a task without runs and set status to closed
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'closed']);

    // Expect triggerReview to be called with review agent from config
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'review-agent')
        ->andReturn(true);

    Artisan::call('review', ['taskId' => $task['id']]);
});

it('uses primary agent as fallback when no run and no review agent configured', function (): void {
    // Create a task without runs and set status to closed
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'closed']);

    // Expect triggerReview to be called with primary agent
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'test-agent')
        ->andReturn(true);

    Artisan::call('review', ['taskId' => $task['id']]);
});

it('supports partial task ID matching', function (): void {
    // Create a task and set it to in_progress
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'in_progress']);

    // Extract partial ID (last 6 characters)
    $partialId = substr($task['id'], -6);

    $this->runService->logRun($task['id'], [
        'agent' => 'test-agent',
        'started_at' => now()->toIso8601String(),
    ]);

    // Expect triggerReview to be called with full task ID
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'test-agent');

    Artisan::call('review', ['taskId' => $partialId]);
    $output = Artisan::output();

    expect($output)->toContain("Triggering review for {$task['id']}...");
});

it('allows reviewing closed tasks', function (): void {
    // Create a task and set status to closed
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'closed']);

    $this->runService->logRun($task['id'], [
        'agent' => 'test-agent',
        'started_at' => now()->toIso8601String(),
    ]);

    // Expect triggerReview to be called
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'test-agent')
        ->andReturn(true);

    Artisan::call('review', ['taskId' => $task['id']]);
});

it('allows reviewing tasks in review status', function (): void {
    // Create a task and set status to review
    $task = $this->taskService->create([
        'title' => 'Test task',
    ]);
    $this->taskService->update($task['id'], ['status' => 'review']);

    $this->runService->logRun($task['id'], [
        'agent' => 'test-agent',
        'started_at' => now()->toIso8601String(),
    ]);

    // Expect triggerReview to be called
    $this->mockReviewService
        ->shouldReceive('triggerReview')
        ->once()
        ->with($task['id'], 'test-agent')
        ->andReturn(true);

    Artisan::call('review', ['taskId' => $task['id']]);
});
