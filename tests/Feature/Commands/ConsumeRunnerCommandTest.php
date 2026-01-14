<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    // Use isolated temp directory for tests
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Change to test directory
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    // Return to original directory
    chdir($this->originalDir);

    // Clean up test directory
    if (is_dir($this->tempDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->tempDir);
    }
});

it('is registered and has correct signature', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('consume:runner');
    expect($commands['consume:runner']->getDefinition()->hasOption('interval'))->toBeTrue();
    expect($commands['consume:runner']->getDefinition()->hasOption('review'))->toBeTrue();
});

it('is hidden from command list', function (): void {
    $command = Artisan::all()['consume:runner'];

    expect($command->isHidden())->toBeTrue();
});

it('has correct description', function (): void {
    $command = Artisan::all()['consume:runner'];

    expect($command->getDescription())->toBe('Headless consume runner for background execution');
});

it('accepts interval option', function (): void {
    $command = Artisan::all()['consume:runner'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('interval'))->toBeTrue();
    expect($definition->getOption('interval')->getDefault())->toBe('5');
});

it('accepts review option', function (): void {
    $command = Artisan::all()['consume:runner'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('review'))->toBeTrue();
});
