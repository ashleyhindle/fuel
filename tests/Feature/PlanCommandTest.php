<?php

use App\Commands\PlanCommand;

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