<?php

use App\Services\DatabaseService;
use App\Services\RunService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';

    $this->databaseService = new DatabaseService($this->dbPath);
    $this->databaseService->initialize();

    // Create a test task
    $this->taskId = 'f-test01';
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$this->taskId, 'Test Task', 'open']
    );

    $this->runService = new RunService($this->databaseService);
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
    expect($runs[0]['run_id'])->toStartWith('run-');
    expect(strlen((string) $runs[0]['run_id']))->toBe(10); // run- + 6 chars
    expect($runs[0]['agent'])->toBe('test-agent');
    expect($runs[0]['model'])->toBe('test-model');
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
    expect($runs[0])->toHaveKeys(['run_id', 'agent', 'model', 'started_at', 'ended_at', 'exit_code', 'output']);
    expect($runs[0]['agent'])->toBe('test-agent');
    expect($runs[0]['model'])->toBe('test-model');
    expect($runs[0]['started_at'])->toBe($startedAt);
    expect($runs[0]['ended_at'])->toBe($endedAt);
    expect($runs[0]['exit_code'])->toBe(0);
    expect($runs[0]['output'])->toBe('Test output');
});

it('defaults started_at to current time when not provided', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['started_at'])->not->toBeNull();
    expect($runs[0]['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('allows null values for optional fields', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['ended_at'])->toBeNull();
    expect($runs[0]['exit_code'])->toBeNull();
    expect($runs[0]['output'])->toBeNull();
});

it('truncates output to 10KB', function (): void {
    $longOutput = str_repeat('a', 15000); // 15KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => $longOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen((string) $runs[0]['output']))->toBe(10240); // Exactly 10KB
    expect($runs[0]['output'])->toBe(substr($longOutput, 0, 10240));
});

it('does not truncate output under 10KB', function (): void {
    $shortOutput = str_repeat('a', 5000); // 5KB

    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => $shortOutput,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect(strlen((string) $runs[0]['output']))->toBe(5000);
    expect($runs[0]['output'])->toBe($shortOutput);
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
    expect($runs[0]['agent'])->toBe('agent1');
    expect($runs[1]['agent'])->toBe('agent2');
    expect($runs[2]['agent'])->toBe('agent3');
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
    expect($latest['agent'])->toBe('agent2');
    expect($latest['model'])->toBe('model2');
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
    $runIds = array_map(fn (array $run): string => $run['run_id'], $runs);
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
    expect($runs1[0]['agent'])->toBe('agent1');
    expect($runs2[0]['agent'])->toBe('agent2');
});

it('handles empty output string', function (): void {
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => '',
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['output'])->toBe('');
});

it('handles non-string output gracefully', function (): void {
    // If output is not a string, it should be stored as-is (no truncation)
    $this->runService->logRun($this->taskId, [
        'agent' => 'test-agent',
        'model' => 'test-model',
        'output' => null,
    ]);

    $runs = $this->runService->getRuns($this->taskId);

    expect($runs[0]['output'])->toBeNull();
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
    expect($runs[0]['started_at'])->toBe($startedAt);
    expect($runs[0]['ended_at'])->toBe($endedAt);
    expect($runs[0]['exit_code'])->toBe(0);
    expect($runs[0]['output'])->toBe('Test output');
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
    expect($runs[0]['exit_code'])->toBeNull(); // First run unchanged
    expect($runs[1]['exit_code'])->toBe(1); // Latest run updated
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

    expect(strlen((string) $runs[0]['output']))->toBe(10240); // Exactly 10KB
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

it('imports runs from JSONL files', function (): void {
    // Create a runs directory with JSONL files
    $runsDir = $this->tempDir.'/.fuel/runs';
    mkdir($runsDir, 0755, true);

    // Create a task to import runs for
    $taskId = 'f-import1';
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$taskId, 'Import Test Task', 'open']
    );

    // Create a JSONL file with sample runs
    $jsonlPath = $runsDir.'/'.$taskId.'.jsonl';
    $runs = [
        [
            'run_id' => 'run-abc123',
            'agent' => 'test-agent-1',
            'model' => 'test-model-1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Test output 1',
        ],
        [
            'run_id' => 'run-def456',
            'agent' => 'test-agent-2',
            'model' => 'test-model-2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
            'output' => 'Test output 2',
        ],
    ];

    file_put_contents($jsonlPath, implode("\n", array_map(fn ($r) => json_encode($r), $runs)));

    // Import runs from JSONL
    $imported = $this->databaseService->importRunsFromJsonl();

    expect($imported)->toBe(2);

    // Verify runs were imported
    $importedRuns = $this->runService->getRuns($taskId);
    expect($importedRuns)->toHaveCount(2);
    expect($importedRuns[0]['run_id'])->toBe('run-abc123');
    expect($importedRuns[0]['agent'])->toBe('test-agent-1');
    expect($importedRuns[0]['model'])->toBe('test-model-1');
    expect($importedRuns[1]['run_id'])->toBe('run-def456');
    expect($importedRuns[1]['agent'])->toBe('test-agent-2');
});

it('skips duplicate runs when importing from JSONL', function (): void {
    // Create a runs directory with JSONL files
    $runsDir = $this->tempDir.'/.fuel/runs';
    mkdir($runsDir, 0755, true);

    // Create a task
    $taskId = 'f-import2';
    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status) VALUES (?, ?, ?)',
        [$taskId, 'Duplicate Test Task', 'open']
    );

    // Manually insert a run that will appear in the JSONL
    $this->databaseService->query(
        'INSERT INTO runs (id, task_id, agent, model, started_at, status) VALUES (?, ?, ?, ?, ?, ?)',
        [
            'run-existing',
            $this->databaseService->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$taskId])['id'],
            'existing-agent',
            'existing-model',
            '2026-01-07T09:00:00+00:00',
            RunService::STATUS_COMPLETED,
        ]
    );

    // Create JSONL with duplicate run_id and one new run
    $jsonlPath = $runsDir.'/'.$taskId.'.jsonl';
    $runs = [
        [
            'run_id' => 'run-existing', // Duplicate
            'agent' => 'existing-agent',
            'model' => 'existing-model',
            'started_at' => '2026-01-07T09:00:00+00:00',
        ],
        [
            'run_id' => 'run-new123',
            'agent' => 'new-agent',
            'model' => 'new-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ],
    ];

    file_put_contents($jsonlPath, implode("\n", array_map(fn ($r) => json_encode($r), $runs)));

    // Import - should skip duplicate and import only the new run
    $imported = $this->databaseService->importRunsFromJsonl();

    expect($imported)->toBe(1);

    // Verify we have 2 runs total (1 existing + 1 new)
    $importedRuns = $this->runService->getRuns($taskId);
    expect($importedRuns)->toHaveCount(2);
    expect($importedRuns[0]['run_id'])->toBe('run-existing');
    expect($importedRuns[1]['run_id'])->toBe('run-new123');
});

it('skips JSONL import when no runs directory exists', function (): void {
    // Don't create a runs directory
    $imported = $this->databaseService->importRunsFromJsonl();

    expect($imported)->toBe(0);
});

it('skips JSONL import for tasks that no longer exist', function (): void {
    // Create a runs directory with JSONL for a non-existent task
    $runsDir = $this->tempDir.'/.fuel/runs';
    mkdir($runsDir, 0755, true);

    $jsonlPath = $runsDir.'/f-deleted.jsonl';
    $runs = [
        [
            'run_id' => 'run-orphan1',
            'agent' => 'orphan-agent',
            'model' => 'orphan-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ],
    ];

    file_put_contents($jsonlPath, implode("\n", array_map(fn ($r) => json_encode($r), $runs)));

    // Import should skip this file since task doesn't exist
    $imported = $this->databaseService->importRunsFromJsonl();

    expect($imported)->toBe(0);
});
