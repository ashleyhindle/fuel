<?php

declare(strict_types=1);

test('test:selfguided command executes successfully', function () {
    $this->artisan('test:selfguided')
        ->expectsOutputToContain('Self-guided test command executed successfully!')
        ->expectsOutputToContain('This command demonstrates the self-guided execution mode.')
        ->expectsOutputToContain('Random number generated:')
        ->assertSuccessful();
});

test('test:selfguided command shows message based on random number', function () {
    // Since we can't predict the random number, we just verify that
    // the command completes and shows the random number message
    $this->artisan('test:selfguided')
        ->expectsOutputToContain('Random number generated:')
        ->expectsOutputToContain('Number is')
        ->assertSuccessful();
});

test('test:selfguided command returns success exit code', function () {
    $this->artisan('test:selfguided')
        ->assertExitCode(0);
});

test('test:selfguided command displays all expected output lines', function () {
    $this->artisan('test:selfguided')
        ->expectsOutput('Self-guided test command executed successfully!')
        ->expectsOutputToContain('This command demonstrates the self-guided execution mode.')
        ->expectsOutputToContain('Random number generated:')
        ->expectsOutputToContain('Number is')
        ->assertSuccessful();
});
