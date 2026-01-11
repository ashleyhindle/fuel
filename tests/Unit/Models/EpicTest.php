<?php

declare(strict_types=1);

use App\Enums\EpicStatus;
use App\Models\Epic;

test('fromArray creates Epic instance', function (): void {
    $data = [
        'id' => 1,
        'short_id' => 'e-abc123',
        'title' => 'Test Epic',
        'description' => 'Test description',
        'status' => EpicStatus::Planning->value,
        'created_at' => '2024-01-01 12:00:00',
        'updated_at' => '2024-01-01 12:00:00',
        'reviewed_at' => null,
    ];

    $epic = Epic::fromArray($data);

    expect($epic)->toBeInstanceOf(Epic::class);
    expect($epic->id)->toBe(1);
    expect($epic->short_id)->toBe('e-abc123');
    expect($epic->title)->toBe('Test Epic');
    expect($epic->description)->toBe('Test description');
});

test('magic __get provides access to properties', function (): void {
    $epic = Epic::fromArray([
        'id' => 1,
        'short_id' => 'e-abc123',
        'title' => 'Test Epic',
        'description' => 'Test description',
        'status' => EpicStatus::Planning->value,
        'created_at' => '2024-01-01 12:00:00',
        'updated_at' => '2024-01-01 12:00:00',
        'reviewed_at' => null,
    ]);

    expect($epic->id)->toBe(1);
    expect($epic->short_id)->toBe('e-abc123');
    expect($epic->title)->toBe('Test Epic');
    expect($epic->description)->toBe('Test description');
    expect($epic->status)->toBe(EpicStatus::Planning->value);
    expect($epic->created_at)->toBe('2024-01-01 12:00:00');
    expect($epic->updated_at)->toBe('2024-01-01 12:00:00');
    expect($epic->reviewed_at)->toBeNull();
});

test('isPlanning returns true when status is planning', function (): void {
    $epic = Epic::fromArray(['status' => EpicStatus::Planning->value]);
    expect($epic->isPlanning())->toBeTrue();
});

test('isPlanning returns false when status is not planning', function (): void {
    $approvedEpic = Epic::fromArray(['status' => EpicStatus::Approved->value]);
    expect($approvedEpic->isPlanning())->toBeFalse();

    $inProgressEpic = Epic::fromArray(['status' => EpicStatus::InProgress->value]);
    expect($inProgressEpic->isPlanning())->toBeFalse();
});

test('isApproved returns true when status is approved', function (): void {
    $epic = Epic::fromArray(['status' => EpicStatus::Approved->value]);
    expect($epic->isApproved())->toBeTrue();
});

test('isApproved returns false when status is not approved', function (): void {
    $planningEpic = Epic::fromArray(['status' => EpicStatus::Planning->value]);
    expect($planningEpic->isApproved())->toBeFalse();

    $inProgressEpic = Epic::fromArray(['status' => EpicStatus::InProgress->value]);
    expect($inProgressEpic->isApproved())->toBeFalse();
});

test('isReviewed returns true when reviewed_at is set', function (): void {
    $epic = Epic::fromArray(['reviewed_at' => '2024-01-01 12:00:00']);
    expect($epic->isReviewed())->toBeTrue();
});

test('isReviewed returns false when reviewed_at is null', function (): void {
    $epic = Epic::fromArray(['reviewed_at' => null]);
    expect($epic->isReviewed())->toBeFalse();
});

test('isReviewed returns false when reviewed_at is empty string', function (): void {
    $epic = Epic::fromArray(['reviewed_at' => '']);
    expect($epic->isReviewed())->toBeFalse();
});

test('isPlanningOrInProgress returns true for planning status', function (): void {
    $epic = Epic::fromArray(['status' => EpicStatus::Planning->value]);
    expect($epic->isPlanningOrInProgress())->toBeTrue();
});

test('isPlanningOrInProgress returns true for in_progress status', function (): void {
    $epic = Epic::fromArray(['status' => EpicStatus::InProgress->value]);
    expect($epic->isPlanningOrInProgress())->toBeTrue();
});

test('isPlanningOrInProgress returns false for approved status', function (): void {
    $epic = Epic::fromArray(['status' => EpicStatus::Approved->value]);
    expect($epic->isPlanningOrInProgress())->toBeFalse();
});

test('isPlanningOrInProgress returns false for other statuses', function (): void {
    $epic = Epic::fromArray(['status' => EpicStatus::ReviewPending->value]);
    expect($epic->isPlanningOrInProgress())->toBeFalse();
});
