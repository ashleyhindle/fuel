<?php

use App\Enums\FailureType;
use App\Services\AgentHealthTracker;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tracker = app(AgentHealthTracker::class);
});

it('resets health for specific agent', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('cursor-agent', FailureType::Network);

    // Verify agents have health data
    $healthBefore = $this->tracker->getHealthStatus('claude');
    expect($healthBefore->consecutiveFailures)->toBe(1);

    Artisan::call('health:reset', ['agent' => 'claude']);
    $output = Artisan::output();

    expect($output)->toContain('Health status reset for agent: claude');

    // Verify claude was reset
    $healthAfter = $this->tracker->getHealthStatus('claude');
    expect($healthAfter->consecutiveFailures)->toBe(0);

    // Verify cursor-agent was not reset
    $cursorHealth = $this->tracker->getHealthStatus('cursor-agent');
    expect($cursorHealth->consecutiveFailures)->toBe(1);
});

it('resets all agents with --all flag', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordFailure('cursor-agent', FailureType::Network);

    Artisan::call('health:reset', ['--all' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Health status reset for 2 agent(s).');

    // Verify all agents were reset
    $claudeHealth = $this->tracker->getHealthStatus('claude');
    expect($claudeHealth->consecutiveFailures)->toBe(0);

    $cursorHealth = $this->tracker->getHealthStatus('cursor-agent');
    expect($cursorHealth->consecutiveFailures)->toBe(0);
});

it('prompts for confirmation when no arguments provided and resets on yes', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    // Simulate user confirming
    $this->artisan('health:reset')
        ->expectsConfirmation('Reset health status for all agents?', 'yes')
        ->expectsOutput('Health status reset for 1 agent(s).')
        ->assertExitCode(0);

    // Verify agent was reset
    $health = $this->tracker->getHealthStatus('claude');
    expect($health->consecutiveFailures)->toBe(0);
});

it('cancels reset when user declines confirmation', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    // Simulate user declining
    $this->artisan('health:reset')
        ->expectsConfirmation('Reset health status for all agents?', 'no')
        ->expectsOutput('Reset cancelled.')
        ->assertExitCode(0);

    // Verify agent was not reset
    $health = $this->tracker->getHealthStatus('claude');
    expect($health->consecutiveFailures)->toBe(1);
});

it('displays message when no agents to reset', function (): void {
    Artisan::call('health:reset', ['--all' => true]);
    $output = Artisan::output();

    expect($output)->toContain('No agent health data to reset.');
});

it('resets dead agent back to healthy', function (): void {
    // Create a dead agent (5+ consecutive failures)
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->recordFailure('dead-agent', FailureType::Network);
    }

    expect($this->tracker->isDead('dead-agent', 5))->toBeTrue();

    Artisan::call('health:reset', ['agent' => 'dead-agent']);

    // Verify agent is no longer dead
    $health = $this->tracker->getHealthStatus('dead-agent');
    expect($health->consecutiveFailures)->toBe(0);
    expect($this->tracker->isDead('dead-agent', 5))->toBeFalse();
});

it('clears backoff status when resetting agent', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    // Verify agent is in backoff
    expect($this->tracker->isAvailable('claude'))->toBeFalse();
    expect($this->tracker->getBackoffSeconds('claude'))->toBeGreaterThan(0);

    Artisan::call('health:reset', ['agent' => 'claude']);

    // Verify backoff is cleared
    expect($this->tracker->isAvailable('claude'))->toBeTrue();
    expect($this->tracker->getBackoffSeconds('claude'))->toBe(0);
});
