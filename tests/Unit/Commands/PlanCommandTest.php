<?php

use App\Commands\PlanCommand;

test('plan command starts immediately without arguments', function () {
    // Mock the Process so we don't actually spawn claude
    $this->artisan('plan')
        ->expectsOutput('Starting new planning session with Claude Opus 4.5...')
        ->expectsOutputToContain("Type 'exit' or press Ctrl+C to end the planning session.")
        ->expectsOutputToContain('Connecting to Claude Opus 4.5 in planning mode...')
        ->assertExitCode(0);
});

test('plan command resumes with epic id argument', function () {
    $this->artisan('plan', ['epic-id' => 'e-12345'])
        ->expectsOutput('Resuming planning session for epic: e-12345')
        ->assertExitCode(0);
});

test('plan command has correct signature', function () {
    $command = new PlanCommand;
    $signature = (new ReflectionClass($command))->getProperty('signature');
    $signature->setAccessible(true);

    expect($signature->getValue($command))->toBe('plan {epic-id? : Resume planning for existing epic}');
});

test('plan command has correct description', function () {
    $command = new PlanCommand;
    $description = (new ReflectionClass($command))->getProperty('description');
    $description->setAccessible(true);

    expect($description->getValue($command))->toBe('Interactive planning session with Claude Opus for feature design');
});
