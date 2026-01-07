<?php

use App\Services\RunService;
use RuntimeException;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storageBasePath = $this->tempDir.'/.fuel/runs';
    $this->runService = new RunService($this->storageBasePath);
    $this->taskId = 'f-test01';
});

afterEach(function () {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir) {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

it('creates runs directory on first write', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    expect(is_dir($this->storageBasePath))->toBeTrue();
    expect(file_exists($this->storageBasePath.'/'.$this->taskId.'.jsonl'))->toBeTrue();
});

it('logs a run with hash-based run_id', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]['run_id'])->toStartWith('run-');
    expect(strlen($runs[0]['run_id']))->toBe(10); // run- + 6 chars
    expect($runs[0]['agent'])->toBe('test-agent');
    expect($runs[0]['model'])->toBe('test-model');
});

it('logs a run with all schema fields', function () {
    $startedAt = '2026-01-07T10:00:00+00:00';
    $endedAt = '2026-01-07T10:05:00+00:00';

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'exit_code' => 0,
        'output' => 'Test output',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0])->toHaveKeys(['run_id', 'agent', 'model', 'started_at', 'ended_at', 'exit_code', 'output']);
    expect($runs[0]['agent'])->toBe('test-agent');
    expect($runs[0]['model'])->toBe('test-model');
    expect($runs[0]['started_at'])->toBe($startedAt);
    expect($runs[0]['ended_at'])->toBe($endedAt);
    expect($runs[0]['exit_code'])->toBe(0);
    expect($runs[0]['output'])->toBe('Test output');
});

it('defaults started_at to current time when not provided', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['started_at'])->not->toBeNull();
    expect($runs[0]['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('allows null values for optional fields', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['ended_at'])->toBeNull();
    expect($runs[0]['exit_code'])->toBeNull();
    expect($runs[0]['output'])->toBeNull();
});

it('truncates output to 10KB', function () {
    $longOutput = str_repeat('a', 15000); // 15KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => $longOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen($runs[0]['output']))->toBe(10240); // Exactly 10KB
    expect($runs[0]['output'])->toBe(substr($longOutput, 0, 10240));
});

it('does not truncate output under 10KB', function () {
    $shortOutput = str_repeat('a', 5000); // 5KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => $shortOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen($runs[0]['output']))->toBe(5000);
    expect($runs[0]['output'])->toBe($shortOutput);
});

it('returns empty array when no runs exist', function () {
    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toBe([]);
});

it('returns all runs for a task', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'agent1',
        'model' => 'model1',
    ]);

    $this->runService->logRun($this->taskId, [
        'agent' => 'agent2',
        'model' => 'model2',
    ]);

    $this->runService->logRun($this->taskId, [
        'agent' => 'agent3',
        'model' => 'model3',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(3);
    expect($runs[0]['agent'])->toBe('agent1');
    expect($runs[1]['agent'])->toBe('agent2');
    expect($runs[2]['agent'])->toBe('agent3');
});

it('returns null for latest run when no runs exist', function () {
    $latest = $this->runService->getLatestRun($this->taskId);

    expect($latest)->toBeNull();
});

it('returns the most recent run', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'agent1',
        'model' => 'model1',
    ]);

    $this->runService->logRun($this->taskId, [
        'agent' => 'agent2',
        'model' => 'model2',
    ]);

    $latest = $this->runService->getLatestRun($this->taskId);

    expect($latest)->not->toBeNull();
    expect($latest['agent'])->toBe('agent2');
    expect($latest['model'])->toBe('model2');
});

it('generates unique run IDs', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->runService->logRun($this->taskId, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);
    }

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(10);
    $runIds = array_map(fn (array $run): string => $run['run_id'], $runs);
    expect(count(array_unique($runIds)))->toBe(10);
});

it('stores runs for different tasks separately', function () {
    $taskId1 = 'f-task1';
    $taskId2 = 'f-task2';

    $this->runService->logRun($taskId1, [
        'agent' => 'agent1',
        'model' => 'model1',
    ]);

    $this->runService->logRun($taskId2, [
        'agent' => 'agent2',
        'model' => 'model2',
    ]);

    $runs1 = $this->runService->getRuns($taskId1);
    $runs2 = $this->runService->getRuns($taskId2);

    expect($runs1)->toHaveCount(1);
    expect($runs2)->toHaveCount(1);
    expect($runs1[0]['agent'])->toBe('agent1');
    expect($runs2[0]['agent'])->toBe('agent2');
});

it('handles empty output string', function () {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => '',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['output'])->toBe('');
});

it('handles non-string output gracefully', function () {
    // If output is not a string, it should be stored as-is (no truncation)
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => null,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['output'])->toBeNull();
});

it('updates the latest run with completion data', function () {
    $startedAt = '2026-01-07T10:00:00+00:00';
    $endedAt = '2026-01-07T10:05:00+00:00';

    // Create initial run
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
    ]);

    // Update with completion data
    $this->runService->updateLatestRun($this->taskId, [
        'ended_at' => $endedAt,
        'exit_code' => 0,
        'output' => 'Test output',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]['started_at'])->toBe($startedAt);
    expect($runs[0]['ended_at'])->toBe($endedAt);
    expect($runs[0]['exit_code'])->toBe(0);
    expect($runs[0]['output'])->toBe('Test output');
});

it('throws exception when updating non-existent run', function () {
    expect(fn () => $this->runService->updateLatestRun($this->taskId, [
        'ended_at' => '2026-01-07T10:05:00+00:00',
    ]))->toThrow(RuntimeException::class, 'No runs found');
});

it('updates only the latest run when multiple runs exist', function () {
    // Create two runs
    $this->runService->logRun($this->taskId, [
        'agent' => 'agent1',
        'started_at' => '2026-01-07T10:00:00+00:00',
    ]);

    $this->runService->logRun($this->taskId, [
        'agent' => 'agent2',
        'started_at' => '2026-01-07T10:05:00+00:00',
    ]);

    // Update only the latest run
    $this->runService->updateLatestRun($this->taskId, [
        'exit_code' => 1,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(2);
    expect($runs[0]['exit_code'])->toBeNull(); // First run unchanged
    expect($runs[1]['exit_code'])->toBe(1); // Latest run updated
});

it('truncates output when updating latest run', function () {
    $longOutput = str_repeat('a', 15000); // 15KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'started_at' => '2026-01-07T10:00:00+00:00',
    ]);

    $this->runService->updateLatestRun($this->taskId, [
        'output' => $longOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen($runs[0]['output']))->toBe(10240); // Exactly 10KB
});
