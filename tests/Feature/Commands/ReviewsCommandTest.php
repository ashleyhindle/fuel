<?php

declare(strict_types=1);

use App\Repositories\ReviewRepository;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    // Bind our test DatabaseService instance
    $context->configureDatabase();
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    Artisan::call('migrate', ['--force' => true]);
    $this->app->singleton(ReviewRepository::class, fn (): ReviewRepository => new ReviewRepository($databaseService));

    $this->databaseService = $this->app->make(DatabaseService::class);
    $this->reviewRepo = $this->app->make(ReviewRepository::class);
});

afterEach(function (): void {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
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
    // Create tasks first (required for FK relationship)
    createTaskForReview($this->databaseService, 'f-abc123');
    createTaskForReview($this->databaseService, 'f-def456');
    createTaskForReview($this->databaseService, 'f-ghi789');

    // Create reviews with different statuses
    $now = new \DateTime;
    $twoMinutesAgo = (clone $now)->modify('-2 minutes');
    $fiveMinutesAgo = (clone $now)->modify('-5 minutes');
    $oneMinuteAgo = (clone $now)->modify('-1 minute');

    // Create passed review
    $reviewId1 = 'r-abc123';
    createReviewForTask($this->reviewRepo, 'f-abc123', $reviewId1, 'claude-sonnet');
    $this->databaseService->query(
        'UPDATE reviews SET status = ?, completed_at = ?, started_at = ? WHERE short_id = ?',
        ['passed', $now->format('c'), $twoMinutesAgo->format('c'), $reviewId1]
    );

    // Create failed review with issues
    $reviewId2 = 'r-def456';
    createReviewForTask($this->reviewRepo, 'f-def456', $reviewId2, 'claude-opus');
    $this->reviewRepo->markAsCompleted($reviewId2, false, ['uncommitted_changes', 'tests_failing']);
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$fiveMinutesAgo->format('c'), $reviewId2]
    );

    // Create pending review
    $reviewId3 = 'r-ghi789';
    createReviewForTask($this->reviewRepo, 'f-ghi789', $reviewId3, 'amp-free');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
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
    // Create 15 tasks and reviews
    for ($i = 1; $i <= 15; $i++) {
        createTaskForReview($this->databaseService, 'f-task'.$i);
        $reviewId = sprintf('r-%06d', $i);
        createReviewForTask($this->reviewRepo, 'f-task'.$i, $reviewId, 'claude');
        $this->reviewRepo->markAsCompleted($reviewId, true, []);
    }

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    // Count occurrences of task IDs
    $taskIdCount = substr_count($output, 'f-task');
    expect($taskIdCount)->toBe(10);
});

it('shows all reviews with --all option', function (): void {
    // Create 15 tasks and reviews
    for ($i = 1; $i <= 15; $i++) {
        createTaskForReview($this->databaseService, 'f-task'.$i);
        $reviewId = sprintf('r-%06d', $i);
        createReviewForTask($this->reviewRepo, 'f-task'.$i, $reviewId, 'claude');
        $this->reviewRepo->markAsCompleted($reviewId, true, []);
    }

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--all' => true]);
    $output = Artisan::output();

    // Count occurrences of task IDs
    $taskIdCount = substr_count($output, 'f-task');
    expect($taskIdCount)->toBe(15);
});

it('filters to pending reviews only with --pending option', function (): void {
    // Create tasks first
    createTaskForReview($this->databaseService, 'f-task1');
    createTaskForReview($this->databaseService, 'f-task2');
    createTaskForReview($this->databaseService, 'f-task3');
    createTaskForReview($this->databaseService, 'f-task4');

    // Create reviews with different statuses
    $reviewId1 = 'r-task01';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);

    $reviewId2 = 'r-task02';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    // Leave as pending

    $reviewId3 = 'r-task03';
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId3, false, ['tests_failing']);

    $reviewId4 = 'r-task04';
    createReviewForTask($this->reviewRepo, 'f-task4', $reviewId4, 'amp');
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
    // Create tasks first
    createTaskForReview($this->databaseService, 'f-task1');
    createTaskForReview($this->databaseService, 'f-task2');
    createTaskForReview($this->databaseService, 'f-task3');
    createTaskForReview($this->databaseService, 'f-task4');

    // Create reviews with different statuses
    $reviewId1 = 'r-fail01';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);

    $reviewId2 = 'r-fail02';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    // Leave as pending

    $reviewId3 = 'r-fail03';
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId3, false, ['tests_failing']);

    $reviewId4 = 'r-fail04';
    createReviewForTask($this->reviewRepo, 'f-task4', $reviewId4, 'amp');
    $this->reviewRepo->markAsCompleted($reviewId4, false, ['uncommitted_changes']);

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--failed' => true]);
    $output = Artisan::output();

    expect($output)->toContain('f-task3');
    expect($output)->toContain('f-task4');
    expect($output)->not->toContain('f-task1');
    expect($output)->not->toContain('f-task2');
    expect($output)->toContain('failed');
});

it('outputs JSON format with --json option', function (): void {
    // Create task first
    createTaskForReview($this->databaseService, 'f-abc123');

    // Create a review
    $reviewId = 'r-json01';
    createReviewForTask($this->reviewRepo, 'f-abc123', $reviewId, 'claude-sonnet');
    $this->reviewRepo->markAsCompleted($reviewId, false, ['tests_failing']);

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveCount(1);
    expect($data[0]['short_id'])->toBe($reviewId);
    expect($data[0]['task_id'])->toBe('f-abc123');
    expect($data[0]['agent'])->toBe('claude-sonnet');
    expect($data[0]['status'])->toBe('failed');
    expect($data[0]['issues'])->toBe(['tests_failing']);
});

it('outputs JSON format with --json and --pending options', function (): void {
    // Create tasks first
    createTaskForReview($this->databaseService, 'f-task1');
    createTaskForReview($this->databaseService, 'f-task2');

    // Create reviews with different statuses
    $reviewId1 = 'r-pend01';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);

    $reviewId2 = 'r-pend02';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    // Leave as pending

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--json' => true, '--pending' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveCount(1);
    expect($data[0]['short_id'])->toBe($reviewId2);
    expect($data[0]['status'])->toBe('pending');
});

it('outputs JSON format with --json and --failed options', function (): void {
    // Create tasks first
    createTaskForReview($this->databaseService, 'f-task1');
    createTaskForReview($this->databaseService, 'f-task2');

    // Create reviews with different statuses
    $reviewId1 = 'r-fjson1';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);

    $reviewId2 = 'r-fjson2';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    $this->reviewRepo->markAsCompleted($reviewId2, false, ['tests_failing']);

    Artisan::call('reviews', ['--cwd' => $this->tempDir, '--json' => true, '--failed' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveCount(1);
    expect($data[0]['short_id'])->toBe($reviewId2);
    expect($data[0]['status'])->toBe('failed');
});

it('shows issues for failed reviews in formatted output', function (): void {
    createTaskForReview($this->databaseService, 'f-abc123');
    $reviewId = 'r-iss01';
    createReviewForTask($this->reviewRepo, 'f-abc123', $reviewId, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId, false, ['uncommitted_changes', 'tests_failing']);

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    expect($output)->toContain('[uncommitted_changes, tests_failing]');
});

it('does not show issues for passed reviews in formatted output', function (): void {
    createTaskForReview($this->databaseService, 'f-abc123');
    $reviewId = 'r-iss02';
    createReviewForTask($this->reviewRepo, 'f-abc123', $reviewId, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    Artisan::call('reviews', ['--cwd' => $this->tempDir]);
    $output = Artisan::output();

    expect($output)->not->toContain('[]');
    expect($output)->toContain('passed');
});

it('orders reviews by started_at descending', function (): void {
    // Create tasks first
    createTaskForReview($this->databaseService, 'f-task1');
    createTaskForReview($this->databaseService, 'f-task2');
    createTaskForReview($this->databaseService, 'f-task3');

    $now = new \DateTime;
    $oneMinuteAgo = (clone $now)->modify('-1 minute');
    $twoMinutesAgo = (clone $now)->modify('-2 minutes');
    $threeMinutesAgo = (clone $now)->modify('-3 minutes');

    $reviewId1 = 'r-ord01';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$threeMinutesAgo->format('c'), $reviewId1]
    );

    $reviewId2 = 'r-ord02';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'claude');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$oneMinuteAgo->format('c'), $reviewId2]
    );

    $reviewId3 = 'r-ord03';
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');
    $this->databaseService->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
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
