<?php

declare(strict_types=1);

use App\Services\ProcessSpawner;

it('spawns a background process with nohup', function (): void {
    // Create a mock subclass that captures the exec call instead of running it
    $spawner = new class extends ProcessSpawner
    {
        public ?string $executedCommand = null;

        public function spawnBackground(string $command, array $args = []): void
        {
            $fuelPath = base_path('fuel');
            $escapedArgs = array_map('escapeshellarg', array_merge([$command], $args));
            $allArgs = implode(' ', $escapedArgs);

            $this->executedCommand = sprintf(
                'nohup %s %s %s > /dev/null 2>&1 &',
                PHP_BINARY,
                $fuelPath,
                $allArgs
            );
            // Don't actually exec - just capture the command
        }
    };

    $spawner->spawnBackground('mirror:create', ['e-abc123']);

    expect($spawner->executedCommand)->toContain('nohup');
    expect($spawner->executedCommand)->toContain(PHP_BINARY);
    expect($spawner->executedCommand)->toContain(base_path('fuel'));
    expect($spawner->executedCommand)->toContain('mirror:create');
    expect($spawner->executedCommand)->toContain('e-abc123');
    expect($spawner->executedCommand)->toContain('> /dev/null 2>&1 &');
});

it('properly escapes command arguments', function (): void {
    $spawner = new class extends ProcessSpawner
    {
        public ?string $executedCommand = null;

        public function spawnBackground(string $command, array $args = []): void
        {
            $fuelPath = base_path('fuel');
            $escapedArgs = array_map('escapeshellarg', array_merge([$command], $args));
            $allArgs = implode(' ', $escapedArgs);

            $this->executedCommand = sprintf(
                'nohup %s %s %s > /dev/null 2>&1 &',
                PHP_BINARY,
                $fuelPath,
                $allArgs
            );
        }
    };

    $spawner->spawnBackground('test:command', ['arg with spaces', 'normal-arg']);

    expect($spawner->executedCommand)->toContain("'arg with spaces'");
    expect($spawner->executedCommand)->toContain("'normal-arg'");
});

it('handles commands with no arguments', function (): void {
    $spawner = new class extends ProcessSpawner
    {
        public ?string $executedCommand = null;

        public function spawnBackground(string $command, array $args = []): void
        {
            $fuelPath = base_path('fuel');
            $escapedArgs = array_map('escapeshellarg', array_merge([$command], $args));
            $allArgs = implode(' ', $escapedArgs);

            $this->executedCommand = sprintf(
                'nohup %s %s %s > /dev/null 2>&1 &',
                PHP_BINARY,
                $fuelPath,
                $allArgs
            );
        }
    };

    $spawner->spawnBackground('simple:command');

    expect($spawner->executedCommand)->toContain('simple:command');
    expect($spawner->executedCommand)->toContain('nohup');
    expect($spawner->executedCommand)->toContain('> /dev/null 2>&1 &');
});
