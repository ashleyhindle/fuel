<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use App\Repositories\ReviewRepository;
use App\Services\DatabaseService;

/**
 * Helper to create a task for testing reviews (needed for FK relationship).
 * Alias for createTaskForReview from Pest.php to maintain backward compatibility.
 */
function createTestTask(DatabaseService $service, string $shortId): void
{
    createTaskForReview($service, $shortId);
}

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    $this->dbPath = $this->tempDir.'/test-agent.db';
    $this->service = new DatabaseService($this->dbPath);
    config(['database.connections.sqlite.database' => $this->dbPath]);
    Artisan::call('migrate', ['--force' => true]);
    $this->reviewRepo = new ReviewRepository($this->service);
});

afterEach(function (): void {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    // Clean up WAL and SHM files if they exist
    foreach (['-wal', '-shm'] as $suffix) {
        $file = $this->dbPath.$suffix;
        if (file_exists($file)) {
            unlink($file);
        }
    }

    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('creates reviews table with correct schema', function (): void {
    $columns = $this->service->fetchAll('PRAGMA table_info(reviews)');

    expect($columns)->toHaveCount(9);
    expect(array_column($columns, 'name'))->toBe([
        'id',
        'short_id',
        'task_id',
        'agent',
        'status',
        'issues',
        'started_at',
        'completed_at',
        'run_id',
    ]);

    // Verify id is INTEGER PRIMARY KEY
    $idColumn = collect($columns)->firstWhere('name', 'id');
    expect(strtoupper((string) $idColumn['type']))->toBe('INTEGER');
    expect($idColumn['pk'])->toBe(1);

    // Verify short_id is TEXT UNIQUE NOT NULL
    $shortIdColumn = collect($columns)->firstWhere('name', 'short_id');
    expect(strtoupper((string) $shortIdColumn['type']))->toBe('TEXT');
    expect($shortIdColumn['notnull'])->toBe(1);

    // Verify task_id is INTEGER (FK to tasks)
    $taskIdColumn = collect($columns)->firstWhere('name', 'task_id');
    expect(strtoupper((string) $taskIdColumn['type']))->toBe('INTEGER');
});

it('creates indexes on reviews table', function (): void {
    $indexes = $this->service->fetchAll('PRAGMA index_list(reviews)');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('idx_reviews_short_id');
    expect($indexNames)->toContain('idx_reviews_task_id');
    expect($indexNames)->toContain('idx_reviews_status');
    expect($indexNames)->toContain('idx_reviews_run_id');
});

it('records review started and returns review id', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    $reviewId = 'r-123456';
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId, 'claude');

    expect($reviewId)->toStartWith('r-');
    expect($reviewId)->toHaveLength(8); // 'r-' + 6 hex chars

    $review = $this->reviewRepo->findByShortId($reviewId);

    expect($review)->not->toBeNull();
    // task_id is now an integer FK - verify it's not null (the task exists)
    expect($review['task_id'])->not->toBeNull();
    expect($review['agent'])->toBe('claude');
    expect($review['status'])->toBe('pending');
    expect($review['started_at'])->not->toBeNull();
    expect($review['completed_at'])->toBeNull();
});

it('records review completed with pass', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    $reviewId = 'r-pass01';
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId, 'claude');

    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    $review = $this->reviewRepo->findByShortId($reviewId);

    expect($review['status'])->toBe('passed');
    expect($review['issues'])->toBe('[]');
    expect($review['completed_at'])->not->toBeNull();
});

it('records review completed with failures and issues', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    $reviewId = 'r-fail01';
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId, 'claude');

    $issues = ['Modified files not committed: src/Service.php', 'Tests failed in UserServiceTest'];

    $this->reviewRepo->markAsCompleted($reviewId, false, $issues);

    $review = $this->reviewRepo->findByShortId($reviewId);

    expect($review['status'])->toBe('failed');
    expect(json_decode((string) $review['issues'], true))->toBe($issues);
    expect($review['completed_at'])->not->toBeNull();
});

it('gets reviews for task with correct data', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    // Create multiple reviews for the same task
    $reviewId1 = 'r-000001';
    $reviewId2 = 'r-000002';
    $reviewId3 = 'r-000003';
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId1, 'claude');
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId2, 'gemini');
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId3, 'claude');

    // Complete some reviews
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);
    $this->reviewRepo->markAsCompleted($reviewId2, false, ['Tests failed in ServiceTest']);

    $taskId = $this->reviewRepo->resolveTaskId('f-123456');
    if ($taskId === null) {
        throw new RuntimeException("Task 'f-123456' not found.");
    }

    $reviews = $this->reviewRepo->findByTaskId($taskId);

    expect($reviews)->toHaveCount(3);

    // Extract the review IDs (short_ids in the repository)
    $reviewIds = array_column($reviews, 'short_id');
    expect($reviewIds)->toContain($reviewId1);
    expect($reviewIds)->toContain($reviewId2);
    expect($reviewIds)->toContain($reviewId3);

    // Verify task_id is returned as integer FK
    foreach ($reviews as $review) {
        expect($review['task_id'])->toBe($taskId);
    }

    // Find review2 and check JSON fields are decoded
    $review2 = collect($reviews)->first(fn (array $review): bool => $review['short_id'] === $reviewId2);
    expect(json_decode((string) $review2['issues'], true))->toBe(['Tests failed in ServiceTest']);

    // Find review3 (pending) and check empty arrays
    $review3 = collect($reviews)->first(fn (array $review): bool => $review['short_id'] === $reviewId3);
    expect($review3['issues'])->toBeNull();
});

it('gets reviews for task returns empty array when no reviews exist', function (): void {
    $reviews = $this->reviewRepo->findByTaskId(9999);

    expect($reviews)->toBe([]);
});

it('gets pending reviews returns only pending reviews', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $reviewId1 = 'r-101001';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    usleep(10000);
    $reviewId2 = 'r-101002';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    usleep(10000);
    $reviewId3 = 'r-101003';
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');

    // Complete reviews 1 and 3
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);
    $this->reviewRepo->markAsCompleted($reviewId3, false, ['Uncommitted changes detected']);

    $pendingReviews = $this->reviewRepo->getPendingReviews();

    expect($pendingReviews)->toHaveCount(1);
    $taskId = $this->reviewRepo->resolveTaskId('f-task2');
    expect($pendingReviews[0]['short_id'])->toBe($reviewId2);
    expect($pendingReviews[0]['status'])->toBe('pending');
    expect($pendingReviews[0]['task_id'])->toBe($taskId);
});

it('gets pending reviews returns all pending reviews', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $reviewId1 = 'r-102001';
    $reviewId2 = 'r-102002';
    $reviewId3 = 'r-102003';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');

    $pendingReviews = $this->reviewRepo->getPendingReviews();

    expect($pendingReviews)->toHaveCount(3);

    $reviewIds = array_column($pendingReviews, 'short_id');
    expect($reviewIds)->toContain($reviewId1);
    expect($reviewIds)->toContain($reviewId2);
    expect($reviewIds)->toContain($reviewId3);
});

it('gets pending reviews returns empty array when no pending reviews exist', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-task1');

    $reviewId = 'r-103001';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId, true, []);

    $pendingReviews = $this->reviewRepo->getPendingReviews();

    expect($pendingReviews)->toBe([]);
});

it('decodes json fields correctly for null values', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    $reviewId = 'r-null01';
    createReviewForTask($this->reviewRepo, 'f-123456', $reviewId, 'claude');

    // Review is pending, so issues is NULL in DB
    $taskId = $this->reviewRepo->resolveTaskId('f-123456');
    if ($taskId === null) {
        throw new RuntimeException("Task 'f-123456' not found.");
    }

    $reviews = $this->reviewRepo->findByTaskId($taskId);

    expect($reviews)->toHaveCount(1);
    expect($reviews[0]['issues'])->toBeNull();
});

it('gets all reviews returns all reviews ordered by started_at descending', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $now = new \DateTime;
    $oneMinuteAgo = (clone $now)->modify('-1 minute');
    $twoMinutesAgo = (clone $now)->modify('-2 minutes');
    $threeMinutesAgo = (clone $now)->modify('-3 minutes');

    $reviewId1 = 'r-order1';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->service->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$threeMinutesAgo->format('c'), $reviewId1]
    );

    $reviewId2 = 'r-order2';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    $this->service->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$oneMinuteAgo->format('c'), $reviewId2]
    );

    $reviewId3 = 'r-order3';
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');
    $this->service->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$twoMinutesAgo->format('c'), $reviewId3]
    );

    $reviews = $this->reviewRepo->getAllWithLimit();

    expect($reviews)->toHaveCount(3);

    // Should be ordered by started_at DESC, so newest first
    expect($reviews[0]['short_id'])->toBe($reviewId2); // 1 minute ago (newest)
    expect($reviews[1]['short_id'])->toBe($reviewId3); // 2 minutes ago
    expect($reviews[2]['short_id'])->toBe($reviewId1); // 3 minutes ago (oldest)
});

it('gets all reviews filters by status', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $reviewId1 = 'r-status1';
    createReviewForTask($this->reviewRepo, 'f-task1', $reviewId1, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId1, true, []);

    $reviewId2 = 'r-status2';
    createReviewForTask($this->reviewRepo, 'f-task2', $reviewId2, 'gemini');
    // Leave as pending

    $reviewId3 = 'r-status3';
    createReviewForTask($this->reviewRepo, 'f-task3', $reviewId3, 'claude');
    $this->reviewRepo->markAsCompleted($reviewId3, false, ['Tests failed']);

    $failedReviews = $this->reviewRepo->findByStatus('failed');
    expect($failedReviews)->toHaveCount(1);
    expect($failedReviews[0]['short_id'])->toBe($reviewId3);

    $pendingReviews = $this->reviewRepo->findByStatus('pending');
    expect($pendingReviews)->toHaveCount(1);
    expect($pendingReviews[0]['short_id'])->toBe($reviewId2);

    $passedReviews = $this->reviewRepo->findByStatus('passed');
    expect($passedReviews)->toHaveCount(1);
    expect($passedReviews[0]['short_id'])->toBe($reviewId1);
});

it('gets all reviews respects limit', function (): void {
    // Create tasks and reviews
    for ($i = 1; $i <= 15; $i++) {
        createTestTask($this->service, 'f-task'.$i);
        $reviewId = sprintf('r-%06d', $i);
        createReviewForTask($this->reviewRepo, 'f-task'.$i, $reviewId, 'claude');
    }

    $reviews = $this->reviewRepo->getAllWithLimit(10);
    expect($reviews)->toHaveCount(10);

    $reviews = $this->reviewRepo->getAllWithLimit(5);
    expect($reviews)->toHaveCount(5);
});

it('gets all reviews with status and limit', function (): void {
    // Create 10 passed and 10 failed reviews
    for ($i = 1; $i <= 10; $i++) {
        createTestTask($this->service, 'f-passed'.$i);
        $reviewId = sprintf('r-p%05d', $i);
        createReviewForTask($this->reviewRepo, 'f-passed'.$i, $reviewId, 'claude');
        $this->reviewRepo->markAsCompleted($reviewId, true, []);
    }

    for ($i = 1; $i <= 10; $i++) {
        createTestTask($this->service, 'f-failed'.$i);
        $reviewId = sprintf('r-f%05d', $i);
        createReviewForTask($this->reviewRepo, 'f-failed'.$i, $reviewId, 'claude');
        $this->reviewRepo->markAsCompleted($reviewId, false, ['Tests failed']);
    }

    $failedReviews = $this->reviewRepo->findByStatusWithLimit('failed', 5);
    expect($failedReviews)->toHaveCount(5);
    expect($failedReviews[0]['status'])->toBe('failed');
});

it('allows reviews with null task_id when task does not exist', function (): void {
    // Record a review for a task that doesn't exist (null FK)
    $reviewId = 'r-nullfk';
    $this->service->query(
        'INSERT INTO reviews (short_id, task_id, agent, status, started_at) VALUES (?, ?, ?, ?, ?)',
        [$reviewId, null, 'claude', 'pending', now()->toIso8601String()]
    );

    expect($reviewId)->toStartWith('r-');

    // The review should be created with null task_id
    $review = $this->reviewRepo->findByShortId($reviewId);
    expect($review)->not->toBeNull();
    expect($review['task_id'])->toBeNull();
});

it('returns null task_id for reviews with orphaned tasks', function (): void {
    // Create task and review
    createTestTask($this->service, 'f-orphan');
    $reviewId = 'r-orphan';
    createReviewForTask($this->reviewRepo, 'f-orphan', $reviewId, 'claude');

    // Delete the task
    $this->service->query('DELETE FROM tasks WHERE short_id = ?', ['f-orphan']);

    // Get all reviews - the task_id should resolve to null
    $review = $this->reviewRepo->findByShortId($reviewId);
    expect($review)->not->toBeNull();
    $resolvedShortId = $this->reviewRepo->resolveTaskShortId((int) $review['task_id']);
    expect($resolvedShortId)->toBeNull();
});
