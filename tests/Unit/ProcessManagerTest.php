<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Process\Process;
use App\Process\ProcessOutput;
use App\Process\ProcessStatus;
use App\Services\ConfigService;
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

        // Create a mock ConfigService for testing
        $mockConfig = Mockery::mock(ConfigService::class);
        $mockConfig->shouldReceive('getAgentDefinition')
            ->andReturn([
                'command' => 'sleep',
                'prompt_args' => [],
                'model' => '',
                'args' => [],
                'env' => [],
                'max_concurrent' => 2,
            ]);
        $mockConfig->shouldReceive('getAgentLimit')
            ->andReturn(2);

        $this->processManager = new ProcessManager(configService: $mockConfig, cwd: $this->testDir);
    }

    public function test_spawn_creates_process_with_correct_properties(): void
    {
        $process = $this->processManager->spawn(
            taskId: 'f-test01',
            agent: 'claude',
            command: 'sleep 0.1',
            cwd: '/tmp'
        );

        // Check process properties
        $this->assertStringStartsWith('p-', $process->id);
        $this->assertEquals('f-test01', $process->taskId);
        $this->assertEquals('claude', $process->agent);
        $this->assertEquals('sleep 0.1', $process->command);
        $this->assertEquals('/tmp', $process->cwd);
        $this->assertGreaterThan(0, $process->pid);
        $this->assertEquals(ProcessStatus::Running, $process->status);
        $this->assertNull($process->exitCode);
        $this->assertNotNull($process->startedAt);
        $this->assertNull($process->completedAt);

        // Verify output directory and files are created
        $outputDir = $this->testDir.'/.fuel/processes/f-test01';
        $this->assertTrue(File::exists($outputDir));
        $this->assertTrue(File::exists($outputDir.'/stdout.log'));
        $this->assertTrue(File::exists($outputDir.'/stderr.log'));

        // Clean up - kill the process
        $this->processManager->kill('f-test01');
    }

    public function test_get_running_count_returns_active_processes(): void
    {
        // Initially no processes
        $this->assertEquals(0, $this->processManager->getRunningCount());

        // Spawn first process
        $this->processManager->spawn(
            taskId: 'f-test01',
            agent: 'claude',
            command: 'sleep 2',
            cwd: '/tmp'
        );

        $this->assertEquals(1, $this->processManager->getRunningCount());

        // Spawn second process
        $this->processManager->spawn(
            taskId: 'f-test02',
            agent: 'claude',
            command: 'sleep 2',
            cwd: '/tmp'
        );

        $this->assertEquals(2, $this->processManager->getRunningCount());

        // Kill first process
        $this->processManager->kill('f-test01');

        // Wait a moment for the process to actually terminate
        usleep(100000); // 100ms

        $this->assertEquals(1, $this->processManager->getRunningCount());

        // Kill second process
        $this->processManager->kill('f-test02');

        // Wait a moment for the process to actually terminate
        usleep(100000); // 100ms

        $this->assertEquals(0, $this->processManager->getRunningCount());
    }

    public function test_kill_terminates_process(): void
    {
        // Spawn a long-running process
        $this->processManager->spawn(
            taskId: 'f-test01',
            agent: 'claude',
            command: 'sleep 10',
            cwd: '/tmp'
        );

        // Verify it's running
        $this->assertTrue($this->processManager->isRunning('f-test01'));
        $this->assertEquals(1, $this->processManager->getRunningCount());

        // Kill the process
        $this->processManager->kill('f-test01');

        // Wait a moment for the process to actually terminate
        usleep(200000); // 200ms

        // Verify it's no longer running
        $this->assertFalse($this->processManager->isRunning('f-test01'));
        $this->assertEquals(0, $this->processManager->getRunningCount());
    }

    public function test_is_running_returns_false_after_completion(): void
    {
        // Spawn a quick process
        $this->processManager->spawn(
            taskId: 'f-test01',
            agent: 'claude',
            command: 'sleep 0.01',
            cwd: '/tmp'
        );

        // Initially should be running (or may have already completed if very fast)
        // Wait for completion
        $maxWait = 2000000; // 2 seconds in microseconds
        $waited = 0;
        $step = 50000; // 50ms

        while ($this->processManager->isRunning('f-test01') && $waited < $maxWait) {
            usleep($step);
            $waited += $step;
        }

        // Should no longer be running
        $this->assertFalse($this->processManager->isRunning('f-test01'));
    }

    public function test_kill_non_existent_process_does_not_error(): void
    {
        // This should not throw any exceptions
        $this->processManager->kill('f-nonexistent');

        // Verify nothing changed
        $this->assertEquals(0, $this->processManager->getRunningCount());
        $this->assertFalse($this->processManager->isRunning('f-nonexistent'));
    }

    public function test_get_output_returns_process_output(): void
    {
        // For this test, we need a command that produces output
        // Since our mock uses 'sleep', we'll just check that output files are created
        $this->processManager->spawn(
            taskId: 'f-test01',
            agent: 'claude',
            command: 'sleep 0.01',
            cwd: '/tmp'
        );

        // Wait for the process to complete
        $maxWait = 2000000; // 2 seconds
        $waited = 0;
        $step = 50000; // 50ms

        while ($this->processManager->isRunning('f-test01') && $waited < $maxWait) {
            usleep($step);
            $waited += $step;
        }

        // Get the output
        $output = $this->processManager->getOutput('f-test01');

        // Verify output - check that we got the ProcessOutput object with paths at least
        $this->assertInstanceOf(ProcessOutput::class, $output);
        $this->assertStringContainsString('.fuel/processes/f-test01/stdout.log', $output->stdoutPath);
        $this->assertStringContainsString('.fuel/processes/f-test01/stderr.log', $output->stderrPath);

        // Check that output is captured (may be empty if sh doesn't work as expected)
        // At least check that the stdout and stderr properties exist
        $this->assertIsString($output->stdout);
        $this->assertIsString($output->stderr);
    }

    public function test_get_running_processes_returns_active_processes(): void
    {
        // Initially empty
        $this->assertEmpty($this->processManager->getRunningProcesses());

        // Spawn two processes
        $this->processManager->spawn(
            taskId: 'f-test01',
            agent: 'claude',
            command: 'sleep 2',
            cwd: '/tmp'
        );

        $this->processManager->spawn(
            taskId: 'f-test02',
            agent: 'claude',
            command: 'sleep 2',
            cwd: '/tmp'
        );

        $runningProcesses = $this->processManager->getRunningProcesses();
        $this->assertCount(2, $runningProcesses);

        // Verify the processes are the ones we spawned
        $taskIds = array_map(fn (Process $p): string => $p->taskId, $runningProcesses);
        $this->assertContains('f-test01', $taskIds);
        $this->assertContains('f-test02', $taskIds);

        // Kill one process
        $this->processManager->kill('f-test01');
        usleep(100000); // 100ms

        $runningProcesses = $this->processManager->getRunningProcesses();
        $this->assertCount(1, $runningProcesses);
        $this->assertEquals('f-test02', $runningProcesses[0]->taskId);

        // Clean up
        $this->processManager->kill('f-test02');
    }
}
