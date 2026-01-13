<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    // Bind test services
    $context->configureDatabase();
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    Artisan::call('migrate', ['--force' => true]);

    $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService());

    $this->taskService = $this->app->make(TaskService::class);
});

afterEach(function (): void {
    // Recursively delete temp directory
    if (is_dir($this->tempDir)) {
        exec('rm -rf '.escapeshellarg($this->tempDir));
    }
});

test('routes to epic:review for epic IDs', function (): void {
    // The epic:review command will fail since epic doesn't exist,
    // but we're testing that routing works correctly
    $exitCode = Artisan::call('review', ['id' => 'e-12345']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain("Epic 'e-12345' not found");
});

test('routes to review:show for review IDs', function (): void {
    // The review:show command will fail since review doesn't exist,
    // but we're testing that routing works correctly
    $exitCode = Artisan::call('review', ['id' => 'r-12345']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain("Review 'r-12345' not found");
});

test('shows task review for task IDs', function (): void {
    // Create a test task
    $task = $this->taskService->create([
        'title' => 'Test task for review',
        'description' => 'Test description',
        'type' => 'task',
        'priority' => 1,
    ]);

    // Review the task
    $exitCode = Artisan::call('review', ['id' => $task->short_id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Task Review: '.$task->short_id);
    expect($output)->toContain('Test task for review');
    expect($output)->toContain('No commit associated with this task');
});

test('shows git diff when task has commit', function (): void {
    // Create a test task with a commit hash
    $task = $this->taskService->create([
        'title' => 'Test task with commit',
        'description' => 'Has a commit',
        'type' => 'task',
        'priority' => 1,
    ]);

    // Check if we're in a git repo
    $gitProcess = new \Symfony\Component\Process\Process(['git', 'rev-parse', 'HEAD']);
    $gitProcess->run();

    if ($gitProcess->isSuccessful()) {
        $commitHash = trim($gitProcess->getOutput());
        $this->taskService->update($task->short_id, ['commit_hash' => $commitHash]);

        // Review the task
        $exitCode = Artisan::call('review', ['id' => $task->short_id]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Task Review: '.$task->short_id);
        expect($output)->toContain('Test task with commit');
        expect($output)->toContain('Commit Information');
        expect($output)->toContain('Diff Stats:');
    } else {
        // Skip test if not in a git repo
        $this->markTestSkipped('Not in a git repository');
    }
});

test('shows full diff with --diff option', function (): void {
    // Create a test task with a commit hash
    $task = $this->taskService->create([
        'title' => 'Test task for diff',
        'description' => 'Testing diff output',
        'type' => 'task',
        'priority' => 1,
    ]);

    // Check if we're in a git repo
    $gitProcess = new \Symfony\Component\Process\Process(['git', 'rev-parse', 'HEAD']);
    $gitProcess->run();

    if ($gitProcess->isSuccessful()) {
        $commitHash = trim($gitProcess->getOutput());
        $this->taskService->update($task->short_id, ['commit_hash' => $commitHash]);

        // Review the task with --diff option
        $exitCode = Artisan::call('review', ['id' => $task->short_id, '--diff' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Task Review: '.$task->short_id);
        expect($output)->toContain('Full Diff:');
        expect($output)->toContain('commit'); // Should show commit hash in diff
    } else {
        // Skip test if not in a git repo
        $this->markTestSkipped('Not in a git repository');
    }
});

test('outputs JSON with --json option', function (): void {
    // Create a test task
    $task = $this->taskService->create([
        'title' => 'JSON test task',
        'description' => 'Testing JSON output',
        'type' => 'task',
        'priority' => 1,
    ]);

    // Review the task with --json option
    Artisan::call('review', ['id' => $task->short_id, '--json' => true]);
    $output = Artisan::output();

    $json = json_decode($output, true);
    expect($json)->toBeArray()
        ->toHaveKey('task')
        ->toHaveKey('commit_hash')
        ->toHaveKey('git_stats');

    expect($json['task']['short_id'])->toBe($task->short_id);
    expect($json['task']['title'])->toBe('JSON test task');
});

test('handles partial ID matching', function (): void {
    // Create a test task
    $task = $this->taskService->create([
        'title' => 'Partial ID test',
        'description' => 'Testing partial matching',
        'type' => 'task',
        'priority' => 1,
    ]);

    // Get partial ID (first 5 chars)
    $partialId = substr($task->short_id, 0, 5); // f-xxx

    // Review with partial ID
    $exitCode = Artisan::call('review', ['id' => $partialId]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Task Review: '.$task->short_id);
    expect($output)->toContain('Partial ID test');
});

test('shows error for non-existent task', function (): void {
    $exitCode = Artisan::call('review', ['id' => 'f-nonexistent']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain("Task 'f-nonexistent' not found");
});

test('handles multiline descriptions properly', function (): void {
    // Create a task with multiline description
    $task = $this->taskService->create([
        'title' => 'Task with multiline',
        'description' => "First line\nSecond line\nThird line",
        'type' => 'task',
        'priority' => 1,
    ]);

    $exitCode = Artisan::call('review', ['id' => $task->short_id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('First line');
    expect($output)->toContain('Second line');
    expect($output)->toContain('Third line');
});
