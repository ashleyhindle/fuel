<?php

use App\Services\FuelContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('shows error when database does not exist', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    // Bind FuelContext to use the test directory
    $context = new FuelContext($tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    $this->artisan('db')
        ->expectsOutputToContain('Database not found')
        ->assertExitCode(1);

    // Cleanup
    File::deleteDirectory($tempDir);
});

it('shows error in JSON format when database does not exist', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    // Bind FuelContext to use the test directory
    $context = new FuelContext($tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    Artisan::call('db', ['--json' => true]);
    $output = Artisan::output();

    // Parse JSON from output
    $data = json_decode(trim($output), true);

    expect($data)->toBeArray();
    expect($data)->toHaveKey('error');
    expect($data['error'])->toContain('Database not found');

    // Cleanup
    File::deleteDirectory($tempDir);
});

it('outputs success message when database exists', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    // Create a dummy database file
    touch($tempDir.'/.fuel/agent.db');

    // Bind FuelContext to use the test directory
    $context = new FuelContext($tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    $this->artisan('db')
        ->expectsOutputToContain('Opening database in TablePlus')
        ->assertExitCode(0);

    // Cleanup
    File::deleteDirectory($tempDir);
});

it('outputs JSON success when database exists', function (): void {
    $tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($tempDir.'/.fuel', 0755, true);

    // Create a dummy database file
    touch($tempDir.'/.fuel/agent.db');

    // Bind FuelContext to use the test directory
    $context = new FuelContext($tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    Artisan::call('db', ['--json' => true]);
    $output = Artisan::output();

    // Parse JSON from output
    $data = json_decode(trim($output), true);

    expect($data)->toBeArray();
    expect($data)->toHaveKeys(['success', 'message', 'path']);
    expect($data['success'])->toBeTrue();
    expect($data['message'])->toBe('Opening database in TablePlus');
    expect($data['path'])->toContain('agent.db');

    // Cleanup
    File::deleteDirectory($tempDir);
});
