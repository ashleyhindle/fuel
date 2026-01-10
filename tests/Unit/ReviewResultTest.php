<?php

declare(strict_types=1);

use App\Process\ReviewResult;

it('creates a review result with all properties', function (): void {
    $result = new ReviewResult(
        taskId: 'f-123456',
        passed: true,
        issues: [],
        followUpTaskIds: [],
        completedAt: '2024-01-01T10:00:00Z'
    );

    expect($result->taskId)->toBe('f-123456');
    expect($result->passed)->toBeTrue();
    expect($result->issues)->toBe([]);
    expect($result->followUpTaskIds)->toBe([]);
    expect($result->completedAt)->toBe('2024-01-01T10:00:00Z');
});

it('creates a review result with issues', function (): void {
    $result = new ReviewResult(
        taskId: 'f-abcdef',
        passed: false,
        issues: ['uncommitted_changes', 'tests_failing'],
        followUpTaskIds: ['f-follow1', 'f-follow2'],
        completedAt: '2024-01-01T11:00:00Z'
    );

    expect($result->passed)->toBeFalse();
    expect($result->issues)->toBe(['uncommitted_changes', 'tests_failing']);
    expect($result->followUpTaskIds)->toBe(['f-follow1', 'f-follow2']);
});

it('creates a passed review result with no issues', function (): void {
    $result = new ReviewResult(
        taskId: 'f-xyz789',
        passed: true,
        issues: [],
        followUpTaskIds: [],
        completedAt: '2024-01-01T12:00:00Z'
    );

    expect($result->passed)->toBeTrue();
    expect($result->issues)->toBeEmpty();
    expect($result->followUpTaskIds)->toBeEmpty();
});

it('creates a failed review result with follow-up tasks', function (): void {
    $result = new ReviewResult(
        taskId: 'f-task123',
        passed: false,
        issues: ['task_mismatch'],
        followUpTaskIds: ['f-fix1', 'f-fix2', 'f-fix3'],
        completedAt: '2024-01-01T13:00:00Z'
    );

    expect($result->passed)->toBeFalse();
    expect($result->issues)->toContain('task_mismatch');
    expect($result->followUpTaskIds)->toHaveCount(3);
    expect($result->followUpTaskIds)->toContain('f-fix1', 'f-fix2', 'f-fix3');
});
