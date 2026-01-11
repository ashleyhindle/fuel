<?php

declare(strict_types=1);

use App\Services\BackoffStrategy;

beforeEach(function (): void {
    $this->backoffStrategy = new BackoffStrategy;
});

it('calculates exponential backoff delay correctly', function (): void {
    $strategy = $this->backoffStrategy;

    // First attempt (0): baseDelay * 2^0 = 5 * 1 = 5
    expect($strategy->calculateDelay(0))->toBe(5);

    // Second attempt (1): baseDelay * 2^1 = 5 * 2 = 10
    expect($strategy->calculateDelay(1))->toBe(10);

    // Third attempt (2): baseDelay * 2^2 = 5 * 4 = 20
    expect($strategy->calculateDelay(2))->toBe(20);

    // Fourth attempt (3): baseDelay * 2^3 = 5 * 8 = 40
    expect($strategy->calculateDelay(3))->toBe(40);

    // Fifth attempt (4): baseDelay * 2^4 = 5 * 16 = 80
    expect($strategy->calculateDelay(4))->toBe(80);
});

it('respects custom base delay', function (): void {
    $strategy = $this->backoffStrategy;

    // First attempt (0): baseDelay * 2^0 = 10 * 1 = 10
    expect($strategy->calculateDelay(0, 10))->toBe(10);

    // Second attempt (1): baseDelay * 2^1 = 10 * 2 = 20
    expect($strategy->calculateDelay(1, 10))->toBe(20);

    // Third attempt (2): baseDelay * 2^2 = 10 * 4 = 40
    expect($strategy->calculateDelay(2, 10))->toBe(40);
});

it('caps delay at maximum value', function (): void {
    $strategy = $this->backoffStrategy;

    // Attempt 8: 5 * 2^8 = 1280, but capped at 300
    expect($strategy->calculateDelay(8))->toBe(300);

    // Attempt 10: 5 * 2^10 = 5120, but capped at 300
    expect($strategy->calculateDelay(10))->toBe(300);

    // With custom max delay
    expect($strategy->calculateDelay(8, 5, 100))->toBe(100);
});

it('handles negative attempts gracefully', function (): void {
    $strategy = $this->backoffStrategy;

    // Negative attempts should be treated as 0
    expect($strategy->calculateDelay(-1))->toBe(5);
    expect($strategy->calculateDelay(-5))->toBe(5);
});

it('formats backoff time under 60 seconds as seconds', function (): void {
    $strategy = $this->backoffStrategy;

    expect($strategy->formatBackoffTime(0))->toBe('0s');
    expect($strategy->formatBackoffTime(5))->toBe('5s');
    expect($strategy->formatBackoffTime(30))->toBe('30s');
    expect($strategy->formatBackoffTime(59))->toBe('59s');
});

it('formats backoff time over 60 seconds as minutes and seconds', function (): void {
    $strategy = $this->backoffStrategy;

    expect($strategy->formatBackoffTime(60))->toBe('1m 0s');
    expect($strategy->formatBackoffTime(90))->toBe('1m 30s');
    expect($strategy->formatBackoffTime(120))->toBe('2m 0s');
    expect($strategy->formatBackoffTime(135))->toBe('2m 15s');
    expect($strategy->formatBackoffTime(300))->toBe('5m 0s');
    expect($strategy->formatBackoffTime(3661))->toBe('61m 1s');
});

it('calculates realistic backoff progression', function (): void {
    $strategy = $this->backoffStrategy;

    $progression = [];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $progression[] = [
            'attempt' => $attempt,
            'delay' => $strategy->calculateDelay($attempt),
            'formatted' => $strategy->formatBackoffTime($strategy->calculateDelay($attempt)),
        ];
    }

    // Verify reasonable progression
    expect($progression)->toHaveCount(10);

    // First few attempts should increase exponentially
    expect($progression[0]['delay'])->toBe(5);   // 5s
    expect($progression[1]['delay'])->toBe(10);  // 10s
    expect($progression[2]['delay'])->toBe(20);  // 20s
    expect($progression[3]['delay'])->toBe(40);  // 40s
    expect($progression[4]['delay'])->toBe(80);  // 1m 20s
    expect($progression[5]['delay'])->toBe(160); // 2m 40s

    // Later attempts should be capped
    expect($progression[7]['delay'])->toBe(300); // capped at 5m
    expect($progression[8]['delay'])->toBe(300); // capped at 5m
    expect($progression[9]['delay'])->toBe(300); // capped at 5m
});
