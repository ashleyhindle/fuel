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

test('findByPartialId finds epic by numeric ID', function (): void {
    // Create a test epic
    $epic = Epic::create([
        'short_id' => 'e-test01',
        'title' => 'Test Epic',
        'description' => 'Test Description',
        'status' => EpicStatus::Planning,
    ]);

    // Find by numeric ID (as string, which is how it comes from CLI)
    $found = Epic::findByPartialId((string) $epic->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($epic->id);
    expect($found->short_id)->toBe('e-test01');

    // Cleanup
    $epic->delete();
});

test('findByPartialId finds epic by full short_id', function (): void {
    // Create a test epic
    $epic = Epic::create([
        'short_id' => 'e-test02',
        'title' => 'Test Epic',
        'description' => 'Test Description',
        'status' => EpicStatus::Planning,
    ]);

    // Find by full short_id
    $found = Epic::findByPartialId('e-test02');

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe('e-test02');

    // Cleanup
    $epic->delete();
});

test('findByPartialId finds epic by partial short_id', function (): void {
    // Create a test epic
    $epic = Epic::create([
        'short_id' => 'e-test03',
        'title' => 'Test Epic',
        'description' => 'Test Description',
        'status' => EpicStatus::Planning,
    ]);

    // Find by partial short_id (without e- prefix)
    $found = Epic::findByPartialId('test03');

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe('e-test03');

    // Cleanup
    $epic->delete();
});

test('findByPartialId throws exception on ambiguous match', function (): void {
    // Create multiple epics with similar IDs
    $epic1 = Epic::create([
        'short_id' => 'e-bbb111',
        'title' => 'Test Epic 1',
        'description' => 'Test Description',
        'status' => EpicStatus::Planning,
    ]);

    $epic2 = Epic::create([
        'short_id' => 'e-bbb222',
        'title' => 'Test Epic 2',
        'description' => 'Test Description',
        'status' => EpicStatus::Planning,
    ]);

    // This should throw an exception because 'bbb' matches both
    expect(fn () => Epic::findByPartialId('bbb'))
        ->toThrow(\RuntimeException::class, "Ambiguous epic ID 'bbb'");

    // Cleanup
    $epic1->delete();
    $epic2->delete();
});

test('findByPartialId returns null for non-existent ID', function (): void {
    $found = Epic::findByPartialId('e-notexist');
    expect($found)->toBeNull();

    $found = Epic::findByPartialId('999999');
    expect($found)->toBeNull();
});
