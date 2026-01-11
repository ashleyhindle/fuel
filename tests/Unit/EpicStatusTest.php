<?php

declare(strict_types=1);

use App\Enums\EpicStatus;

it('has correct string values for all cases', function (): void {
    expect(EpicStatus::Planning->value)->toBe('planning');
    expect(EpicStatus::InProgress->value)->toBe('in_progress');
    expect(EpicStatus::ReviewPending->value)->toBe('review_pending');
    expect(EpicStatus::Reviewed->value)->toBe('reviewed');
    expect(EpicStatus::Approved->value)->toBe('approved');
    expect(EpicStatus::ChangesRequested->value)->toBe('changes_requested');
});

it('returns true for isTerminal only for Approved', function (): void {
    expect(EpicStatus::Planning->isTerminal())->toBeFalse();
    expect(EpicStatus::InProgress->isTerminal())->toBeFalse();
    expect(EpicStatus::ReviewPending->isTerminal())->toBeFalse();
    expect(EpicStatus::Reviewed->isTerminal())->toBeFalse();
    expect(EpicStatus::Approved->isTerminal())->toBeTrue();
    expect(EpicStatus::ChangesRequested->isTerminal())->toBeFalse();
});

it('returns human-readable labels for all statuses', function (): void {
    expect(EpicStatus::Planning->label())->toBe('Planning');
    expect(EpicStatus::InProgress->label())->toBe('In Progress');
    expect(EpicStatus::ReviewPending->label())->toBe('Review Pending');
    expect(EpicStatus::Reviewed->label())->toBe('Reviewed');
    expect(EpicStatus::Approved->label())->toBe('Approved');
    expect(EpicStatus::ChangesRequested->label())->toBe('Changes Requested');
});
