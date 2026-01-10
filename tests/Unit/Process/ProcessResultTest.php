<?php

declare(strict_types=1);

namespace Tests\Unit\Process;

use App\Process\Process;
use App\Process\ProcessOutput;
use App\Process\ProcessResult;
use App\Process\ProcessStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessResultTest extends TestCase
{
    public function test_was_successful_checks_exit_code(): void
    {
        $successProcess = new Process(
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

        $output = new ProcessOutput(
            stdout: 'test',
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $successResult = new ProcessResult(
            process: $successProcess,
            output: $output,
            success: true
        );

        $this->assertTrue($successResult->wasSuccessful());
        $this->assertEquals(0, $successResult->getExitCode());

        // Test failed process
        $failedProcess = new Process(
            id: 'p-234567',
            taskId: 'f-bcdefg',
            agent: 'claude',
            command: 'exit 1',
            cwd: '/tmp',
            pid: 23456,
            status: ProcessStatus::Failed,
            exitCode: 1,
            startedAt: new DateTimeImmutable,
            completedAt: new DateTimeImmutable
        );

        $errorOutput = new ProcessOutput(
            stdout: '',
            stderr: 'Error occurred',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $failedResult = new ProcessResult(
            process: $failedProcess,
            output: $errorOutput,
            success: false
        );

        $this->assertFalse($failedResult->wasSuccessful());
        $this->assertEquals(1, $failedResult->getExitCode());
    }

    public function test_exposes_process_properties(): void
    {
        $startedAt = new DateTimeImmutable('2024-01-01 10:00:00');
        $completedAt = new DateTimeImmutable('2024-01-01 10:00:45');

        $process = new Process(
            id: 'p-123456',
            taskId: 'f-abcdef',
            agent: 'claude',
            command: 'long-running-task',
            cwd: '/tmp',
            pid: 12345,
            status: ProcessStatus::Completed,
            exitCode: 0,
            startedAt: $startedAt,
            completedAt: $completedAt
        );

        $output = new ProcessOutput(
            stdout: 'Task output',
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $result = new ProcessResult(
            process: $process,
            output: $output,
            success: true
        );

        // Test exposed process properties
        $this->assertEquals('f-abcdef', $result->getTaskId());
        $this->assertEquals(0, $result->getExitCode());
        $this->assertEquals(45, $result->getDurationSeconds());
        $this->assertTrue($result->wasSuccessful());

        // Test access to underlying objects
        $this->assertSame($process, $result->process);
        $this->assertSame($output, $result->output);
        $this->assertEquals('claude', $result->process->agent);
        $this->assertEquals('Task output', $result->output->stdout);

        // Test with null exit code (defaults to 1)
        $runningProcess = new Process(
            id: 'p-234567',
            taskId: 'f-bcdefg',
            agent: 'claude',
            command: 'still-running',
            cwd: '/tmp',
            pid: 23456,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable,
            completedAt: null
        );

        $runningResult = new ProcessResult(
            process: $runningProcess,
            output: $output,
            success: false
        );

        $this->assertEquals(1, $runningResult->getExitCode()); // Default to 1 when null

        // Test with null duration (defaults to 0)
        $pendingProcess = new Process(
            id: 'p-345678',
            taskId: 'f-cdefgh',
            agent: 'claude',
            command: 'pending',
            cwd: '/tmp',
            pid: 0,
            status: ProcessStatus::Pending,
            exitCode: null,
            startedAt: null,
            completedAt: null
        );

        $pendingResult = new ProcessResult(
            process: $pendingProcess,
            output: $output,
            success: false
        );

        $this->assertEquals(0, $pendingResult->getDurationSeconds()); // Default to 0 when null
    }
}
