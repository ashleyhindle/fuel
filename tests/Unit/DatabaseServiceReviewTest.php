<?php

declare(strict_types=1);

use App\Models\Review;
use App\Services\DatabaseService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    $this->dbPath = $this->tempDir.'/test-agent.db';
    $this->service = new DatabaseService($this->dbPath);
    $this->service->initialize();
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

/**
 * Helper to create a task for testing reviews (needed for FK relationship).
 */
function createTestTask(DatabaseService $service, string $shortId): void
{
    $service->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$shortId, 'Test Task', 'open', now()->toIso8601String(), now()->toIso8601String()]
    );
}

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

    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    expect($reviewId)->toStartWith('r-');
    expect($reviewId)->toHaveLength(8); // 'r-' + 6 hex chars

    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE short_id = ?', [$reviewId]);

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

    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    $this->service->recordReviewCompleted($reviewId, true, []);

    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE short_id = ?', [$reviewId]);

    expect($review['status'])->toBe('passed');
    expect($review['issues'])->toBe('[]');
    expect($review['completed_at'])->not->toBeNull();
});

it('records review completed with failures and issues', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    $issues = ['Modified files not committed: src/Service.php', 'Tests failed in UserServiceTest'];

    $this->service->recordReviewCompleted($reviewId, false, $issues);

    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE short_id = ?', [$reviewId]);

    expect($review['status'])->toBe('failed');
    expect(json_decode((string) $review['issues'], true))->toBe($issues);
    expect($review['completed_at'])->not->toBeNull();
});

it('gets reviews for task with correct data', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    // Create multiple reviews for the same task
    $reviewId1 = $this->service->recordReviewStarted('f-123456', 'claude');
    $reviewId2 = $this->service->recordReviewStarted('f-123456', 'gemini');
    $reviewId3 = $this->service->recordReviewStarted('f-123456', 'claude');

    // Complete some reviews
    $this->service->recordReviewCompleted($reviewId1, true, []);
    $this->service->recordReviewCompleted($reviewId2, false, ['Tests failed in ServiceTest']);

    $reviews = $this->service->getReviewsForTask('f-123456');

    expect($reviews)->toHaveCount(3);

    // Verify all items are Review models
    foreach ($reviews as $review) {
        expect($review)->toBeInstanceOf(Review::class);
    }

    // Extract the review IDs (now short_ids in the public interface)
    $reviewIds = array_map(fn ($r) => $r->id, $reviews);
    expect($reviewIds)->toContain($reviewId1);
    expect($reviewIds)->toContain($reviewId2);
    expect($reviewIds)->toContain($reviewId3);

    // Verify task_id is returned as short_id
    foreach ($reviews as $review) {
        expect($review->task_id)->toBe('f-123456');
    }

    // Find review2 and check JSON fields are decoded via issues() method
    $review2 = collect($reviews)->first(fn ($r): bool => $r->id === $reviewId2);
    expect($review2->issues())->toBe(['Tests failed in ServiceTest']);

    // Find review3 (pending) and check empty arrays
    $review3 = collect($reviews)->first(fn ($r): bool => $r->id === $reviewId3);
    expect($review3->issues())->toBe([]);
});

it('gets reviews for task returns empty array when no reviews exist', function (): void {
    $reviews = $this->service->getReviewsForTask('f-nonexistent');

    expect($reviews)->toBe([]);
});

it('gets pending reviews returns only pending reviews', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $reviewId1 = $this->service->recordReviewStarted('f-task1', 'claude');
    usleep(10000);
    $reviewId2 = $this->service->recordReviewStarted('f-task2', 'gemini');
    usleep(10000);
    $reviewId3 = $this->service->recordReviewStarted('f-task3', 'claude');

    // Complete reviews 1 and 3
    $this->service->recordReviewCompleted($reviewId1, true, []);
    $this->service->recordReviewCompleted($reviewId3, false, ['Uncommitted changes detected']);

    $pendingReviews = $this->service->getPendingReviews();

    expect($pendingReviews)->toHaveCount(1);
    expect($pendingReviews[0])->toBeInstanceOf(Review::class);
    expect($pendingReviews[0]->id)->toBe($reviewId2);
    expect($pendingReviews[0]->status)->toBe('pending');
    expect($pendingReviews[0]->task_id)->toBe('f-task2');
});

it('gets pending reviews returns all pending reviews', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $reviewId1 = $this->service->recordReviewStarted('f-task1', 'claude');
    $reviewId2 = $this->service->recordReviewStarted('f-task2', 'gemini');
    $reviewId3 = $this->service->recordReviewStarted('f-task3', 'claude');

    $pendingReviews = $this->service->getPendingReviews();

    expect($pendingReviews)->toHaveCount(3);

    $reviewIds = array_map(fn ($r) => $r->id, $pendingReviews);
    expect($reviewIds)->toContain($reviewId1);
    expect($reviewIds)->toContain($reviewId2);
    expect($reviewIds)->toContain($reviewId3);
});

it('gets pending reviews returns empty array when no pending reviews exist', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-task1');

    $reviewId = $this->service->recordReviewStarted('f-task1', 'claude');
    $this->service->recordReviewCompleted($reviewId, true, []);

    $pendingReviews = $this->service->getPendingReviews();

    expect($pendingReviews)->toBe([]);
});

it('decodes json fields correctly for null values', function (): void {
    // Create task first for FK relationship
    createTestTask($this->service, 'f-123456');

    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    // Review is pending, so issues is NULL in DB
    $reviews = $this->service->getReviewsForTask('f-123456');

    expect($reviews)->toHaveCount(1);
    expect($reviews[0])->toBeInstanceOf(Review::class);
    expect($reviews[0]->issues())->toBe([]);
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

    $reviewId1 = $this->service->recordReviewStarted('f-task1', 'claude');
    $this->service->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$threeMinutesAgo->format('c'), $reviewId1]
    );

    $reviewId2 = $this->service->recordReviewStarted('f-task2', 'gemini');
    $this->service->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$oneMinuteAgo->format('c'), $reviewId2]
    );

    $reviewId3 = $this->service->recordReviewStarted('f-task3', 'claude');
    $this->service->query(
        'UPDATE reviews SET started_at = ? WHERE short_id = ?',
        [$twoMinutesAgo->format('c'), $reviewId3]
    );

    $reviews = $this->service->getAllReviews();

    expect($reviews)->toHaveCount(3);
    // Verify all are Review models
    foreach ($reviews as $review) {
        expect($review)->toBeInstanceOf(Review::class);
    }

    // Should be ordered by started_at DESC, so newest first
    expect($reviews[0]->id)->toBe($reviewId2); // 1 minute ago (newest)
    expect($reviews[1]->id)->toBe($reviewId3); // 2 minutes ago
    expect($reviews[2]->id)->toBe($reviewId1); // 3 minutes ago (oldest)
});

it('gets all reviews filters by status', function (): void {
    // Create tasks first for FK relationship
    createTestTask($this->service, 'f-task1');
    createTestTask($this->service, 'f-task2');
    createTestTask($this->service, 'f-task3');

    $reviewId1 = $this->service->recordReviewStarted('f-task1', 'claude');
    $this->service->recordReviewCompleted($reviewId1, true, []);

    $reviewId2 = $this->service->recordReviewStarted('f-task2', 'gemini');
    // Leave as pending

    $reviewId3 = $this->service->recordReviewStarted('f-task3', 'claude');
    $this->service->recordReviewCompleted($reviewId3, false, ['Tests failed']);

    $failedReviews = $this->service->getAllReviews('failed');
    expect($failedReviews)->toHaveCount(1);
    expect($failedReviews[0])->toBeInstanceOf(Review::class);
    expect($failedReviews[0]->id)->toBe($reviewId3);

    $pendingReviews = $this->service->getAllReviews('pending');
    expect($pendingReviews)->toHaveCount(1);
    expect($pendingReviews[0])->toBeInstanceOf(Review::class);
    expect($pendingReviews[0]->id)->toBe($reviewId2);

    $passedReviews = $this->service->getAllReviews('passed');
    expect($passedReviews)->toHaveCount(1);
    expect($passedReviews[0])->toBeInstanceOf(Review::class);
    expect($passedReviews[0]->id)->toBe($reviewId1);
});

it('gets all reviews respects limit', function (): void {
    // Create tasks and reviews
    for ($i = 1; $i <= 15; $i++) {
        createTestTask($this->service, 'f-task' . $i);
        $this->service->recordReviewStarted('f-task' . $i, 'claude');
    }

    $reviews = $this->service->getAllReviews(null, 10);
    expect($reviews)->toHaveCount(10);

    $reviews = $this->service->getAllReviews(null, 5);
    expect($reviews)->toHaveCount(5);
});

it('gets all reviews with status and limit', function (): void {
    // Create 10 passed and 10 failed reviews
    for ($i = 1; $i <= 10; $i++) {
        createTestTask($this->service, 'f-passed' . $i);
        $reviewId = $this->service->recordReviewStarted('f-passed' . $i, 'claude');
        $this->service->recordReviewCompleted($reviewId, true, []);
    }

    for ($i = 1; $i <= 10; $i++) {
        createTestTask($this->service, 'f-failed' . $i);
        $reviewId = $this->service->recordReviewStarted('f-failed' . $i, 'claude');
        $this->service->recordReviewCompleted($reviewId, false, ['Tests failed']);
    }

    $failedReviews = $this->service->getAllReviews('failed', 5);
    expect($failedReviews)->toHaveCount(5);
    expect($failedReviews[0])->toBeInstanceOf(Review::class);
    expect($failedReviews[0]->status)->toBe('failed');
});

it('allows reviews with null task_id when task does not exist', function (): void {
    // Record a review for a task that doesn't exist (null FK)
    $reviewId = $this->service->recordReviewStarted('f-nonexistent', 'claude');

    expect($reviewId)->toStartWith('r-');

    // The review should be created with null task_id
    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE short_id = ?', [$reviewId]);
    expect($review)->not->toBeNull();
    expect($review['task_id'])->toBeNull();
});

it('returns null task_id for reviews with orphaned tasks', function (): void {
    // Create task and review
    createTestTask($this->service, 'f-orphan');
    $reviewId = $this->service->recordReviewStarted('f-orphan', 'claude');

    // Delete the task
    $this->service->query('DELETE FROM tasks WHERE short_id = ?', ['f-orphan']);

    // Get all reviews - the task_id should resolve to null
    $reviews = $this->service->getAllReviews();
    expect($reviews)->toHaveCount(1);
    expect($reviews[0])->toBeInstanceOf(Review::class);
    expect($reviews[0]->id)->toBe($reviewId);
    expect($reviews[0]->task_id)->toBeNull();
});
