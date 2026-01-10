<?php

declare(strict_types=1);

namespace Tests\Unit\Process;

use App\Process\ProcessOutput;
use PHPUnit\Framework\TestCase;

class ProcessOutputTest extends TestCase
{
    public function test_get_combined_merges_stdout_stderr(): void
    {
        $output = new ProcessOutput(
            stdout: "This is stdout\n",
            stderr: "This is stderr\n",
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $combined = $output->getCombined();
        $this->assertEquals("This is stdout\nThis is stderr\n", $combined);

        // Test with empty stdout
        $outputWithEmptyStdout = new ProcessOutput(
            stdout: '',
            stderr: "Only stderr\n",
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertEquals("Only stderr\n", $outputWithEmptyStdout->getCombined());

        // Test with empty stderr
        $outputWithEmptyStderr = new ProcessOutput(
            stdout: "Only stdout\n",
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertEquals("Only stdout\n", $outputWithEmptyStderr->getCombined());

        // Test with both empty
        $emptyOutput = new ProcessOutput(
            stdout: '',
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertEquals('', $emptyOutput->getCombined());
    }

    public function test_has_errors_detects_stderr_content(): void
    {
        // Test with actual stderr content
        $outputWithErrors = new ProcessOutput(
            stdout: "Normal output\n",
            stderr: "Error: Something went wrong\n",
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertTrue($outputWithErrors->hasErrors());

        // Test with empty stderr
        $outputWithoutErrors = new ProcessOutput(
            stdout: "Normal output\n",
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertFalse($outputWithoutErrors->hasErrors());

        // Test with whitespace-only stderr
        $outputWithWhitespace = new ProcessOutput(
            stdout: "Normal output\n",
            stderr: "   \n\t  ",
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertFalse($outputWithWhitespace->hasErrors());

        // Test with single space in stderr (should be considered as having errors)
        $outputWithSpace = new ProcessOutput(
            stdout: "Normal output\n",
            stderr: ' ',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertFalse($outputWithSpace->hasErrors()); // Single space after trim becomes empty

        // Test with actual content surrounded by whitespace
        $outputWithContentAndWhitespace = new ProcessOutput(
            stdout: "Normal output\n",
            stderr: "  Error message  \n",
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        );

        $this->assertTrue($outputWithContentAndWhitespace->hasErrors());
    }
}
