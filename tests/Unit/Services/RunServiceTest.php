<?php

use App\Models\Run;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';

    // Configure database for Eloquent
    config(['database.connections.sqlite.database' => $this->dbPath]);

    $this->databaseService = new DatabaseService($this->dbPath);
    config(['database.connections.sqlite.database' => $this->dbPath]);
    Artisan::call('migrate', ['--force' => true]);

    // Create a test task
    $this->taskId = 'f-test01';
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$this->taskId, 'Test Task', 'open']
    );

    $this->runService = makeRunService();
});

afterEach(function (): void {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
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

it('logs a run to database', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    // Verify run was inserted into database
    $taskIntId = $this->databaseService->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$this->taskId]);
    $runs = $this->databaseService->fetchAll('SELECT * FROM runs WHERE task_id = ?', [$taskIntId['id']]);

    expect($runs)->toHaveCount(1);
    expect($runs[0]['agent'])->toBe('test-agent');
    expect($runs[0]['model'])->toBe('test-model');
});

it('logs a run with hash-based run_id', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0])->toBeInstanceOf(Run::class);
    expect($runs[0]->run_id)->toStartWith('run-');
    expect(strlen((string) $runs[0]->run_id))->toBe(10); // run- + 6 chars
    expect($runs[0]->agent)->toBe('test-agent');
    expect($runs[0]->model)->toBe('test-model');
});

it('logs a run with all schema fields', function (): void {
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
    expect($runs[0])->toBeInstanceOf(Run::class);
    expect($runs[0]->agent)->toBe('test-agent');
    expect($runs[0]->model)->toBe('test-model');
    expect($runs[0]->started_at->toIso8601String())->toBe($startedAt);
    expect($runs[0]->ended_at->toIso8601String())->toBe($endedAt);
    expect($runs[0]->exit_code)->toBe(0);
    expect($runs[0]->output)->toBe('Test output');
});

it('defaults started_at to current time when not provided', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]->started_at)->not->toBeNull();
    expect($runs[0]->started_at->toIso8601String())->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('allows null values for optional fields', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]->ended_at)->toBeNull();
    expect($runs[0]->exit_code)->toBeNull();
    expect($runs[0]->output)->toBeNull();
});

it('truncates output to 10KB', function (): void {
    $longOutput = str_repeat('a', 15000); // 15KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => $longOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen((string) $runs[0]->output))->toBe(10240); // Exactly 10KB
    expect($runs[0]->output)->toBe(substr($longOutput, 0, 10240));
});

it('does not truncate output under 10KB', function (): void {
    $shortOutput = str_repeat('a', 5000); // 5KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => $shortOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen((string) $runs[0]->output))->toBe(5000);
    expect($runs[0]->output)->toBe($shortOutput);
});

it('returns empty array when no runs exist', function (): void {
    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toBe([]);
});

it('returns all runs for a task', function (): void {
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
    expect($runs[0]->agent)->toBe('agent1');
    expect($runs[1]->agent)->toBe('agent2');
    expect($runs[2]->agent)->toBe('agent3');
});

it('returns null for latest run when no runs exist', function (): void {
    $latest = $this->runService->getLatestRun($this->taskId);

    expect($latest)->toBeNull();
});

it('returns the most recent run', function (): void {
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
    expect($latest)->toBeInstanceOf(Run::class);
    expect($latest->agent)->toBe('agent2');
    expect($latest->model)->toBe('model2');
});

it('generates unique run IDs', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $this->runService->logRun($this->taskId, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);
    }

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(10);
    $runIds = array_map(fn (Run $run): string => $run->run_id, $runs);
    expect(count(array_unique($runIds)))->toBe(10);
});

it('generates globally unique run IDs across all tasks', function (): void {
    $taskId1 = 'f-task1';
    $taskId2 = 'f-task2';

    // Create additional test tasks
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$taskId1, 'Test Task 1', 'open']
    );
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$taskId2, 'Test Task 2', 'open']
    );

    // Create runs for different tasks
    for ($i = 0; $i < 5; $i++) {
        $this->runService->logRun($taskId1, [
            'agent' => 'agent1',
            'model' => 'model1',
        ]);
    }

    for ($i = 0; $i < 5; $i++) {
        $this->runService->logRun($taskId2, [
            'agent' => 'agent2',
            'model' => 'model2',
        ]);
    }

    // Get all run IDs from database
    $allRuns = $this->databaseService->fetchAll('SELECT id FROM runs');
    $allRunIds = array_map(fn (array $run): string => $run['id'], $allRuns);

    // Verify we have 10 runs total
    expect($allRunIds)->toHaveCount(10);

    // Verify all run IDs are unique globally (not just per-task)
    expect(count(array_unique($allRunIds)))->toBe(10);
});

it('stores runs for different tasks separately', function (): void {
    $taskId1 = 'f-task1';
    $taskId2 = 'f-task2';

    // Create additional test tasks
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$taskId1, 'Test Task 1', 'open']
    );
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$taskId2, 'Test Task 2', 'open']
    );

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
    expect($runs1[0]->agent)->toBe('agent1');
    expect($runs2[0]->agent)->toBe('agent2');
});

it('handles empty output string', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => '',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]->output)->toBe('');
});

it('handles non-string output gracefully', function (): void {
    // If output is not a string, it should be stored as-is (no truncation)
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => null,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]->output)->toBeNull();
});

it('updates the latest run with completion data', function (): void {
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
    expect($runs[0]->started_at->toIso8601String())->toBe($startedAt);
    expect($runs[0]->ended_at->toIso8601String())->toBe($endedAt);
    expect($runs[0]->exit_code)->toBe(0);
    expect($runs[0]->output)->toBe('Test output');
});

it('updates a run with commit hash', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);
    $runId = $runs[0]->run_id;

    $this->runService->updateRun($runId, [
        'commit_hash' => 'abc123def456',
    ]);

    $updatedRun = $this->runService->findRun($runId);
    expect($updatedRun)->not->toBeNull();
    expect($updatedRun->commit_hash)->toBe('abc123def456');
});

it('updates the latest run with commit hash', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $this->runService->updateLatestRun($this->taskId, [
        'commit_hash' => 'fed321cba654',
    ]);

    $latestRun = $this->runService->getLatestRun($this->taskId);
    expect($latestRun)->not->toBeNull();
    expect($latestRun->commit_hash)->toBe('fed321cba654');
});

it('throws exception when updating non-existent run', function (): void {
    expect(fn () => $this->runService->updateLatestRun($this->taskId, [
        'ended_at' => '2026-01-07T10:05:00+00:00',
    ]))->toThrow(RuntimeException::class, 'No runs found');
});

it('updates only the latest run when multiple runs exist', function (): void {
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
    expect($runs[0]->exit_code)->toBeNull(); // First run unchanged
    expect($runs[1]->exit_code)->toBe(1); // Latest run updated
});

it('truncates output when updating latest run', function (): void {
    $longOutput = str_repeat('a', 15000); // 15KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'started_at' => '2026-01-07T10:00:00+00:00',
    ]);

    $this->runService->updateLatestRun($this->taskId, [
        'output' => $longOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen((string) $runs[0]->output))->toBe(10240); // Exactly 10KB
});

it('throws exception when logging run for non-existent task', function (): void {
    expect(fn () => $this->runService->logRun('f-nonexist', [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]))->toThrow(RuntimeException::class, 'Task f-nonexist not found');
});

it('returns empty array when getting runs for non-existent task', function (): void {
    $runs = $this->runService->getRuns('f-nonexist');

    expect($runs)->toBe([]);
});

it('returns null when getting latest run for non-existent task', function (): void {
    $latest = $this->runService->getLatestRun('f-nonexist');

    expect($latest)->toBeNull();
});

it('calculates duration_seconds when logging run with both timestamps', function (): void {
    $startedAt = '2026-01-07T10:00:00+00:00';
    $endedAt = '2026-01-07T10:05:00+00:00';

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]->duration_seconds)->toBe(300); // 5 minutes = 300 seconds
});

it('does not calculate duration_seconds when logging run with only started_at', function (): void {
    $startedAt = '2026-01-07T10:00:00+00:00';

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]->duration_seconds)->toBeNull();
});

it('calculates duration_seconds when updating latest run with ended_at', function (): void {
    $startedAt = '2026-01-07T10:00:00+00:00';
    $endedAt = '2026-01-07T10:10:00+00:00';

    // Create initial run
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
    ]);

    // Update with ended_at
    $this->runService->updateLatestRun($this->taskId, [
        'ended_at' => $endedAt,
        'exit_code' => 0,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]->duration_seconds)->toBe(600); // 10 minutes = 600 seconds
});

it('includes duration_seconds in getRuns response', function (): void {
    $startedAt = '2026-01-07T10:00:00+00:00';
    $endedAt = '2026-01-07T10:02:30+00:00';

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]->duration_seconds)->toBe(150); // 2.5 minutes = 150 seconds
});

it('includes duration_seconds in getLatestRun response', function (): void {
    $startedAt = '2026-01-07T10:00:00+00:00';
    $endedAt = '2026-01-07T10:07:00+00:00';

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
    ]);

    $latestRun = $this->runService->getLatestRun($this->taskId);

    expect($latestRun)->not->toBeNull();
    expect($latestRun)->toBeInstanceOf(Run::class);
    expect($latestRun->duration_seconds)->toBe(420); // 7 minutes = 420 seconds
});

it('finds a run by run_id', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'started_at' => '2026-01-07T10:00:00+00:00',
    ]);

    $runs = $this->runService->getRuns($this->taskId);
    $runId = $runs[0]->run_id;

    $foundRun = $this->runService->findRun($runId);

    expect($foundRun)->not->toBeNull();
    expect($foundRun)->toBeInstanceOf(Run::class);
    expect($foundRun->run_id)->toBe($runId);
    expect($foundRun->agent)->toBe('test-agent');
    expect($foundRun->model)->toBe('test-model');
});

it('returns null when finding non-existent run', function (): void {
    $foundRun = $this->runService->findRun('run-nonexist');

    expect($foundRun)->toBeNull();
});
