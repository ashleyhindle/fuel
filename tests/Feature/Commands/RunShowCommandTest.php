<?php

use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// RunShowCommand Tests
describe('run:show command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
        $this->runService = app(RunService::class);
    });

    it('shows error when run not found', function (): void {
        $this->artisan('run:show', ['id' => 'run-nonexist'])
            ->expectsOutputToContain("Run 'run-nonexist' not found")
            ->assertExitCode(1);
    });

    it('displays run details', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Test output',
        ]);

        $runs = $this->runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        Artisan::call('run:show', ['id' => $runId]);
        $output = Artisan::output();

        expect($output)->toContain('Run: '.$runId);
        expect($output)->toContain('test-agent');
        expect($output)->toContain('test-model');
        expect($output)->toContain('Test output');
    });

    it('displays run with full output from stdout.log', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runs = $this->runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        // Create stdout.log file
        $fuelContext = $this->app->make(FuelContext::class);
        $processDir = $fuelContext->getProcessesPath().'/'.$runId;
        mkdir($processDir, 0755, true);
        file_put_contents($processDir.'/stdout.log', 'Full output from file');

        Artisan::call('run:show', ['id' => $runId]);
        $output = Artisan::output();

        expect($output)->toContain('Full output from file');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runs = $this->runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        Artisan::call('run:show', ['id' => $runId, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKeys(['run_id', 'agent', 'model', 'started_at', 'ended_at', 'exit_code', 'duration']);
        expect($data['agent'])->toBe('test-agent');
        expect($data['model'])->toBe('test-model');
        expect($data['run_id'])->toBe($runId);
    });

    it('displays exit code with color', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 1,
        ]);

        $runs = $this->runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        Artisan::call('run:show', ['id' => $runId]);
        $output = Artisan::output();

        expect($output)->toContain('Exit code');
        expect($output)->toContain('1');
    });

    it('calculates and displays duration', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:30+00:00', // 5 minutes 30 seconds
            'exit_code' => 0,
        ]);

        $runs = $this->runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        Artisan::call('run:show', ['id' => $runId]);
        $output = Artisan::output();

        expect($output)->toContain('Duration');
        expect($output)->toContain('5m');
        expect($output)->toContain('30s');
    });
});
