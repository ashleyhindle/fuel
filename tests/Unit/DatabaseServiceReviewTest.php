<?php

declare(strict_types=1);

use App\Services\DatabaseService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    $this->dbPath = $this->tempDir.'/test-agent.db';
    $this->service = new DatabaseService($this->dbPath);
    $this->service->initialize();
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('creates reviews table with correct schema', function () {
    $columns = $this->service->fetchAll('PRAGMA table_info(reviews)');

    expect($columns)->toHaveCount(8);
    expect(array_column($columns, 'name'))->toBe([
        'id',
        'task_id',
        'agent',
        'status',
        'issues',
        'followup_task_ids',
        'started_at',
        'completed_at',
    ]);
});

it('creates index on reviews table', function () {
    $indexes = $this->service->fetchAll('PRAGMA index_list(reviews)');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('idx_reviews_task');
});

it('records review started and returns review id', function () {
    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    expect($reviewId)->toStartWith('r-');
    expect($reviewId)->toHaveLength(8); // 'r-' + 6 hex chars

    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE id = ?', [$reviewId]);

    expect($review)->not->toBeNull();
    expect($review['task_id'])->toBe('f-123456');
    expect($review['agent'])->toBe('claude');
    expect($review['status'])->toBe('pending');
    expect($review['started_at'])->not->toBeNull();
    expect($review['completed_at'])->toBeNull();
});

it('records review completed with pass', function () {
    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    $this->service->recordReviewCompleted($reviewId, true, [], []);

    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE id = ?', [$reviewId]);

    expect($review['status'])->toBe('passed');
    expect($review['issues'])->toBe('[]');
    expect($review['followup_task_ids'])->toBe('[]');
    expect($review['completed_at'])->not->toBeNull();
});

it('records review completed with failures and issues', function () {
    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    $issues = ['uncommitted_changes', 'tests_failing'];
    $followupTaskIds = ['f-abc123', 'f-def456'];

    $this->service->recordReviewCompleted($reviewId, false, $issues, $followupTaskIds);

    $review = $this->service->fetchOne('SELECT * FROM reviews WHERE id = ?', [$reviewId]);

    expect($review['status'])->toBe('failed');
    expect(json_decode($review['issues'], true))->toBe($issues);
    expect(json_decode($review['followup_task_ids'], true))->toBe($followupTaskIds);
    expect($review['completed_at'])->not->toBeNull();
});

it('gets reviews for task with correct data', function () {
    // Create multiple reviews for the same task
    $reviewId1 = $this->service->recordReviewStarted('f-123456', 'claude');
    $reviewId2 = $this->service->recordReviewStarted('f-123456', 'gemini');
    $reviewId3 = $this->service->recordReviewStarted('f-123456', 'claude');

    // Complete some reviews
    $this->service->recordReviewCompleted($reviewId1, true, [], []);
    $this->service->recordReviewCompleted($reviewId2, false, ['tests_failing'], ['f-follow1']);

    $reviews = $this->service->getReviewsForTask('f-123456');

    expect($reviews)->toHaveCount(3);

    // Extract the review IDs
    $reviewIds = array_column($reviews, 'id');
    expect($reviewIds)->toContain($reviewId1);
    expect($reviewIds)->toContain($reviewId2);
    expect($reviewIds)->toContain($reviewId3);

    // Find review2 and check JSON fields are decoded
    $review2 = collect($reviews)->firstWhere('id', $reviewId2);
    expect($review2['issues'])->toBe(['tests_failing']);
    expect($review2['followup_task_ids'])->toBe(['f-follow1']);

    // Find review3 (pending) and check empty arrays
    $review3 = collect($reviews)->firstWhere('id', $reviewId3);
    expect($review3['issues'])->toBe([]);
    expect($review3['followup_task_ids'])->toBe([]);
});

it('gets reviews for task returns empty array when no reviews exist', function () {
    $reviews = $this->service->getReviewsForTask('f-nonexistent');

    expect($reviews)->toBe([]);
});

it('gets pending reviews returns only pending reviews', function () {
    $reviewId1 = $this->service->recordReviewStarted('f-task1', 'claude');
    usleep(10000);
    $reviewId2 = $this->service->recordReviewStarted('f-task2', 'gemini');
    usleep(10000);
    $reviewId3 = $this->service->recordReviewStarted('f-task3', 'claude');

    // Complete reviews 1 and 3
    $this->service->recordReviewCompleted($reviewId1, true, [], []);
    $this->service->recordReviewCompleted($reviewId3, false, ['uncommitted_changes'], []);

    $pendingReviews = $this->service->getPendingReviews();

    expect($pendingReviews)->toHaveCount(1);
    expect($pendingReviews[0]['id'])->toBe($reviewId2);
    expect($pendingReviews[0]['status'])->toBe('pending');
    expect($pendingReviews[0]['task_id'])->toBe('f-task2');
});

it('gets pending reviews returns all pending reviews', function () {
    $reviewId1 = $this->service->recordReviewStarted('f-task1', 'claude');
    $reviewId2 = $this->service->recordReviewStarted('f-task2', 'gemini');
    $reviewId3 = $this->service->recordReviewStarted('f-task3', 'claude');

    $pendingReviews = $this->service->getPendingReviews();

    expect($pendingReviews)->toHaveCount(3);

    $reviewIds = array_column($pendingReviews, 'id');
    expect($reviewIds)->toContain($reviewId1);
    expect($reviewIds)->toContain($reviewId2);
    expect($reviewIds)->toContain($reviewId3);
});

it('gets pending reviews returns empty array when no pending reviews exist', function () {
    $reviewId = $this->service->recordReviewStarted('f-task1', 'claude');
    $this->service->recordReviewCompleted($reviewId, true, [], []);

    $pendingReviews = $this->service->getPendingReviews();

    expect($pendingReviews)->toBe([]);
});

it('decodes json fields correctly for null values', function () {
    $reviewId = $this->service->recordReviewStarted('f-123456', 'claude');

    // Review is pending, so issues and followup_task_ids are NULL in DB
    $reviews = $this->service->getReviewsForTask('f-123456');

    expect($reviews)->toHaveCount(1);
    expect($reviews[0]['issues'])->toBe([]);
    expect($reviews[0]['followup_task_ids'])->toBe([]);
});
