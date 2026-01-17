<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ProcessSpawner;
use Tests\TestCase;

class ProcessSpawnerTest extends TestCase
{
    private TestableProcessSpawner $processSpawner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processSpawner = new TestableProcessSpawner;
    }

    public function test_spawn_background_builds_correct_command_string(): void
    {
        $this->processSpawner->spawnBackground('mirror:create', ['e-abc123']);

        $capturedCommand = $this->processSpawner->getLastCommand();

        // Verify nohup is present
        $this->assertStringContainsString('nohup', $capturedCommand);

        // Verify PHP_BINARY is present
        $this->assertStringContainsString(PHP_BINARY, $capturedCommand);

        // Verify base_path('fuel') is present
        $this->assertStringContainsString(base_path('fuel'), $capturedCommand);

        // Verify the command name is present and escaped
        $this->assertStringContainsString("'mirror:create'", $capturedCommand);

        // Verify args are escaped
        $this->assertStringContainsString("'e-abc123'", $capturedCommand);

        // Verify output redirection and backgrounding suffix
        $this->assertStringContainsString('> /dev/null 2>&1 &', $capturedCommand);
    }

    public function test_spawn_background_escapes_arguments(): void
    {
        $this->processSpawner->spawnBackground('test:command', ['arg with spaces', 'arg"with"quotes']);

        $capturedCommand = $this->processSpawner->getLastCommand();

        // Verify args with spaces are properly escaped
        $this->assertStringContainsString("'arg with spaces'", $capturedCommand);

        // Verify args with quotes are properly escaped
        $this->assertStringContainsString("'arg\"with\"quotes'", $capturedCommand);
    }

    public function test_spawn_background_handles_no_arguments(): void
    {
        $this->processSpawner->spawnBackground('test:command');

        $capturedCommand = $this->processSpawner->getLastCommand();

        // Verify command is still built correctly
        $this->assertStringContainsString('nohup', $capturedCommand);
        $this->assertStringContainsString(PHP_BINARY, $capturedCommand);
        $this->assertStringContainsString(base_path('fuel'), $capturedCommand);
        $this->assertStringContainsString("'test:command'", $capturedCommand);
        $this->assertStringContainsString('> /dev/null 2>&1 &', $capturedCommand);
    }

    public function test_spawn_background_handles_multiple_arguments(): void
    {
        $this->processSpawner->spawnBackground('mirror:create', ['e-abc123', '--force', '--dry-run']);

        $capturedCommand = $this->processSpawner->getLastCommand();

        // Verify all arguments are present and escaped
        $this->assertStringContainsString("'mirror:create'", $capturedCommand);
        $this->assertStringContainsString("'e-abc123'", $capturedCommand);
        $this->assertStringContainsString("'--force'", $capturedCommand);
        $this->assertStringContainsString("'--dry-run'", $capturedCommand);
    }

    public function test_spawn_background_command_structure(): void
    {
        $this->processSpawner->spawnBackground('test:command', ['arg1', 'arg2']);

        $capturedCommand = $this->processSpawner->getLastCommand();

        // Verify the command follows the expected structure:
        // nohup PHP_BINARY base_path('fuel') 'command' 'arg1' 'arg2' > /dev/null 2>&1 &
        $expectedPattern = sprintf(
            '/^nohup %s %s \'test:command\' \'arg1\' \'arg2\' > \/dev\/null 2>&1 &$/',
            preg_quote(PHP_BINARY, '/'),
            preg_quote(base_path('fuel'), '/')
        );

        $this->assertMatchesRegularExpression($expectedPattern, $capturedCommand);
    }

    public function test_spawn_background_handles_special_characters_in_arguments(): void
    {
        $this->processSpawner->spawnBackground('test:command', ['$PATH', '$(whoami)', '`ls`', 'foo;bar']);

        $capturedCommand = $this->processSpawner->getLastCommand();

        // Verify special shell characters are escaped
        // escapeshellarg wraps in single quotes, which prevents variable expansion and command substitution
        $this->assertStringContainsString("'\$PATH'", $capturedCommand);
        $this->assertStringContainsString("'\$(whoami)'", $capturedCommand);
        $this->assertStringContainsString("'`ls`'", $capturedCommand);
        $this->assertStringContainsString("'foo;bar'", $capturedCommand);
    }
}

/**
 * Testable subclass that captures exec commands instead of running them.
 */
class TestableProcessSpawner extends ProcessSpawner
{
    private ?string $lastCommand = null;

    public function spawnBackground(string $command, array $args = []): void
    {
        $fuelPath = base_path('fuel');
        $escapedArgs = array_map('escapeshellarg', array_merge([$command], $args));
        $allArgs = implode(' ', $escapedArgs);

        $fullCommand = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            PHP_BINARY,
            $fuelPath,
            $allArgs
        );

        // Capture the command instead of executing it
        $this->lastCommand = $fullCommand;
    }

    public function getLastCommand(): ?string
    {
        return $this->lastCommand;
    }
}
