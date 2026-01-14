<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    mkdir($this->tempDir.'/.fuel');
});

afterEach(function (): void {
    if (is_dir($this->tempDir)) {
        array_map(unlink(...), glob($this->tempDir.'/.fuel/*'));
        array_map(unlink(...), glob($this->tempDir.'/*'));
        rmdir($this->tempDir.'/.fuel');
        rmdir($this->tempDir);
    }
});

it('fails when daemon is not running', function (): void {
    $pidFilePath = $this->tempDir.'/.fuel/consume.pid';

    // Mock the base_path function to return our temp dir
    $this->app->bind('path.base', fn () => $this->tempDir);

    $this->artisan('browser:close', [
        'context_id' => 'test-context',
    ])->assertExitCode(1);
});

it('fails when PID file has invalid port', function (): void {
    $pidFilePath = $this->tempDir.'/.fuel/consume.pid';

    // Create PID file with valid PID but invalid port
    file_put_contents($pidFilePath, json_encode([
        'pid' => getmypid(),
        'port' => 0,
    ]));

    $this->app->bind('path.base', fn () => $this->tempDir);

    $this->artisan('browser:close', [
        'context_id' => 'test-context',
    ])->assertExitCode(1);
});

it('outputs JSON error when daemon not running and --json flag provided', function (): void {
    $pidFilePath = $this->tempDir.'/.fuel/consume.pid';

    $this->app->bind('path.base', fn () => $this->tempDir);

    $this->artisan('browser:close', [
        'context_id' => 'test-context',
        '--json' => true,
    ])
        ->expectsOutputToContain('"error"')
        ->assertExitCode(1);
});

// Note: Full IPC communication tests would require integration testing with a running daemon.
// Unit tests above verify basic error handling (daemon not running, invalid PID file, etc.)
// The command follows the same pattern as BrowserCreateCommand which has been validated.
