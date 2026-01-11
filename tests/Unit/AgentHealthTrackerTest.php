<?php

use App\Enums\FailureType;
use App\Process\AgentHealth;
use App\Services\AgentHealthTracker;
use App\Services\DatabaseService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';

    $this->database = new DatabaseService($this->dbPath);
    $this->database->initialize();

    $this->tracker = new AgentHealthTracker($this->database);
});

afterEach(function (): void {
    // Clean up temp files
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    $fuelDir = $this->tempDir.'/.fuel';
    if (is_dir($fuelDir)) {
        rmdir($fuelDir);
    }

    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

// =============================================================================
// recordSuccess() Tests
// =============================================================================

it('records a success for a new agent', function (): void {
    $this->tracker->recordSuccess('claude');

    $health = $this->tracker->getHealthStatus('claude');

    expect($health->agent)->toBe('claude');
    expect($health->totalRuns)->toBe(1);
    expect($health->totalSuccesses)->toBe(1);
    expect($health->consecutiveFailures)->toBe(0);
    expect($health->lastSuccessAt)->not->toBeNull();
    expect($health->backoffUntil)->toBeNull();
});

it('increments success count for existing agent', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('claude');

    $health = $this->tracker->getHealthStatus('claude');

    expect($health->totalRuns)->toBe(3);
    expect($health->totalSuccesses)->toBe(3);
    expect($health->consecutiveFailures)->toBe(0);
});

it('resets consecutive failures on success', function (): void {
    // Record some failures first
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);

    $healthBefore = $this->tracker->getHealthStatus('claude');
    expect($healthBefore->consecutiveFailures)->toBe(2);

    // Now record a success
    $this->tracker->recordSuccess('claude');

    $healthAfter = $this->tracker->getHealthStatus('claude');
    expect($healthAfter->consecutiveFailures)->toBe(0);
    expect($healthAfter->backoffUntil)->toBeNull();
});

it('clears backoff on success', function (): void {
    // Record a failure to trigger backoff
    $this->tracker->recordFailure('claude', FailureType::Network);
    expect($this->tracker->isAvailable('claude'))->toBeFalse();

    // Record a success to clear backoff
    $this->tracker->recordSuccess('claude');

    expect($this->tracker->isAvailable('claude'))->toBeTrue();
    expect($this->tracker->getBackoffSeconds('claude'))->toBe(0);
});

// =============================================================================
// recordFailure() Tests
// =============================================================================

it('records a failure for a new agent', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    $health = $this->tracker->getHealthStatus('claude');

    expect($health->agent)->toBe('claude');
    expect($health->totalRuns)->toBe(1);
    expect($health->totalSuccesses)->toBe(0);
    expect($health->consecutiveFailures)->toBe(1);
    expect($health->lastFailureAt)->not->toBeNull();
});

it('increments consecutive failures', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Timeout);
    $this->tracker->recordFailure('claude', FailureType::Crash);

    $health = $this->tracker->getHealthStatus('claude');

    expect($health->totalRuns)->toBe(3);
    expect($health->totalSuccesses)->toBe(0);
    expect($health->consecutiveFailures)->toBe(3);
});

it('applies backoff on network failure', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    expect($this->tracker->isAvailable('claude'))->toBeFalse();
    expect($this->tracker->getBackoffSeconds('claude'))->toBeGreaterThan(0);
    expect($this->tracker->getBackoffSeconds('claude'))->toBeLessThanOrEqual(30);
});

it('applies backoff on timeout failure', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Timeout);

    expect($this->tracker->isAvailable('claude'))->toBeFalse();
    expect($this->tracker->getBackoffSeconds('claude'))->toBeLessThanOrEqual(30);
});

it('applies backoff on crash failure', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Crash);

    expect($this->tracker->isAvailable('claude'))->toBeFalse();
    expect($this->tracker->getBackoffSeconds('claude'))->toBeLessThanOrEqual(30);
});

it('does not apply backoff on permission failure', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Permission);

    expect($this->tracker->isAvailable('claude'))->toBeTrue();
    expect($this->tracker->getBackoffSeconds('claude'))->toBe(0);
});

it('increases backoff exponentially with consecutive failures', function (): void {
    // First failure: 30 seconds
    $this->tracker->recordFailure('claude', FailureType::Network);
    $backoff1 = $this->tracker->getBackoffSeconds('claude');
    expect($backoff1)->toBeLessThanOrEqual(30);

    // Wait and record second failure: 60 seconds
    // Manually clear backoff for testing
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);

    $backoff2 = $this->tracker->getBackoffSeconds('claude');
    expect($backoff2)->toBeLessThanOrEqual(60);

    // More failures: 120 seconds
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);

    $backoff3 = $this->tracker->getBackoffSeconds('claude');
    expect($backoff3)->toBeLessThanOrEqual(120);
});

// =============================================================================
// isAvailable() Tests
// =============================================================================

it('returns true for new agent', function (): void {
    expect($this->tracker->isAvailable('new-agent'))->toBeTrue();
});

it('returns true when backoff has expired', function (): void {
    // Record a failure
    $this->tracker->recordFailure('claude', FailureType::Network);

    // Manually set backoff_until to the past
    $this->database->query(
        'UPDATE agent_health SET backoff_until = ? WHERE agent = ?',
        [(new DateTimeImmutable('-1 hour'))->format('c'), 'claude']
    );

    expect($this->tracker->isAvailable('claude'))->toBeTrue();
});

it('returns false when in active backoff', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    expect($this->tracker->isAvailable('claude'))->toBeFalse();
});

// =============================================================================
// getBackoffSeconds() Tests
// =============================================================================

it('returns 0 for new agent', function (): void {
    expect($this->tracker->getBackoffSeconds('new-agent'))->toBe(0);
});

it('returns 0 when no backoff', function (): void {
    $this->tracker->recordSuccess('claude');

    expect($this->tracker->getBackoffSeconds('claude'))->toBe(0);
});

it('returns remaining seconds during backoff', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    $remaining = $this->tracker->getBackoffSeconds('claude');

    expect($remaining)->toBeGreaterThan(0);
    expect($remaining)->toBeLessThanOrEqual(30);
});

// =============================================================================
// getHealthStatus() Tests
// =============================================================================

it('returns new agent health for unknown agent', function (): void {
    $health = $this->tracker->getHealthStatus('unknown-agent');

    expect($health)->toBeInstanceOf(AgentHealth::class);
    expect($health->agent)->toBe('unknown-agent');
    expect($health->totalRuns)->toBe(0);
    expect($health->totalSuccesses)->toBe(0);
    expect($health->consecutiveFailures)->toBe(0);
});

it('returns correct health status after mixed operations', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Timeout);
    $this->tracker->recordSuccess('claude');

    $health = $this->tracker->getHealthStatus('claude');

    expect($health->totalRuns)->toBe(5);
    expect($health->totalSuccesses)->toBe(3);
    expect($health->consecutiveFailures)->toBe(0);
    expect($health->lastSuccessAt)->not->toBeNull();
    expect($health->lastFailureAt)->not->toBeNull();
});

// =============================================================================
// getAllHealthStatus() Tests
// =============================================================================

it('returns empty array when no agents tracked', function (): void {
    $all = $this->tracker->getAllHealthStatus();

    expect($all)->toBe([]);
});

it('returns all tracked agents', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('cursor-agent');
    $this->tracker->recordFailure('opencode', FailureType::Network);

    $all = $this->tracker->getAllHealthStatus();

    expect($all)->toHaveCount(3);

    $agents = array_map(fn (AgentHealth $h): string => $h->agent, $all);
    expect($agents)->toContain('claude');
    expect($agents)->toContain('cursor-agent');
    expect($agents)->toContain('opencode');
});

it('returns agents sorted alphabetically', function (): void {
    $this->tracker->recordSuccess('opencode');
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('cursor-agent');

    $all = $this->tracker->getAllHealthStatus();

    expect($all[0]->agent)->toBe('claude');
    expect($all[1]->agent)->toBe('cursor-agent');
    expect($all[2]->agent)->toBe('opencode');
});

// =============================================================================
// clearHealth() Tests
// =============================================================================

it('clears health data for an agent', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);

    $this->tracker->clearHealth('claude');

    $health = $this->tracker->getHealthStatus('claude');
    expect($health->totalRuns)->toBe(0);
    expect($health->totalSuccesses)->toBe(0);
});

it('does not affect other agents when clearing', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('cursor-agent');

    $this->tracker->clearHealth('claude');

    $claudeHealth = $this->tracker->getHealthStatus('claude');
    $cursorHealth = $this->tracker->getHealthStatus('cursor-agent');

    expect($claudeHealth->totalRuns)->toBe(0);
    expect($cursorHealth->totalRuns)->toBe(1);
});

// =============================================================================
// AgentHealth Value Object Tests
// =============================================================================

it('calculates success rate correctly', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordSuccess('claude');

    $health = $this->tracker->getHealthStatus('claude');

    // 3 successes out of 4 runs = 75%
    expect($health->getSuccessRate())->toBe(75.0);
});

it('returns null success rate for new agent', function (): void {
    $health = $this->tracker->getHealthStatus('new-agent');

    expect($health->getSuccessRate())->toBeNull();
});

it('calculates total failures correctly', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Timeout);

    $health = $this->tracker->getHealthStatus('claude');

    expect($health->getTotalFailures())->toBe(2);
});

it('returns correct health status label', function (): void {
    // New agent = healthy
    $newHealth = AgentHealth::forNewAgent('test');
    expect($newHealth->getStatus())->toBe('healthy');

    // 1 failure = warning
    $this->tracker->recordFailure('warn-agent', FailureType::Network);
    expect($this->tracker->getHealthStatus('warn-agent')->getStatus())->toBe('warning');

    // 2-4 failures = degraded
    $this->tracker->recordFailure('warn-agent', FailureType::Network);
    expect($this->tracker->getHealthStatus('warn-agent')->getStatus())->toBe('degraded');

    // 5+ failures = unhealthy
    $this->tracker->recordFailure('warn-agent', FailureType::Network);
    $this->tracker->recordFailure('warn-agent', FailureType::Network);
    $this->tracker->recordFailure('warn-agent', FailureType::Network);

    expect($this->tracker->getHealthStatus('warn-agent')->getStatus())->toBe('unhealthy');
});

// =============================================================================
// FailureType Enum Tests
// =============================================================================

it('identifies retryable failure types', function (): void {
    expect(FailureType::Network->isRetryable())->toBeTrue();
    expect(FailureType::Timeout->isRetryable())->toBeTrue();
    expect(FailureType::Permission->isRetryable())->toBeFalse();
    expect(FailureType::Crash->isRetryable())->toBeFalse();
});

it('provides failure type descriptions', function (): void {
    expect(FailureType::Network->description())->toContain('Network');
    expect(FailureType::Timeout->description())->toContain('timed out');
    expect(FailureType::Permission->description())->toContain('Permission');
    expect(FailureType::Crash->description())->toContain('crashed');
});

// =============================================================================
// isDead() Tests
// =============================================================================

it('returns false for new agent with isDead', function (): void {
    expect($this->tracker->isDead('new-agent', 5))->toBeFalse();
});

it('returns false when consecutive failures below max_retries', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);

    expect($this->tracker->isDead('claude', 5))->toBeFalse();
});

it('returns true when consecutive failures equals max_retries', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->recordFailure('claude', FailureType::Network);
    }

    expect($this->tracker->isDead('claude', 5))->toBeTrue();
});

it('returns true when consecutive failures exceeds max_retries', function (): void {
    for ($i = 0; $i < 7; $i++) {
        $this->tracker->recordFailure('claude', FailureType::Network);
    }

    expect($this->tracker->isDead('claude', 5))->toBeTrue();
});

it('returns false after success resets consecutive failures', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->recordFailure('claude', FailureType::Network);
    }

    expect($this->tracker->isDead('claude', 5))->toBeTrue();

    $this->tracker->recordSuccess('claude');

    expect($this->tracker->isDead('claude', 5))->toBeFalse();
});

it('respects custom max_retries threshold', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('claude', FailureType::Network);

    expect($this->tracker->isDead('claude', 3))->toBeTrue();
    expect($this->tracker->isDead('claude', 5))->toBeFalse();
});

// =============================================================================
// Edge Cases
// =============================================================================

it('handles concurrent operations correctly', function (): void {
    // Simulate rapid operations
    for ($i = 0; $i < 10; $i++) {
        $this->tracker->recordSuccess('claude');
        $this->tracker->recordFailure('cursor-agent', FailureType::Network);
    }

    $claudeHealth = $this->tracker->getHealthStatus('claude');
    $cursorHealth = $this->tracker->getHealthStatus('cursor-agent');

    expect($claudeHealth->totalRuns)->toBe(10);
    expect($claudeHealth->totalSuccesses)->toBe(10);
    expect($cursorHealth->totalRuns)->toBe(10);
    expect($cursorHealth->totalSuccesses)->toBe(0);
});

it('tracks multiple agents independently', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('cursor-agent', FailureType::Network);
    $this->tracker->recordSuccess('opencode');

    $claudeHealth = $this->tracker->getHealthStatus('claude');
    $cursorHealth = $this->tracker->getHealthStatus('cursor-agent');
    $opencodeHealth = $this->tracker->getHealthStatus('opencode');

    expect($claudeHealth->consecutiveFailures)->toBe(0);
    expect($cursorHealth->consecutiveFailures)->toBe(1);
    expect($opencodeHealth->consecutiveFailures)->toBe(0);

    expect($claudeHealth->isAvailable())->toBeTrue();
    expect($cursorHealth->isAvailable())->toBeFalse();
    expect($opencodeHealth->isAvailable())->toBeTrue();
});
