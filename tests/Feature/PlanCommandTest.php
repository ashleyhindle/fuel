<?php

use App\Services\PlanSession;

test('fuel plan command exists and has correct signature', function () {
    $this->artisan('plan', ['--help' => true])
        ->expectsOutputToContain('Interactive planning session with Claude Opus')
        ->expectsOutputToContain('plan [<epic-id>]')
        ->assertSuccessful();
});

test('fuel plan command starts immediately with no arguments', function () {
    // Mock the process to avoid actually spawning Claude
    $this->artisan('plan')
        ->expectsOutputToContain('Starting new planning session with Claude Opus 4.5')
        ->assertSuccessful();
});

test('fuel plan command can resume with epic-id', function () {
    $this->artisan('plan', ['epic-id' => 'e-test123'])
        ->expectsOutputToContain('Resuming planning session for epic: e-test123')
        ->assertSuccessful();
});

test('plan session can track conversation state and history', function () {
    $session = new PlanSession;

    // Initial state
    expect($session->getConversationState())->toBe('initial');
    expect($session->getPlanData())->toBe([]);

    // Change state
    $session->setConversationState('planning');
    expect($session->getConversationState())->toBe('planning');

    // Add user message
    $session->addUserMessage('I want to build a notification system');

    // Get conversation summary
    $summary = $session->getConversationSummary();
    expect($summary)->toContain('Planning Conversation Summary');
    expect($summary)->toContain('User:');
    expect($summary)->toContain('notification system');
});

test('plan session tracks plan refinements', function () {
    $session = new PlanSession;

    // Start with empty plan data
    expect($session->getPlanData())->toBe([]);

    // Add some user messages to simulate conversation
    $session->addUserMessage('Build a caching system');
    $session->addUserMessage('It should support TTL and eviction policies');
    $session->addUserMessage('Looks good, let\'s create the epic');

    // State should still be initial until we process Claude's output
    expect($session->getConversationState())->toBe('initial');

    // Conversation summary should track all messages
    $summary = $session->getConversationSummary();
    expect($summary)->toContain('Build a caching system');
    expect($summary)->toContain('TTL and eviction policies');
});
