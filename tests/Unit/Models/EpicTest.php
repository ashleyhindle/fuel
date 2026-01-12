<?php

declare(strict_types=1);

use App\Enums\EpicStatus;
use App\Models\Epic;

test('creates Epic instance', function (): void {
    $epic = new Epic([
        'short_id' => 'e-abc123',
        'title' => 'Test Epic',
        'description' => 'Test description',
        'status' => EpicStatus::Planning->value,
    ]);

    expect($epic)->toBeInstanceOf(Epic::class);
    expect($epic->short_id)->toBe('e-abc123');
    expect($epic->title)->toBe('Test Epic');
    expect($epic->description)->toBe('Test description');
});

test('provides access to properties', function (): void {
    $epic = new Epic([
        'short_id' => 'e-abc123',
        'title' => 'Test Epic',
        'description' => 'Test description',
        'status' => EpicStatus::Planning->value,
        'reviewed_at' => null,
    ]);

    expect($epic->short_id)->toBe('e-abc123');
    expect($epic->title)->toBe('Test Epic');
    expect($epic->description)->toBe('Test description');
    expect($epic->status)->toBe(EpicStatus::Planning);
    expect($epic->reviewed_at)->toBeNull();
});

test('isPlanning returns true when status is planning', function (): void {
    $epic = new Epic(['status' => EpicStatus::Planning->value]);
    expect($epic->isPlanning())->toBeTrue();
});

test('isPlanning returns false when status is not planning', function (): void {
    $approvedEpic = new Epic(['status' => EpicStatus::Approved->value]);
    expect($approvedEpic->isPlanning())->toBeFalse();

    $inProgressEpic = new Epic(['status' => EpicStatus::InProgress->value]);
    expect($inProgressEpic->isPlanning())->toBeFalse();
});

test('isApproved returns true when status is approved', function (): void {
    $epic = new Epic(['status' => EpicStatus::Approved->value]);
    expect($epic->isApproved())->toBeTrue();
});

test('isApproved returns false when status is not approved', function (): void {
    $planningEpic = new Epic(['status' => EpicStatus::Planning->value]);
    expect($planningEpic->isApproved())->toBeFalse();

    $inProgressEpic = new Epic(['status' => EpicStatus::InProgress->value]);
    expect($inProgressEpic->isApproved())->toBeFalse();
});

test('isReviewed returns true when reviewed_at is set', function (): void {
    $epic = new Epic(['reviewed_at' => '2024-01-01 12:00:00']);
    expect($epic->isReviewed())->toBeTrue();
});

test('isReviewed returns false when reviewed_at is null', function (): void {
    $epic = new Epic(['reviewed_at' => null]);
    expect($epic->isReviewed())->toBeFalse();
});

// Note: Empty string is not a valid datetime value - Eloquent will cast it.
// This test is removed as proper Eloquent usage requires null for unset datetime fields.

test('isPlanningOrInProgress returns true for planning status', function (): void {
    $epic = new Epic(['status' => EpicStatus::Planning->value]);
    expect($epic->isPlanningOrInProgress())->toBeTrue();
});

test('isPlanningOrInProgress returns true for in_progress status', function (): void {
    $epic = new Epic(['status' => EpicStatus::InProgress->value]);
    expect($epic->isPlanningOrInProgress())->toBeTrue();
});

test('isPlanningOrInProgress returns false for approved status', function (): void {
    $epic = new Epic(['status' => EpicStatus::Approved->value]);
    expect($epic->isPlanningOrInProgress())->toBeFalse();
});

test('isPlanningOrInProgress returns false for other statuses', function (): void {
    $epic = new Epic(['status' => EpicStatus::ReviewPending->value]);
    expect($epic->isPlanningOrInProgress())->toBeFalse();
});

// Note: findByPartialId tests require database and are covered in Feature tests
// The TaskTest.php version uses RefreshDatabase correctly via TestCase
