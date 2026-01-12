<?php

declare(strict_types=1);

use App\Models\Review;

describe('Review Model', function (): void {
    it('creates a review instance', function (): void {
        $review = new Review([
            'short_id' => 'r-abc123',
            'task_id' => 42,
            'run_id' => 10,
            'agent' => 'claude-sonnet',
            'status' => 'completed',
            'issues' => ['test failed', 'uncommitted changes'],
            'started_at' => '2024-01-01T10:00:00Z',
            'completed_at' => '2024-01-01T10:05:00Z',
        ]);

        expect($review)->toBeInstanceOf(Review::class);
        expect($review->short_id)->toBe('r-abc123');
        expect($review->task_id)->toBe(42);
        expect($review->agent)->toBe('claude-sonnet');
        expect($review->status)->toBe('completed');
    });

    it('accesses properties', function (): void {
        $review = new Review([
            'short_id' => 'r-xyz789',
            'agent' => 'claude-opus',
            'status' => 'pending',
        ]);

        expect($review->short_id)->toBe('r-xyz789');
        expect($review->agent)->toBe('claude-opus');
        expect($review->status)->toBe('pending');
    });

    describe('issues() method', function (): void {
        it('parses valid issues array', function (): void {
            $review = new Review([
                'issues' => ['test failed', 'uncommitted changes', 'missing docs'],
            ]);

            $issues = $review->issues();

            expect($issues)->toBe(['test failed', 'uncommitted changes', 'missing docs']);
            expect($issues)->toHaveCount(3);
        });

        it('returns empty array when issues is null', function (): void {
            $review = new Review([
                'issues' => null,
            ]);

            expect($review->issues())->toBe([]);
        });

        it('returns empty array when issues is empty string', function (): void {
            $review = new Review([
                'issues' => '',
            ]);

            expect($review->issues())->toBe([]);
        });

        it('returns empty array when issues field is not set', function (): void {
            $review = new Review([
                'status' => 'completed',
            ]);

            expect($review->issues())->toBe([]);
        });

        it('returns empty array when issues is empty array', function (): void {
            $review = new Review([
                'issues' => [],
            ]);

            expect($review->issues())->toBe([]);
        });

        it('handles already-decoded array', function (): void {
            $review = new Review([
                'issues' => ['already', 'an', 'array'],
            ]);

            expect($review->issues())->toBe(['already', 'an', 'array']);
        });

        it('handles single issue in array', function (): void {
            $review = new Review([
                'issues' => ['single issue'],
            ]);

            expect($review->issues())->toBe(['single issue']);
        });
    });

    describe('isPending()', function (): void {
        it('returns true when status is pending', function (): void {
            $review = new Review(['status' => 'pending']);

            expect($review->isPending())->toBeTrue();
        });

        it('returns false when status is not pending', function (): void {
            $review = new Review(['status' => 'completed']);

            expect($review->isPending())->toBeFalse();
        });
    });

    describe('isCompleted()', function (): void {
        it('returns true when status is completed', function (): void {
            $review = new Review(['status' => 'completed']);

            expect($review->isCompleted())->toBeTrue();
        });

        it('returns false when status is not completed', function (): void {
            $review = new Review(['status' => 'pending']);

            expect($review->isCompleted())->toBeFalse();
        });
    });

    describe('hasPassed()', function (): void {
        it('returns true when there are no issues', function (): void {
            $review = new Review([
                'status' => 'completed',
                'issues' => [],
            ]);

            expect($review->hasPassed())->toBeTrue();
        });

        it('returns true when issues is null', function (): void {
            $review = new Review([
                'status' => 'completed',
                'issues' => null,
            ]);

            expect($review->hasPassed())->toBeTrue();
        });

        it('returns true when issues is empty string', function (): void {
            $review = new Review([
                'status' => 'completed',
                'issues' => '',
            ]);

            expect($review->hasPassed())->toBeTrue();
        });

        it('returns false when there are issues', function (): void {
            $review = new Review([
                'status' => 'completed',
                'issues' => ['test failed'],
            ]);

            expect($review->hasPassed())->toBeFalse();
        });

        it('works regardless of completion status', function (): void {
            // Pending review with no issues still "passes" the check
            $review = new Review([
                'status' => 'pending',
                'issues' => [],
            ]);

            expect($review->hasPassed())->toBeTrue();
        });
    });

    describe('hasFailed()', function (): void {
        it('returns true when completed with issues', function (): void {
            $review = new Review([
                'status' => 'completed',
                'issues' => ['test failed', 'uncommitted changes'],
            ]);

            expect($review->hasFailed())->toBeTrue();
        });

        it('returns false when completed with no issues', function (): void {
            $review = new Review([
                'status' => 'completed',
                'issues' => [],
            ]);

            expect($review->hasFailed())->toBeFalse();
        });

        it('returns false when pending even with issues', function (): void {
            // Not completed yet, so hasn't failed yet
            $review = new Review([
                'status' => 'pending',
                'issues' => ['some issue'],
            ]);

            expect($review->hasFailed())->toBeFalse();
        });

        it('returns false when pending with no issues', function (): void {
            $review = new Review([
                'status' => 'pending',
                'issues' => [],
            ]);

            expect($review->hasFailed())->toBeFalse();
        });
    });

    describe('getIssueCount()', function (): void {
        it('returns 0 when no issues', function (): void {
            $review = new Review(['issues' => '[]']);

            expect($review->getIssueCount())->toBe(0);
        });

        it('returns correct count for multiple issues', function (): void {
            $review = new Review([
                'issues' => ['issue 1', 'issue 2', 'issue 3'],
            ]);

            expect($review->getIssueCount())->toBe(3);
        });

        it('returns 1 for single issue', function (): void {
            $review = new Review([
                'issues' => ['only issue'],
            ]);

            expect($review->getIssueCount())->toBe(1);
        });

        it('returns 0 for null issues', function (): void {
            $review = new Review(['issues' => null]);

            expect($review->getIssueCount())->toBe(0);
        });

        it('returns 0 for empty string issues', function (): void {
            $review = new Review(['issues' => '']);

            expect($review->getIssueCount())->toBe(0);
        });
    });

    describe('Integration scenarios', function (): void {
        it('handles a typical passed review', function (): void {
            $review = new Review([
                'id' => 1,
                'short_id' => 'r-pass01',
                'task_id' => 42,
                'agent' => 'claude-sonnet',
                'status' => 'completed',
                'issues' => [],
                'started_at' => '2024-01-01T10:00:00Z',
                'completed_at' => '2024-01-01T10:05:00Z',
            ]);

            expect($review->isCompleted())->toBeTrue();
            expect($review->isPending())->toBeFalse();
            expect($review->hasPassed())->toBeTrue();
            expect($review->hasFailed())->toBeFalse();
            expect($review->getIssueCount())->toBe(0);
            expect($review->issues())->toBe([]);
        });

        it('handles a typical failed review', function (): void {
            $review = new Review([
                'id' => 2,
                'short_id' => 'r-fail01',
                'task_id' => 43,
                'agent' => 'claude-opus',
                'status' => 'completed',
                'issues' => ['Tests failing in UserServiceTest', 'Modified files not committed: src/UserService.php'],
                'started_at' => '2024-01-01T11:00:00Z',
                'completed_at' => '2024-01-01T11:10:00Z',
            ]);

            expect($review->isCompleted())->toBeTrue();
            expect($review->isPending())->toBeFalse();
            expect($review->hasPassed())->toBeFalse();
            expect($review->hasFailed())->toBeTrue();
            expect($review->getIssueCount())->toBe(2);
            expect($review->issues())->toBe([
                'Tests failing in UserServiceTest',
                'Modified files not committed: src/UserService.php',
            ]);
        });

        it('handles a pending review', function (): void {
            $review = new Review([
                'id' => 3,
                'short_id' => 'r-pend01',
                'task_id' => 44,
                'agent' => 'claude-sonnet',
                'status' => 'pending',
                'issues' => null,
                'started_at' => '2024-01-01T12:00:00Z',
                'completed_at' => null,
            ]);

            expect($review->isCompleted())->toBeFalse();
            expect($review->isPending())->toBeTrue();
            expect($review->hasPassed())->toBeTrue(); // No issues yet
            expect($review->hasFailed())->toBeFalse(); // Not completed
            expect($review->getIssueCount())->toBe(0);
            expect($review->issues())->toBe([]);
        });
    });
});
