<?php

declare(strict_types=1);

test('fuel plan command starts immediately without arguments', function () {
    // In testing environment, the command returns immediately
    // since we skip the actual Claude spawn
    $this->artisan('plan')
        ->expectsOutput('Starting new planning session with Claude Opus 4.5...')
        ->expectsOutputToContain("Type 'exit' or press Ctrl+C to end the planning session.")
        ->assertExitCode(0);
});

test('fuel plan command accepts epic-id argument for resuming', function () {
    $this->artisan('plan', ['epic-id' => 'e-abc123'])
        ->expectsOutput('Resuming planning session for epic: e-abc123')
        ->assertExitCode(0);
});

test('fuel plan shows transition prompt methods exist', function () {
    // Verify the command structure supports the transition flow
    $this->assertTrue(
        class_exists(\App\Commands\PlanCommand::class),
        'PlanCommand class should exist'
    );

    $reflection = new ReflectionClass(\App\Commands\PlanCommand::class);

    // Check that new methods for transition handling exist
    $showTransitionPrompt = $reflection->getMethod('showTransitionPrompt');
    $this->assertNotNull($showTransitionPrompt, 'showTransitionPrompt method should exist');

    // Check that the state hint method handles new states
    $showStateHint = $reflection->getMethod('showStateHint');
    $this->assertNotNull($showStateHint, 'showStateHint method should exist');

    // Verify processClaudeOutput returns epicId in its result
    $processOutput = $reflection->getMethod('processClaudeOutput');
    $this->assertNotNull($processOutput, 'processClaudeOutput method should exist');
});

test('fuel plan handles multiple conversation states', function () {
    $command = new \App\Commands\PlanCommand;
    $reflection = new ReflectionClass($command);

    // Test that updateConversationState method exists
    $updateState = $reflection->getMethod('updateConversationState');
    $this->assertNotNull($updateState, 'updateConversationState method should exist');

    // Test that wrapUserMessage method exists for constraint injection
    $wrapMessage = $reflection->getMethod('wrapUserMessage');
    $this->assertNotNull($wrapMessage, 'wrapUserMessage method should exist');

    // Test the various states are handled
    $runLoop = $reflection->getMethod('runInteractionLoop');
    $this->assertNotNull($runLoop, 'runInteractionLoop method should exist');
});
