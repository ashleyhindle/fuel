<?php

declare(strict_types=1);

use App\Commands\GuidelinesCommand;
use App\Services\FuelContext;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);

    // Create .fuel subdirectory to match expected structure
    $fuelDir = $this->tempDir.'/.fuel';
    mkdir($fuelDir);

    // Bind FuelContext to use our .fuel directory
    $this->app->bind(FuelContext::class, fn(): FuelContext => new FuelContext($fuelDir));
});

afterEach(function (): void {
    if (is_dir($this->tempDir)) {
        // Clean up recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($this->tempDir);
    }
});

it('outputs guidelines content including browser testing section', function (): void {
    // Use reflection to access the protected method
    $command = new GuidelinesCommand;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('getGuidelinesContent');

    $content = $method->invoke($command);

    expect($content)->toContain('### Testing Visual Changes with Browser');
    expect($content)->toContain('fuel browser testing skill');
    expect($content)->toContain('Screenshots are saved to `/tmp`');
    expect($content)->toContain('Browser daemon auto-manages lifecycle');
});

it('injects guidelines with browser testing section into CLAUDE.md', function (): void {
    $claudePath = $this->tempDir.'/CLAUDE.md';
    file_put_contents($claudePath, "# Project Instructions\n\nSome existing content.\n");

    $this->artisan('guidelines', [
        '--add' => true,
    ])->assertExitCode(0);

    $content = file_get_contents($claudePath);

    expect($content)->toContain('<fuel>');
    expect($content)->toContain('</fuel>');
    expect($content)->toContain('### Testing Visual Changes with Browser');
    expect($content)->toContain('fuel browser testing skill');
    expect($content)->toContain('Browser daemon auto-manages lifecycle');
});

it('replaces existing fuel section with updated content', function (): void {
    $claudePath = $this->tempDir.'/CLAUDE.md';
    file_put_contents($claudePath, "# Project\n\n<fuel>\nOld content\n</fuel>\n\nMore content");

    $this->artisan('guidelines', [
        '--add' => true,
    ])->assertExitCode(0);

    $content = file_get_contents($claudePath);

    expect($content)->not->toContain('Old content');
    expect($content)->toContain('### Testing Visual Changes with Browser');
    expect($content)->toContain('More content'); // Preserves content outside fuel tags
});

it('creates file with guidelines if it does not exist', function (): void {
    $agentsPath = $this->tempDir.'/AGENTS.md';

    expect(file_exists($agentsPath))->toBeFalse();

    $this->artisan('guidelines', [
        '--add' => true,
    ])->assertExitCode(0);

    expect(file_exists($agentsPath))->toBeTrue();

    $content = file_get_contents($agentsPath);
    expect($content)->toContain('# Agent Instructions');
    expect($content)->toContain('### Testing Visual Changes with Browser');
});
