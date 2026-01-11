<?php

declare(strict_types=1);

use App\Models\Model;

// Create a concrete implementation for testing
class TestModel extends Model
{
    // No additional methods needed for basic tests
}

it('can be instantiated with attributes', function () {
    $attributes = [
        'id' => 'test-123',
        'title' => 'Test Title',
        'status' => 'open',
    ];

    $model = new TestModel($attributes);

    expect($model)->toBeInstanceOf(Model::class);
});

it('can be instantiated without attributes', function () {
    $model = new TestModel;

    expect($model)->toBeInstanceOf(Model::class);
    expect($model->toArray())->toBe([]);
});

it('provides magic property access via __get', function () {
    $attributes = [
        'id' => 'test-123',
        'title' => 'Test Title',
        'status' => 'open',
    ];

    $model = new TestModel($attributes);

    expect($model->id)->toBe('test-123');
    expect($model->title)->toBe('Test Title');
    expect($model->status)->toBe('open');
});

it('returns null for non-existent properties via __get', function () {
    $model = new TestModel(['id' => 'test-123']);

    expect($model->nonexistent)->toBeNull();
});

it('supports isset check via __isset', function () {
    $model = new TestModel([
        'id' => 'test-123',
        'title' => 'Test Title',
    ]);

    expect(isset($model->id))->toBeTrue();
    expect(isset($model->title))->toBeTrue();
    expect(isset($model->nonexistent))->toBeFalse();
});

it('converts to array via toArray', function () {
    $attributes = [
        'id' => 'test-123',
        'title' => 'Test Title',
        'status' => 'open',
        'priority' => 1,
    ];

    $model = new TestModel($attributes);

    expect($model->toArray())->toBe($attributes);
});

it('gets attribute with default value via getAttribute', function () {
    $model = new TestModel(['id' => 'test-123']);

    expect($model->getAttribute('id'))->toBe('test-123');
    expect($model->getAttribute('title'))->toBeNull();
    expect($model->getAttribute('title', 'Default Title'))->toBe('Default Title');
});

it('gets attribute without default value via getAttribute', function () {
    $model = new TestModel([
        'id' => 'test-123',
        'title' => 'Test Title',
    ]);

    expect($model->getAttribute('id'))->toBe('test-123');
    expect($model->getAttribute('title'))->toBe('Test Title');
});

it('handles nested array attributes', function () {
    $attributes = [
        'id' => 'test-123',
        'metadata' => [
            'created_at' => '2024-01-01',
            'tags' => ['tag1', 'tag2'],
        ],
    ];

    $model = new TestModel($attributes);

    expect($model->metadata)->toBe([
        'created_at' => '2024-01-01',
        'tags' => ['tag1', 'tag2'],
    ]);
    expect($model->toArray())->toBe($attributes);
});

it('handles null attribute values', function () {
    $attributes = [
        'id' => 'test-123',
        'optional_field' => null,
    ];

    $model = new TestModel($attributes);

    expect(isset($model->optional_field))->toBeFalse();
    expect($model->optional_field)->toBeNull();
    expect($model->getAttribute('optional_field', 'default'))->toBe('default');
});

it('preserves attribute types', function () {
    $attributes = [
        'string' => 'test',
        'integer' => 123,
        'float' => 45.67,
        'boolean' => true,
        'array' => [1, 2, 3],
        'null' => null,
    ];

    $model = new TestModel($attributes);

    expect($model->string)->toBeString();
    expect($model->integer)->toBeInt();
    expect($model->float)->toBeFloat();
    expect($model->boolean)->toBeBool();
    expect($model->array)->toBeArray();
    expect($model->null)->toBeNull();
});
