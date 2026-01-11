<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Process\ProcessResult;
use App\Process\ProcessStatus;
use App\Services\ConfigService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class ProcessManagerTest extends TestCase
{
    private ProcessManager $processManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create processes subdirectory in the test directory (provided by Pest.php)
        mkdir($this->testDir.'/.fuel/processes', 0755, true);

        // Create a mock ConfigService for testing with real commands
        $mockConfig = Mockery::mock(ConfigService::class);
        $mockConfig->shouldReceive('getAgentDefinition')
            ->andReturn([
                'command' => 'sh',
                'prompt_args' => ['-c'],
                'model' => '',
                'args' => [],
                'env' => [],
                'max_concurrent' => 2,
            ]);
        $mockConfig->shouldReceive('getAgentLimit')
            ->andReturn(10); // Higher limit for feature tests

        $this->processManager = new ProcessManager($mockConfig, new FuelContext($this->testDir.'/.fuel'));
    }

    protected function tearDown(): void
    {
        // Ensure all processes are killed
        $this->processManager->shutdown();

        parent::tearDown();
    }

    public function test_spawn_runs_real_command(): void
    {
        $this->processManager->spawn(
            taskId: 'f-real01',
            agent: 'claude',
            command: 'echo hello',
            cwd: '/tmp'
        );

        // Wait for completion
        $result = $this->processManager->waitForAny(2000);

        $this->assertNotNull($result);
        $this->assertTrue($result->wasSuccessful());
        $this->assertEquals(0, $result->getExitCode());
        $this->assertStringContainsString('hello', $result->output->stdout);
        $this->assertEquals('', trim($result->output->stderr));
    }

    public function test_captures_stdout_to_file(): void
    {
        $this->processManager->spawn(
            taskId: 'f-stdout',
            agent: 'claude',
            command: 'echo "stdout content"',
            cwd: '/tmp'
        );

        // Wait for completion
        $result = $this->processManager->waitForAny(2000);

        $this->assertNotNull($result);

        // Verify stdout file content
        $stdoutPath = $this->testDir.'/.fuel/processes/f-stdout/stdout.log';
        $this->assertTrue(File::exists($stdoutPath));
        $content = File::get($stdoutPath);
        $this->assertStringContainsString('stdout content', $content);
    }

    public function test_captures_stderr_to_file(): void
    {
        $this->processManager->spawn(
            taskId: 'f-stderr',
            agent: 'claude',
            command: 'sh -c "echo error >&2"',
            cwd: '/tmp'
        );

        // Wait for completion
        $result = $this->processManager->waitForAny(2000);

        $this->assertNotNull($result);

        // Verify stderr file content
        $stderrPath = $this->testDir.'/.fuel/processes/f-stderr/stderr.log';
        $this->assertTrue(File::exists($stderrPath));
        $content = File::get($stderrPath);
        $this->assertStringContainsString('error', $content);
    }

    public function test_wait_for_any_returns_completed_process(): void
    {
        // Spawn multiple processes with different durations
        $this->processManager->spawn(
            taskId: 'f-fast',
            agent: 'claude',
            command: 'echo fast',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-slow',
            agent: 'claude',
            command: 'sleep 1',
            cwd: '/tmp'
        );

        // Wait for any to complete (should be the fast one)
        $result = $this->processManager->waitForAny(500);

        $this->assertNotNull($result);
        $this->assertEquals('f-fast', $result->getTaskId());
        $this->assertTrue($result->wasSuccessful());
        $this->assertStringContainsString('fast', $result->output->stdout);

        // Clean up slow process
        $this->processManager->kill('f-slow');
    }

    public function test_wait_for_all_waits_for_all_processes(): void
    {
        // Spawn multiple quick processes
        $this->processManager->spawn(
            taskId: 'f-proc1',
            agent: 'claude',
            command: 'echo one',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-proc2',
            agent: 'claude',
            command: 'echo two',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-proc3',
            agent: 'claude',
            command: 'echo three',
            cwd: '/tmp'
        );

        // Wait for all to complete
        $results = $this->processManager->waitForAll(2000);

        $this->assertCount(3, $results);

        // Verify all completed successfully
        foreach ($results as $result) {
            $this->assertTrue($result->wasSuccessful());
            $this->assertEquals(0, $result->getExitCode());
        }

        // Verify we got output from all
        $outputs = array_map(fn (ProcessResult $r): string => trim($r->output->stdout), $results);
        $this->assertContains('one', $outputs);
        $this->assertContains('two', $outputs);
        $this->assertContains('three', $outputs);
    }

    public function test_kill_stops_long_running_process(): void
    {
        // Spawn a long-running process
        $this->processManager->spawn(
            taskId: 'f-long',
            agent: 'claude',
            command: 'sleep 60',
            cwd: '/tmp'
        );

        // Verify it's running
        $this->assertTrue($this->processManager->isRunning('f-long'));

        // Kill it
        $this->processManager->kill('f-long');

        // Wait a bit for the kill to take effect
        usleep(500000); // 500ms

        // Verify it's no longer running
        $this->assertFalse($this->processManager->isRunning('f-long'));

        // Check that the process status was updated to Killed
        $runningProcesses = $this->processManager->getRunningProcesses();
        $this->assertEmpty($runningProcesses);
    }

    public function test_shutdown_kills_all_processes(): void
    {
        // Spawn multiple long-running processes
        $this->processManager->spawn(
            taskId: 'f-shutdown1',
            agent: 'claude',
            command: 'sleep 30',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-shutdown2',
            agent: 'claude',
            command: 'sleep 30',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-shutdown3',
            agent: 'claude',
            command: 'sleep 30',
            cwd: '/tmp'
        );

        // Verify all are running
        $this->assertEquals(3, $this->processManager->getRunningCount());

        // Shutdown
        $this->processManager->shutdown();

        // Verify all are stopped
        $this->assertEquals(0, $this->processManager->getRunningCount());
        $this->assertFalse($this->processManager->isRunning('f-shutdown1'));
        $this->assertFalse($this->processManager->isRunning('f-shutdown2'));
        $this->assertFalse($this->processManager->isRunning('f-shutdown3'));
    }

    public function test_handles_failing_command(): void
    {
        // Use a command that doesn't have spaces in arguments
        // false command always exits with 1
        $this->processManager->spawn(
            taskId: 'f-fail',
            agent: 'claude',
            command: 'false',  // Standard Unix command that always fails with exit code 1
            cwd: '/tmp'
        );

        // Wait for completion
        $result = $this->processManager->waitForAny(2000);

        $this->assertNotNull($result);
        $this->assertFalse($result->wasSuccessful());
        $this->assertEquals(1, $result->getExitCode());
        $this->assertEquals(ProcessStatus::Failed, $result->process->status);
    }

    public function test_handles_command_with_arguments(): void
    {
        $this->processManager->spawn(
            taskId: 'f-args',
            agent: 'claude',
            command: 'echo foo bar baz',
            cwd: '/tmp'
        );

        // Wait for completion
        $result = $this->processManager->waitForAny(2000);

        $this->assertNotNull($result);
        $this->assertTrue($result->wasSuccessful());
        $this->assertStringContainsString('foo bar baz', $result->output->stdout);
    }

    public function test_wait_for_any_returns_null_on_timeout(): void
    {
        // Spawn a long-running process
        $this->processManager->spawn(
            taskId: 'f-timeout',
            agent: 'claude',
            command: 'sleep 5',
            cwd: '/tmp'
        );

        // Wait with short timeout
        $result = $this->processManager->waitForAny(100); // 100ms timeout

        $this->assertNull($result);
        $this->assertTrue($this->processManager->isRunning('f-timeout'));

        // Clean up
        $this->processManager->kill('f-timeout');
    }

    public function test_wait_for_all_partial_completion_on_timeout(): void
    {
        // Spawn mix of fast and slow processes
        $this->processManager->spawn(
            taskId: 'f-fast1',
            agent: 'claude',
            command: 'echo fast1',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-slow1',
            agent: 'claude',
            command: 'sleep 5',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-fast2',
            agent: 'claude',
            command: 'echo fast2',
            cwd: '/tmp'
        );

        // Wait with medium timeout
        $results = $this->processManager->waitForAll(500); // 500ms timeout

        // Should have at least the fast ones
        $this->assertGreaterThanOrEqual(2, count($results));

        // Verify fast processes completed
        $taskIds = array_map(fn (ProcessResult $r): string => $r->getTaskId(), $results);
        $this->assertContains('f-fast1', $taskIds);
        $this->assertContains('f-fast2', $taskIds);

        // Clean up slow process
        $this->processManager->kill('f-slow1');
    }
}
