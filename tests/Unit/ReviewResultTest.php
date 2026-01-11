<?php

declare(strict_types=1);

use App\Process\ReviewResult;

it('creates a review result with all properties', function (): void {
    $result = new ReviewResult(
        taskId: 'f-123456',
        passed: true,
        issues: [],
        completedAt: '2024-01-01T10:00:00Z'
    );

    expect($result->taskId)->toBe('f-123456');
    expect($result->passed)->toBeTrue();
    expect($result->issues)->toBe([]);
    expect($result->completedAt)->toBe('2024-01-01T10:00:00Z');
});

it('creates a review result with issues', function (): void {
    $result = new ReviewResult(
        taskId: 'f-abcdef',
        passed: false,
        issues: ['Modified files not committed: src/Service.php', 'Tests failed in UserServiceTest'],
        completedAt: '2024-01-01T11:00:00Z'
    );

    expect($result->passed)->toBeFalse();
    expect($result->issues)->toBe(['Modified files not committed: src/Service.php', 'Tests failed in UserServiceTest']);
});

it('creates a passed review result with no issues', function (): void {
    $result = new ReviewResult(
        taskId: 'f-xyz789',
        passed: true,
        issues: [],
        completedAt: '2024-01-01T12:00:00Z'
    );

    expect($result->passed)->toBeTrue();
    expect($result->issues)->toBeEmpty();
});

it('creates a failed review result with multiple issues', function (): void {
    $result = new ReviewResult(
        taskId: 'f-task123',
        passed: false,
        issues: ['Missing validation for email field', 'Unit test coverage below threshold', 'Documentation not updated'],
        completedAt: '2024-01-01T13:00:00Z'
    );

    expect($result->passed)->toBeFalse();
    expect($result->issues)->toHaveCount(3);
    expect($result->issues)->toContain('Missing validation for email field', 'Unit test coverage below threshold', 'Documentation not updated');
});
