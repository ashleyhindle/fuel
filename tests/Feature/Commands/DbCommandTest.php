<?php

use Illuminate\Support\Facades\Artisan;

it('shows error when database does not exist', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    $this->artisan('db', ['--cwd' => $tempDir])
        ->expectsOutputToContain('Database not found')
        ->assertExitCode(1);

    // Cleanup
    rmdir($tempDir.'/.fuel');
    rmdir($tempDir);
});

it('shows error in JSON format when database does not exist', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    Artisan::call('db', ['--cwd' => $tempDir, '--json' => true]);
    $output = Artisan::output();

    // Parse JSON from output
    $data = json_decode(trim($output), true);

    expect($data)->toBeArray();
    expect($data)->toHaveKey('error');
    expect($data['error'])->toContain('Database not found');

    // Cleanup
    rmdir($tempDir.'/.fuel');
    rmdir($tempDir);
});

it('outputs success message when database exists', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    // Create a dummy database file
    touch($tempDir.'/.fuel/agent.db');

    $this->artisan('db', ['--cwd' => $tempDir])
        ->expectsOutputToContain('Opening database in TablePlus')
        ->assertExitCode(0);

    // Cleanup
    unlink($tempDir.'/.fuel/agent.db');
    rmdir($tempDir.'/.fuel');
    rmdir($tempDir);
});

it('outputs JSON success when database exists', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    // Create a dummy database file
    touch($tempDir.'/.fuel/agent.db');

    Artisan::call('db', ['--cwd' => $tempDir, '--json' => true]);
    $output = Artisan::output();

    // Parse JSON from output
    $data = json_decode(trim($output), true);

    expect($data)->toBeArray();
    expect($data)->toHaveKeys(['success', 'message', 'path']);
    expect($data['success'])->toBeTrue();
    expect($data['message'])->toBe('Opening database in TablePlus');
    expect($data['path'])->toContain('agent.db');

    // Cleanup
    unlink($tempDir.'/.fuel/agent.db');
    rmdir($tempDir.'/.fuel');
    rmdir($tempDir);
});
