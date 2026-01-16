<?php

declare(strict_types=1);

use App\Repositories\ReviewRepository;
use App\Services\DatabaseService;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

/**
 * Helper to create a review for a task using the repository.
 */
function createReviewForShowCommandTask(
    ReviewRepository $reviewRepo,
    string $taskShortId,
    string $reviewShortId,
    string $agent,
    ?int $runId = null
): void {
    $taskId = $reviewRepo->resolveTaskId($taskShortId);
    if ($taskId === null) {
        throw new RuntimeException(sprintf("Task '%s' not found.", $taskShortId));
    }

    $reviewRepo->createReview($reviewShortId, $taskId, $agent, $runId);
}

beforeEach(function (): void {
    $this->databaseService = app(DatabaseService::class);
    $this->reviewRepo = app(ReviewRepository::class);
    $this->taskService = app(TaskService::class);
    $this->runService = app(RunService::class);
});

it('shows error for non-existent review', function (): void {
    Artisan::call('review:show', ['id' => 'r-nonexistent']);
    $output = Artisan::output();

    expect($output)->toContain("Review 'r-nonexistent' not found");
});

it('shows review details', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-abc123', 'Test Task', 'done', now()->toIso8601String(), now()->toIso8601String()]
    );

    $reviewId = 'r-abc123';
    createReviewForShowCommandTask($this->reviewRepo, 'f-abc123', $reviewId, 'claude-sonnet');
    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    Artisan::call('review:show', ['id' => $reviewId]);
    $output = Artisan::output();

    expect($output)->toContain('Review: '.$reviewId);
    expect($output)->toContain('Task: f-abc123');
    expect($output)->toContain('Test Task');
    expect($output)->toContain('PASSED');
    expect($output)->toContain('claude-sonnet');
});

it('shows failed review with issues', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-def456', 'Failed Task', 'review', now()->toIso8601String(), now()->toIso8601String()]
    );

    $reviewId = 'r-def456';
    createReviewForShowCommandTask($this->reviewRepo, 'f-def456', $reviewId, 'cursor-composer');
    $this->reviewRepo->markAsCompleted($reviewId, false, ['uncommitted_changes', 'tests_failing']);

    Artisan::call('review:show', ['id' => $reviewId]);
    $output = Artisan::output();

    expect($output)->toContain('Review: '.$reviewId);
    expect($output)->toContain('FAILED');
    expect($output)->toContain('Issues');
    expect($output)->toContain('uncommitted_changes');
    expect($output)->toContain('tests_failing');
});

it('supports partial ID matching', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-xyz789', 'Partial Match Task', 'done', now()->toIso8601String(), now()->toIso8601String()]
    );

    $reviewId = 'r-xyz789';
    createReviewForShowCommandTask($this->reviewRepo, 'f-xyz789', $reviewId, 'amp-free');
    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    $partialId = substr($reviewId, 2, 4);

    Artisan::call('review:show', ['id' => $partialId]);
    $output = Artisan::output();

    expect($output)->toContain('Review: '.$reviewId);
    expect($output)->toContain('f-xyz789');
});

it('outputs JSON format with --json option', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-json01', 'JSON Test Task', 'done', now()->toIso8601String(), now()->toIso8601String()]
    );

    $reviewId = 'r-json01';
    createReviewForShowCommandTask($this->reviewRepo, 'f-json01', $reviewId, 'gemini');
    $this->reviewRepo->markAsCompleted($reviewId, false, ['task_incomplete']);

    Artisan::call('review:show', ['id' => $reviewId, '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data['short_id'])->toBe($reviewId);
    expect($data['task_id'])->toBe('f-json01');
    expect($data['agent'])->toBe('gemini');
    expect($data['status'])->toBe('failed');
    expect($data['issues'])->toBe(['task_incomplete']);
    expect($data['task_title'])->toBe('JSON Test Task');
});

it('shows agent output from stdout.log', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-stdout1', 'Stdout Test Task', 'done', now()->toIso8601String(), now()->toIso8601String()]
    );

    // Create a run for this review
    $runShortId = $this->runService->createRun('f-stdout1', [
        'agent' => 'claude',
        'started_at' => now()->toIso8601String(),
    ]);

    // Get the run's integer ID to associate with the review
    $run = $this->databaseService->fetchOne('SELECT id FROM runs WHERE short_id = ?', [$runShortId]);
    $runId = (int) $run['id'];

    $reviewId = 'r-stdout';
    createReviewForShowCommandTask($this->reviewRepo, 'f-stdout1', $reviewId, 'claude', $runId);
    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    // Use run-based directory path
    $processDir = $this->testDir.'/.fuel/processes/'.$runShortId;
    mkdir($processDir, 0755, true);
    file_put_contents($processDir.'/stdout.log', "Line 1\nLine 2\nLine 3\n");

    Artisan::call('review:show', ['id' => $reviewId]);
    $output = Artisan::output();

    expect($output)->toContain('Agent Output');
    expect($output)->toContain('Line 1');
    expect($output)->toContain('Line 2');
    expect($output)->toContain('Line 3');
});

it('delegates from fuel show r-xxx', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-delega', 'Delegation Test Task', 'done', now()->toIso8601String(), now()->toIso8601String()]
    );

    $reviewId = 'r-delega';
    createReviewForShowCommandTask($this->reviewRepo, 'f-delega', $reviewId, 'amp');
    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    Artisan::call('show', ['id' => $reviewId]);
    $output = Artisan::output();

    expect($output)->toContain('Review: '.$reviewId);
    expect($output)->toContain('f-delega');
});

it('shows pending status for in-progress reviews', function (): void {
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-pend01', 'Pending Review Task', 'review', now()->toIso8601String(), now()->toIso8601String()]
    );

    $reviewId = 'r-pend01';
    createReviewForShowCommandTask($this->reviewRepo, 'f-pend01', $reviewId, 'claude');

    Artisan::call('review:show', ['id' => $reviewId]);
    $output = Artisan::output();

    expect($output)->toContain('PENDING');
});
