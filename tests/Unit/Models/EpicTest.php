<?php

declare(strict_types=1);

use App\Models\Epic;

test('fromArray creates Epic instance', function () {
    $data = [
        'id' => 1,
        'short_id' => 'e-abc123',
        'title' => 'Test Epic',
        'description' => 'Test description',
        'status' => 'open',
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

test('magic __get provides access to properties', function () {
    $epic = Epic::fromArray([
        'id' => 1,
        'short_id' => 'e-abc123',
        'title' => 'Test Epic',
        'description' => 'Test description',
        'status' => 'open',
        'created_at' => '2024-01-01 12:00:00',
        'updated_at' => '2024-01-01 12:00:00',
        'reviewed_at' => null,
    ]);

    expect($epic->id)->toBe(1);
    expect($epic->short_id)->toBe('e-abc123');
    expect($epic->title)->toBe('Test Epic');
    expect($epic->description)->toBe('Test description');
    expect($epic->status)->toBe('open');
    expect($epic->created_at)->toBe('2024-01-01 12:00:00');
    expect($epic->updated_at)->toBe('2024-01-01 12:00:00');
    expect($epic->reviewed_at)->toBeNull();
});

test('isOpen returns true when status is open', function () {
    $epic = Epic::fromArray(['status' => 'open']);
    expect($epic->isOpen())->toBeTrue();
});

test('isOpen returns false when status is not open', function () {
    $closedEpic = Epic::fromArray(['status' => 'closed']);
    expect($closedEpic->isOpen())->toBeFalse();

    $planningEpic = Epic::fromArray(['status' => 'planning']);
    expect($planningEpic->isOpen())->toBeFalse();
});

test('isClosed returns true when status is closed', function () {
    $epic = Epic::fromArray(['status' => 'closed']);
    expect($epic->isClosed())->toBeTrue();
});

test('isClosed returns false when status is not closed', function () {
    $openEpic = Epic::fromArray(['status' => 'open']);
    expect($openEpic->isClosed())->toBeFalse();

    $planningEpic = Epic::fromArray(['status' => 'planning']);
    expect($planningEpic->isClosed())->toBeFalse();
});

test('isReviewed returns true when reviewed_at is set', function () {
    $epic = Epic::fromArray(['reviewed_at' => '2024-01-01 12:00:00']);
    expect($epic->isReviewed())->toBeTrue();
});

test('isReviewed returns false when reviewed_at is null', function () {
    $epic = Epic::fromArray(['reviewed_at' => null]);
    expect($epic->isReviewed())->toBeFalse();
});

test('isReviewed returns false when reviewed_at is empty string', function () {
    $epic = Epic::fromArray(['reviewed_at' => '']);
    expect($epic->isReviewed())->toBeFalse();
});

test('isPlanningOrOpen returns true for planning status', function () {
    $epic = Epic::fromArray(['status' => 'planning']);
    expect($epic->isPlanningOrOpen())->toBeTrue();
});

test('isPlanningOrOpen returns true for open status', function () {
    $epic = Epic::fromArray(['status' => 'open']);
    expect($epic->isPlanningOrOpen())->toBeTrue();
});

test('isPlanningOrOpen returns false for closed status', function () {
    $epic = Epic::fromArray(['status' => 'closed']);
    expect($epic->isPlanningOrOpen())->toBeFalse();
});

test('isPlanningOrOpen returns false for other statuses', function () {
    $epic = Epic::fromArray(['status' => 'archived']);
    expect($epic->isPlanningOrOpen())->toBeFalse();
});
