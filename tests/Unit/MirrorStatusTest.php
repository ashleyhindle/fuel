<?php

declare(strict_types=1);

use App\Enums\MirrorStatus;

it('has correct string values for all cases', function (): void {
    expect(MirrorStatus::None->value)->toBe('none');
    expect(MirrorStatus::Pending->value)->toBe('pending');
    expect(MirrorStatus::Creating->value)->toBe('creating');
    expect(MirrorStatus::Ready->value)->toBe('ready');
    expect(MirrorStatus::Merging->value)->toBe('merging');
    expect(MirrorStatus::MergeFailed->value)->toBe('merge_failed');
    expect(MirrorStatus::Merged->value)->toBe('merged');
    expect(MirrorStatus::Cleaned->value)->toBe('cleaned');
});

it('returns true for isWorkable only for Ready', function (): void {
    expect(MirrorStatus::None->isWorkable())->toBeFalse();
    expect(MirrorStatus::Pending->isWorkable())->toBeFalse();
    expect(MirrorStatus::Creating->isWorkable())->toBeFalse();
    expect(MirrorStatus::Ready->isWorkable())->toBeTrue();
    expect(MirrorStatus::Merging->isWorkable())->toBeFalse();
    expect(MirrorStatus::MergeFailed->isWorkable())->toBeFalse();
    expect(MirrorStatus::Merged->isWorkable())->toBeFalse();
    expect(MirrorStatus::Cleaned->isWorkable())->toBeFalse();
});

it('returns true for needsAttention only for MergeFailed', function (): void {
    expect(MirrorStatus::None->needsAttention())->toBeFalse();
    expect(MirrorStatus::Pending->needsAttention())->toBeFalse();
    expect(MirrorStatus::Creating->needsAttention())->toBeFalse();
    expect(MirrorStatus::Ready->needsAttention())->toBeFalse();
    expect(MirrorStatus::Merging->needsAttention())->toBeFalse();
    expect(MirrorStatus::MergeFailed->needsAttention())->toBeTrue();
    expect(MirrorStatus::Merged->needsAttention())->toBeFalse();
    expect(MirrorStatus::Cleaned->needsAttention())->toBeFalse();
});

it('returns human-readable labels for all statuses', function (): void {
    expect(MirrorStatus::None->label())->toBe('None');
    expect(MirrorStatus::Pending->label())->toBe('Pending');
    expect(MirrorStatus::Creating->label())->toBe('Creating');
    expect(MirrorStatus::Ready->label())->toBe('Ready');
    expect(MirrorStatus::Merging->label())->toBe('Merging');
    expect(MirrorStatus::MergeFailed->label())->toBe('Merge Failed');
    expect(MirrorStatus::Merged->label())->toBe('Merged');
    expect(MirrorStatus::Cleaned->label())->toBe('Cleaned');
});
