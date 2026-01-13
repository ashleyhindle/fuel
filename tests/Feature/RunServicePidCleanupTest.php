<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Services\ProcessManager;
use App\Services\RunService;
use Tests\TestCase;

class RunServicePidCleanupTest extends TestCase
{
    private RunService $runService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runService = new RunService;
    }

    public function test_cleanup_marks_run_as_failed_when_pid_is_dead(): void
    {
        // Create a task
        $task = Task::create([
            'short_id' => 'f-test01',
            'title' => 'Test task',
            'status' => 'open',
        ]);

        // Use a PID that definitely doesn't exist (999999)
        // This PID is unlikely to be a real process
        $deadPid = 999999;

        // Create a run with a dead PID and runner_instance_id
        $runId = $this->runService->createRun('f-test01', [
            'pid' => $deadPid,
            'runner_instance_id' => 'runner-abc123',
            'agent' => 'test-agent',
        ]);

        // Verify the PID is actually dead before testing
        $this->assertFalse(ProcessManager::isProcessAlive($deadPid), 'Test PID should be dead');

        // Call cleanupOrphanedRuns
        $cleanedCount = $this->runService->cleanupOrphanedRuns();

        // Verify run was marked as failed
        $this->assertEquals(1, $cleanedCount);

        $run = $this->runService->getLatestRun('f-test01');
        $this->assertNotNull($run);
        $this->assertEquals('failed', $run->status);
        $this->assertEquals(-1, $run->exit_code);
        $this->assertNotNull($run->ended_at);
        $this->assertStringContainsString('orphaned', $run->output);
    }

    public function test_cleanup_does_not_mark_run_as_failed_when_pid_is_alive(): void
    {
        // Create a task
        $task = Task::create([
            'short_id' => 'f-test02',
            'title' => 'Test task 2',
            'status' => 'open',
        ]);

        // Use the test process's own PID - guaranteed to be alive during the test
        $alivePid = getmypid();

        // Verify the PID is actually alive before testing
        $this->assertTrue(ProcessManager::isProcessAlive($alivePid), "Test PID $alivePid should be alive");

        // Create a run with the alive PID and runner_instance_id
        $runId = $this->runService->createRun('f-test02', [
            'pid' => $alivePid,
            'runner_instance_id' => 'runner-xyz789',
            'agent' => 'test-agent',
        ]);

        // Verify the run was created with the correct PID
        $run = $this->runService->getLatestRun('f-test02');
        $this->assertEquals($alivePid, $run->pid, "Run should have PID $alivePid");

        // Call cleanupOrphanedRuns
        $cleanedCount = $this->runService->cleanupOrphanedRuns();

        // Verify run was NOT marked as failed (still running)
        $this->assertEquals(0, $cleanedCount);

        $run = $this->runService->getLatestRun('f-test02');
        $this->assertNotNull($run);
        $this->assertEquals('running', $run->status);
        $this->assertNull($run->exit_code);
        $this->assertNull($run->ended_at);
    }

    public function test_cleanup_marks_run_as_failed_when_pid_is_null(): void
    {
        // Create a task
        $task = Task::create([
            'short_id' => 'f-test03',
            'title' => 'Test task 3',
            'status' => 'open',
        ]);

        // Create a run with no PID (legacy behavior)
        $runId = $this->runService->createRun('f-test03', [
            'agent' => 'test-agent',
            // pid and runner_instance_id are not set (null)
        ]);

        // Call cleanupOrphanedRuns
        $cleanedCount = $this->runService->cleanupOrphanedRuns();

        // Verify run was marked as failed (legacy behavior)
        $this->assertEquals(1, $cleanedCount);

        $run = $this->runService->getLatestRun('f-test03');
        $this->assertNotNull($run);
        $this->assertEquals('failed', $run->status);
        $this->assertEquals(-1, $run->exit_code);
        $this->assertNotNull($run->ended_at);
        $this->assertStringContainsString('orphaned', $run->output);
    }

    public function test_cleanup_only_affects_running_runs(): void
    {
        // Create a task
        $task = Task::create([
            'short_id' => 'f-test04',
            'title' => 'Test task 4',
            'status' => 'open',
        ]);

        // Create a run that's already completed
        $runId = $this->runService->createRun('f-test04', [
            'pid' => 11111,
            'runner_instance_id' => 'runner-completed',
            'agent' => 'test-agent',
        ]);

        // Mark it as completed
        $this->runService->updateLatestRun('f-test04', [
            'ended_at' => now()->toIso8601String(),
            'exit_code' => 0,
        ]);

        // Call cleanupOrphanedRuns
        $cleanedCount = $this->runService->cleanupOrphanedRuns();

        // Verify no runs were cleaned up (already completed)
        $this->assertEquals(0, $cleanedCount);

        $run = $this->runService->getLatestRun('f-test04');
        $this->assertNotNull($run);
        $this->assertEquals('completed', $run->status);
        $this->assertEquals(0, $run->exit_code);
    }

    public function test_cleanup_handles_multiple_runs_with_different_states(): void
    {
        // Create tasks
        Task::create(['short_id' => 'f-test05', 'title' => 'Dead PID', 'status' => 'open']);
        Task::create(['short_id' => 'f-test06', 'title' => 'Alive PID', 'status' => 'open']);
        Task::create(['short_id' => 'f-test07', 'title' => 'No PID', 'status' => 'open']);

        // Use the test process's own PID - guaranteed to be alive during the test
        $alivePid = getmypid();

        // Create runs
        $this->runService->createRun('f-test05', ['pid' => 999998, 'agent' => 'test-agent']); // Dead PID
        $this->runService->createRun('f-test06', ['pid' => $alivePid, 'agent' => 'test-agent']); // Alive PID
        $this->runService->createRun('f-test07', ['agent' => 'test-agent']); // No PID

        // Verify PIDs before testing
        $this->assertFalse(ProcessManager::isProcessAlive(999998), 'Dead PID should be dead');
        $this->assertTrue(ProcessManager::isProcessAlive($alivePid), 'Alive PID should be alive');

        // Call cleanupOrphanedRuns
        $cleanedCount = $this->runService->cleanupOrphanedRuns();

        // Verify only 2 runs were cleaned up (dead PID + no PID)
        $this->assertEquals(2, $cleanedCount);

        // Verify states
        $run1 = $this->runService->getLatestRun('f-test05');
        $this->assertEquals('failed', $run1->status);

        $run2 = $this->runService->getLatestRun('f-test06');
        $this->assertEquals('running', $run2->status);

        $run3 = $this->runService->getLatestRun('f-test07');
        $this->assertEquals('failed', $run3->status);
    }
}
