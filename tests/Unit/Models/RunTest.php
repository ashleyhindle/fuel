<?php

declare(strict_types=1);

use App\Models\Run;

describe('Run Model', function (): void {
    it('allows access to properties via magic __get', function (): void {
        $run = new Run([
            'id' => 1,
            'short_id' => 'run-abc123',
            'task_id' => 5,
            'agent' => 'cursor-agent',
            'status' => 'completed',
            'exit_code' => 0,
            'started_at' => '2025-01-11T10:00:00+00:00',
            'ended_at' => '2025-01-11T10:05:00+00:00',
            'duration_seconds' => 300,
            'session_id' => 'sess-123',
            'error_type' => null,
            'model' => 'claude-3-opus',
            'output' => 'Task completed successfully',
            'cost_usd' => 0.05,
        ]);

        expect($run->id)->toBe(1);
        expect($run->short_id)->toBe('run-abc123');
        expect($run->task_id)->toBe(5);
        expect($run->agent)->toBe('cursor-agent');
        expect($run->status)->toBe('completed');
        expect($run->exit_code)->toBe(0);
        expect($run->started_at)->toBe('2025-01-11T10:00:00+00:00');
        expect($run->ended_at)->toBe('2025-01-11T10:05:00+00:00');
        expect($run->duration_seconds)->toBe(300);
        expect($run->session_id)->toBe('sess-123');
        expect($run->error_type)->toBeNull();
        expect($run->model)->toBe('claude-3-opus');
        expect($run->output)->toBe('Task completed successfully');
        expect($run->cost_usd)->toBe(0.05);
    });

    it('returns null for non-existent properties', function (): void {
        $run = new Run([]);
        expect($run->non_existent)->toBeNull();
    });

    describe('isRunning()', function (): void {
        it('returns true when status is running', function (): void {
            $run = new Run(['status' => 'running']);
            expect($run->isRunning())->toBeTrue();
        });

        it('returns false when status is completed', function (): void {
            $run = new Run(['status' => 'completed']);
            expect($run->isRunning())->toBeFalse();
        });

        it('returns false when status is failed', function (): void {
            $run = new Run(['status' => 'failed']);
            expect($run->isRunning())->toBeFalse();
        });
    });

    describe('isCompleted()', function (): void {
        it('returns true when status is completed', function (): void {
            $run = new Run(['status' => 'completed']);
            expect($run->isCompleted())->toBeTrue();
        });

        it('returns false when status is running', function (): void {
            $run = new Run(['status' => 'running']);
            expect($run->isCompleted())->toBeFalse();
        });

        it('returns false when status is failed', function (): void {
            $run = new Run(['status' => 'failed']);
            expect($run->isCompleted())->toBeFalse();
        });
    });

    describe('isFailed()', function (): void {
        it('returns true when status is failed', function (): void {
            $run = new Run(['status' => 'failed']);
            expect($run->isFailed())->toBeTrue();
        });

        it('returns false when status is running', function (): void {
            $run = new Run(['status' => 'running']);
            expect($run->isFailed())->toBeFalse();
        });

        it('returns false when status is completed', function (): void {
            $run = new Run(['status' => 'completed']);
            expect($run->isFailed())->toBeFalse();
        });
    });

    describe('getDurationFormatted()', function (): void {
        it('formats duration with hours, minutes, and seconds', function (): void {
            $run = new Run(['duration_seconds' => 3665]); // 1h 1m 5s
            expect($run->getDurationFormatted())->toBe('1h 1m 5s');
        });

        it('formats duration with hours and minutes only', function (): void {
            $run = new Run(['duration_seconds' => 3660]); // 1h 1m
            expect($run->getDurationFormatted())->toBe('1h 1m');
        });

        it('formats duration with hours and seconds only', function (): void {
            $run = new Run(['duration_seconds' => 3605]); // 1h 5s
            expect($run->getDurationFormatted())->toBe('1h 5s');
        });

        it('formats duration with minutes and seconds', function (): void {
            $run = new Run(['duration_seconds' => 125]); // 2m 5s
            expect($run->getDurationFormatted())->toBe('2m 5s');
        });

        it('formats duration with minutes only', function (): void {
            $run = new Run(['duration_seconds' => 120]); // 2m
            expect($run->getDurationFormatted())->toBe('2m');
        });

        it('formats duration with seconds only', function (): void {
            $run = new Run(['duration_seconds' => 45]);
            expect($run->getDurationFormatted())->toBe('45s');
        });

        it('formats zero duration as 0s', function (): void {
            $run = new Run(['duration_seconds' => 0]);
            expect($run->getDurationFormatted())->toBe('0s');
        });

        it('returns empty string when duration_seconds is null', function (): void {
            $run = new Run(['duration_seconds' => null]);
            expect($run->getDurationFormatted())->toBe('');
        });

        it('returns empty string when duration_seconds is not set', function (): void {
            $run = new Run([]);
            expect($run->getDurationFormatted())->toBe('');
        });

        it('formats large durations correctly', function (): void {
            $run = new Run(['duration_seconds' => 7384]); // 2h 3m 4s
            expect($run->getDurationFormatted())->toBe('2h 3m 4s');
        });
    });

    describe('getOutputLines()', function (): void {
        it('splits output into lines', function (): void {
            $run = new Run(['output' => "Line 1\nLine 2\nLine 3"]);
            expect($run->getOutputLines())->toBe(['Line 1', 'Line 2', 'Line 3']);
        });

        it('returns single line for output without newlines', function (): void {
            $run = new Run(['output' => 'Single line']);
            expect($run->getOutputLines())->toBe(['Single line']);
        });

        it('returns empty array for null output', function (): void {
            $run = new Run(['output' => null]);
            expect($run->getOutputLines())->toBe([]);
        });

        it('returns empty array for empty string output', function (): void {
            $run = new Run(['output' => '']);
            expect($run->getOutputLines())->toBe([]);
        });

        it('handles output with trailing newline', function (): void {
            $run = new Run(['output' => "Line 1\nLine 2\n"]);
            expect($run->getOutputLines())->toBe(['Line 1', 'Line 2', '']);
        });

        it('handles output with multiple consecutive newlines', function (): void {
            $run = new Run(['output' => "Line 1\n\n\nLine 2"]);
            expect($run->getOutputLines())->toBe(['Line 1', '', '', 'Line 2']);
        });
    });

    describe('fromArray()', function (): void {
        it('creates a Run instance from an array', function (): void {
            $data = [
                'id' => 1,
                'short_id' => 'run-xyz789',
                'task_id' => 10,
                'agent' => 'claude',
                'status' => 'running',
            ];

            $run = Run::fromArray($data);

            expect($run)->toBeInstanceOf(Run::class);
            expect($run->id)->toBe(1);
            expect($run->short_id)->toBe('run-xyz789');
            expect($run->task_id)->toBe(10);
            expect($run->agent)->toBe('claude');
            expect($run->status)->toBe('running');
        });

        it('creates a Run instance from an empty array', function (): void {
            $run = Run::fromArray([]);
            expect($run)->toBeInstanceOf(Run::class);
        });
    });

    describe('toArray()', function (): void {
        it('returns the underlying attributes array', function (): void {
            $data = [
                'id' => 1,
                'short_id' => 'run-abc123',
                'task_id' => 5,
                'agent' => 'cursor-agent',
            ];

            $run = new Run($data);
            expect($run->toArray())->toBe($data);
        });
    });

    describe('getAttribute()', function (): void {
        it('returns attribute value when it exists', function (): void {
            $run = new Run(['agent' => 'claude']);
            expect($run->getAttribute('agent'))->toBe('claude');
        });

        it('returns null when attribute does not exist', function (): void {
            $run = new Run([]);
            expect($run->getAttribute('non_existent'))->toBeNull();
        });

        it('returns default value when attribute does not exist', function (): void {
            $run = new Run([]);
            expect($run->getAttribute('non_existent', 'default'))->toBe('default');
        });
    });
});
