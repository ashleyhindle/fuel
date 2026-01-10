<?php

declare(strict_types=1);

namespace Tests\Unit\Process;

use App\Process\Process;
use App\Process\ProcessStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase
{
    public function test_process_tracks_status_correctly(): void
    {
        $process = new Process(
            id: 'p-123456',
            taskId: 'f-abcdef',
            agent: 'claude',
            command: 'echo "test"',
            cwd: '/tmp',
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        );

        $this->assertEquals(ProcessStatus::Running, $process->status);
        $this->assertTrue($process->isRunning());
        $this->assertNull($process->exitCode);
        $this->assertNull($process->completedAt);

        // Test completed process
        $completedProcess = new Process(
            id: 'p-123456',
            taskId: 'f-abcdef',
            agent: 'claude',
            command: 'echo "test"',
            cwd: '/tmp',
            pid: 12345,
            status: ProcessStatus::Completed,
            exitCode: 0,
            startedAt: new DateTimeImmutable,
            completedAt: new DateTimeImmutable
        );

        $this->assertEquals(ProcessStatus::Completed, $completedProcess->status);
        $this->assertFalse($completedProcess->isRunning());
        $this->assertEquals(0, $completedProcess->exitCode);
        $this->assertNotNull($completedProcess->completedAt);
    }

    public function test_process_calculates_duration(): void
    {
        $startedAt = new DateTimeImmutable('2024-01-01 10:00:00');
        $completedAt = new DateTimeImmutable('2024-01-01 10:00:30');

        $process = new Process(
            id: 'p-123456',
            taskId: 'f-abcdef',
            agent: 'claude',
            command: 'sleep 30',
            cwd: '/tmp',
            pid: 12345,
            status: ProcessStatus::Completed,
            exitCode: 0,
            startedAt: $startedAt,
            completedAt: $completedAt
        );

        $this->assertEquals(30, $process->getDurationSeconds());

        // Test running process uses current time
        $runningProcess = new Process(
            id: 'p-234567',
            taskId: 'f-bcdefg',
            agent: 'claude',
            command: 'sleep 60',
            cwd: '/tmp',
            pid: 23456,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable('-5 seconds')
        );

        $duration = $runningProcess->getDurationSeconds();
        $this->assertGreaterThanOrEqual(5, $duration);
        $this->assertLessThanOrEqual(6, $duration);

        // Test process with no start time
        $pendingProcess = new Process(
            id: 'p-345678',
            taskId: 'f-cdefgh',
            agent: 'claude',
            command: 'echo pending',
            cwd: '/tmp',
            pid: 0,
            status: ProcessStatus::Pending
        );

        $this->assertNull($pendingProcess->getDurationSeconds());
    }

    public function test_is_running_returns_correct_state(): void
    {
        $statuses = [
            [ProcessStatus::Pending, false],
            [ProcessStatus::Running, true],
            [ProcessStatus::Completed, false],
            [ProcessStatus::Failed, false],
            [ProcessStatus::Killed, false],
        ];

        foreach ($statuses as [$status, $expectedRunning]) {
            $process = new Process(
                id: 'p-test',
                taskId: 'f-test',
                agent: 'test',
                command: 'test',
                cwd: '/tmp',
                pid: 1234,
                status: $status,
                exitCode: $status === ProcessStatus::Running ? null : 0,
                startedAt: new DateTimeImmutable,
                completedAt: $status === ProcessStatus::Running ? null : new DateTimeImmutable
            );

            $this->assertEquals(
                $expectedRunning,
                $process->isRunning(),
                sprintf('Process with status %s should ', $status->value).($expectedRunning ? '' : 'not ').'be running'
            );
        }
    }
}
