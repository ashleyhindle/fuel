<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';
    mkdir(dirname($this->dbPath), 0755, true);

    // Bind our test DatabaseService instance
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => new DatabaseService($this->dbPath));

    $this->databaseService = $this->app->make(DatabaseService::class);
    $this->databaseService->initialize();
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
});

it('shows no reviews message when no reviews exist', function (): void {
    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    expect($output)->toContain('No reviews found.');
});

it('shows recent reviews with correct format', function (): void {
    // Create reviews with different statuses
    $now = new \DateTime;
    $twoMinutesAgo = (clone $now)->modify('-2 minutes');
    $fiveMinutesAgo = (clone $now)->modify('-5 minutes');
    $oneMinuteAgo = (clone $now)->modify('-1 minute');

    // Create passed review
    $reviewId1 = $this->databaseService->recordReviewStarted('f-abc123', 'claude-sonnet');
    $this->databaseService->query(
        'UPDATE reviews SET status = ?, completed_at = ?, started_at = ? WHERE id = ?',
        ['passed', $now->format('c'), $twoMinutesAgo->format('c'), $reviewId1]
    );

    // Create failed review with issues
    $reviewId2 = $this->databaseService->recordReviewStarted('f-def456', 'claude-opus');
    $this->databaseService->recordReviewCompleted($reviewId2, false, ['uncommitted_changes', 'tests_failing'], []);
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE id = ?',
        [$fiveMinutesAgo->format('c'), $reviewId2]
    );

    // Create pending review
    $reviewId3 = $this->databaseService->recordReviewStarted('f-ghi789', 'amp-free');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE id = ?',
        [$oneMinuteAgo->format('c'), $reviewId3]
    );

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    expect($output)->toContain('Recent Reviews');
    expect($output)->toContain('f-abc123');
    expect($output)->toContain('f-def456');
    expect($output)->toContain('f-ghi789');
    expect($output)->toContain('claude-sonnet');
    expect($output)->toContain('claude-opus');
    expect($output)->toContain('amp-free');
    expect($output)->toContain('passed');
    expect($output)->toContain('failed');
    expect($output)->toContain('pending');
    expect($output)->toContain('uncommitted_changes');
    expect($output)->toContain('tests_failing');
});

it('shows only last 10 reviews by default', function (): void {
    // Create 15 reviews
    for ($i = 1; $i <= 15; $i++) {
        $reviewId = $this->databaseService->recordReviewStarted("f-task{$i}", 'claude');
        $this->databaseService->recordReviewCompleted($reviewId, true, [], []);
    }

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    // Count occurrences of task IDs
    $taskIdCount = substr_count($output, 'f-task');
    expect($taskIdCount)->toBe(10);
});

it('shows all reviews with --all option', function (): void {
    // Create 15 reviews
    for ($i = 1; $i <= 15; $i++) {
        $reviewId = $this->databaseService->recordReviewStarted("f-task{$i}", 'claude');
        $this->databaseService->recordReviewCompleted($reviewId, true, [], []);
    }

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--all' => true]);
    $output = Artisan::output();

    // Count occurrences of task IDs
    $taskIdCount = substr_count($output, 'f-task');
    expect($taskIdCount)->toBe(15);
});

it('filters to pending reviews only with --pending option', function (): void {
    // Create reviews with different statuses
    $reviewId1 = $this->databaseService->recordReviewStarted('f-task1', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId1, true, [], []);

    $reviewId2 = $this->databaseService->recordReviewStarted('f-task2', 'gemini');
    // Leave as pending

    $reviewId3 = $this->databaseService->recordReviewStarted('f-task3', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId3, false, ['tests_failing'], []);

    $reviewId4 = $this->databaseService->recordReviewStarted('f-task4', 'amp');
    // Leave as pending

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--pending' => true]);
    $output = Artisan::output();

    expect($output)->toContain('f-task2');
    expect($output)->toContain('f-task4');
    expect($output)->not->toContain('f-task1');
    expect($output)->not->toContain('f-task3');
    expect($output)->toContain('pending');
});

it('filters to failed reviews only with --failed option', function (): void {
    // Create reviews with different statuses
    $reviewId1 = $this->databaseService->recordReviewStarted('f-task1', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId1, true, [], []);

    $reviewId2 = $this->databaseService->recordReviewStarted('f-task2', 'gemini');
    // Leave as pending

    $reviewId3 = $this->databaseService->recordReviewStarted('f-task3', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId3, false, ['tests_failing'], []);

    $reviewId4 = $this->databaseService->recordReviewStarted('f-task4', 'amp');
    $this->databaseService->recordReviewCompleted($reviewId4, false, ['uncommitted_changes'], []);

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--failed' => true]);
    $output = Artisan::output();

    expect($output)->toContain('f-task3');
    expect($output)->toContain('f-task4');
    expect($output)->not->toContain('f-task1');
    expect($output)->not->toContain('f-task2');
    expect($output)->toContain('failed');
});

it('outputs JSON format with --json option', function (): void {
    // Create a review
    $reviewId = $this->databaseService->recordReviewStarted('f-abc123', 'claude-sonnet');
    $this->databaseService->recordReviewCompleted($reviewId, false, ['tests_failing'], ['f-follow1']);

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($reviewId);
    expect($data[0]['task_id'])->toBe('f-abc123');
    expect($data[0]['agent'])->toBe('claude-sonnet');
    expect($data[0]['status'])->toBe('failed');
    expect($data[0]['issues'])->toBe(['tests_failing']);
    expect($data[0]['followup_task_ids'])->toBe(['f-follow1']);
});

it('outputs JSON format with --json and --pending options', function (): void {
    // Create reviews with different statuses
    $reviewId1 = $this->databaseService->recordReviewStarted('f-task1', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId1, true, [], []);

    $reviewId2 = $this->databaseService->recordReviewStarted('f-task2', 'gemini');
    // Leave as pending

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--json' => true, '--pending' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($reviewId2);
    expect($data[0]['status'])->toBe('pending');
});

it('outputs JSON format with --json and --failed options', function (): void {
    // Create reviews with different statuses
    $reviewId1 = $this->databaseService->recordReviewStarted('f-task1', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId1, true, [], []);

    $reviewId2 = $this->databaseService->recordReviewStarted('f-task2', 'gemini');
    $this->databaseService->recordReviewCompleted($reviewId2, false, ['tests_failing'], []);

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--json' => true, '--failed' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($reviewId2);
    expect($data[0]['status'])->toBe('failed');
});

it('shows issues for failed reviews in formatted output', function (): void {
    $reviewId = $this->databaseService->recordReviewStarted('f-abc123', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId, false, ['uncommitted_changes', 'tests_failing'], []);

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    expect($output)->toContain('[uncommitted_changes, tests_failing]');
});

it('does not show issues for passed reviews in formatted output', function (): void {
    $reviewId = $this->databaseService->recordReviewStarted('f-abc123', 'claude');
    $this->databaseService->recordReviewCompleted($reviewId, true, [], []);

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    expect($output)->not->toContain('[]');
    expect($output)->toContain('passed');
});

it('orders reviews by started_at descending', function (): void {
    $now = new \DateTime;
    $oneMinuteAgo = (clone $now)->modify('-1 minute');
    $twoMinutesAgo = (clone $now)->modify('-2 minutes');
    $threeMinutesAgo = (clone $now)->modify('-3 minutes');

    $reviewId1 = $this->databaseService->recordReviewStarted('f-task1', 'claude');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE id = ?',
        [$threeMinutesAgo->format('c'), $reviewId1]
    );

    $reviewId2 = $this->databaseService->recordReviewStarted('f-task2', 'claude');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE id = ?',
        [$oneMinuteAgo->format('c'), $reviewId2]
    );

    $reviewId3 = $this->databaseService->recordReviewStarted('f-task3', 'claude');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE id = ?',
        [$twoMinutesAgo->format('c'), $reviewId3]
    );

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    // Check order: task2 (1m ago) should appear first, then task3 (2m ago), then task1 (3m ago)
    $posTask2 = strpos($output, 'f-task2');
    $posTask3 = strpos($output, 'f-task3');
    $posTask1 = strpos($output, 'f-task1');

    expect($posTask2)->toBeLessThan($posTask3);
    expect($posTask3)->toBeLessThan($posTask1);
});
