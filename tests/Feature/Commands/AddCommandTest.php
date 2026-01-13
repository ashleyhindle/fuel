<?php

use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

/**
 * Helper function to run a command with piped stdin input.
 */
function runCommandWithPipedInput(string $command, string $stdinContent, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (! is_resource($process)) {
        throw new \RuntimeException('Failed to open process');
    }

    // Write piped content to stdin
    fwrite($pipes[0], $stdinContent);
    fclose($pipes[0]);

    // Read stdout and stderr
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

// Add Command Tests
describe('add command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $context->configureDatabase();
        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
        Artisan::call('migrate', ['--force' => true]);

        $taskService = makeTaskService();
        $this->app->singleton(TaskService::class, fn (): TaskService => $taskService);
        $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService($taskService));

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

        $this->taskService = $taskService;
    });

    afterEach(function (): void {
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
                } elseif (file_exists($path)) {
                    unlink($path);
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

    it('creates a task via CLI', function (): void {
        $this->artisan('add', ['title' => 'My test task'])
            ->expectsOutputToContain('Created task: f-')
            ->assertExitCode(0);

        expect(file_exists($this->dbPath))->toBeTrue();
    });

    it('outputs JSON when --json flag is used', function (): void {
        Artisan::call('add', ['title' => 'JSON task', '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "open"');
        expect($output)->toContain('"title": "JSON task"');
        expect($output)->toContain('"short_id": "f-');
    });

    it('creates task in custom cwd', function (): void {
        $this->artisan('add', ['title' => 'Custom path task'])
            ->assertExitCode(0);

        expect(file_exists($this->dbPath))->toBeTrue();

        // Verify task was created in the database
        $tasks = $this->taskService->all();
        expect($tasks->pluck('title')->toArray())->toContain('Custom path task');
    });

    it('creates task with --description flag', function (): void {
        Artisan::call('add', [
            'title' => 'Task with description',
            '--description' => 'This is a detailed description',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['description'])->toBe('This is a detailed description');
    });

    it('creates task with -d flag (description shortcut)', function (): void {
        Artisan::call('add', [
            'title' => 'Task with -d flag',
            '-d' => 'Short description',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['description'])->toBe('Short description');
    });

    it('creates task with multi-line description via --description flag', function (): void {
        $multilineDesc = "Line 1\nLine 2\nLine 3\nLine 4";

        Artisan::call('add', [
            'title' => 'Task with multiline description',
            '--description' => $multilineDesc,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['description'])->toBe($multilineDesc);
        expect($task['description'])->toContain("\n");
        expect(substr_count((string) $task['description'], "\n"))->toBe(3);
    });

    it('creates task with --type flag', function (): void {
        Artisan::call('add', [
            'title' => 'Bug fix',
            '--type' => 'bug',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['type'])->toBe('bug');
    });

    it('validates --type flag enum', function (): void {
        $this->artisan('add', [
            'title' => 'Invalid type',
            '--type' => 'invalid-type',
        ])
            ->expectsOutputToContain('Invalid task type')
            ->assertExitCode(1);
    });

    it('creates task with --priority flag', function (): void {
        Artisan::call('add', [
            'title' => 'High priority task',
            '--priority' => '4',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['priority'])->toBe(4);
    });

    it('validates --priority flag range', function (): void {
        $this->artisan('add', [
            'title' => 'Invalid priority',
            '--priority' => '5',
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('validates --priority flag is integer', function (): void {
        $this->artisan('add', [
            'title' => 'Invalid priority',
            '--priority' => 'high',
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('creates task with --labels flag', function (): void {
        Artisan::call('add', [
            'title' => 'Labeled task',
            '--labels' => 'frontend,backend,urgent',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['labels'])->toBe(['frontend', 'backend', 'urgent']);
    });

    it('handles --labels flag with spaces', function (): void {
        Artisan::call('add', [
            'title' => 'Labeled task',
            '--labels' => 'frontend, backend, urgent',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['labels'])->toBe(['frontend', 'backend', 'urgent']);
    });

    it('creates task with all flags together', function (): void {
        Artisan::call('add', [
            'title' => 'Complete task',
            '--description' => 'Full featured task',
            '--type' => 'feature',
            '--priority' => '3',
            '--labels' => 'ui,backend',
            '--complexity' => 'moderate',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete task');
        expect($task['description'])->toBe('Full featured task');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(3);
        expect($task['labels'])->toBe(['ui', 'backend']);
        expect($task['complexity'])->toBe('moderate');
    });

    it('creates task with --complexity flag', function (): void {
        Artisan::call('add', [
            'title' => 'Complex task',
            '--complexity' => 'complex',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['complexity'])->toBe('complex');
    });

    it('defaults complexity to simple when not specified', function (): void {
        Artisan::call('add', [
            'title' => 'Task without complexity',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['complexity'])->toBe('simple');
    });

    it('validates --complexity flag enum', function (): void {
        $this->artisan('add', [
            'title' => 'Invalid complexity',
            '--complexity' => 'invalid-complexity',
        ])
            ->expectsOutputToContain('Invalid task complexity')
            ->assertExitCode(1);
    });

    it('creates task with --blocked-by flag (single blocker)', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(1);
        expect($task['blocked_by'])->toContain($blocker->short_id);
    });

    it('creates task with --blocked-by flag (multiple blockers)', function (): void {
        $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
        $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker1->short_id.','.$blocker2->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(2);
        expect($task['blocked_by'])->toContain($blocker1->short_id);
        expect($task['blocked_by'])->toContain($blocker2->short_id);
    });

    it('creates task with --blocked-by flag (with spaces)', function (): void {
        $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
        $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker1->short_id.', '.$blocker2->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(2);
        expect($task['blocked_by'])->toContain($blocker1->short_id);
        expect($task['blocked_by'])->toContain($blocker2->short_id);
    });

    it('displays blocked-by info in non-JSON output', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-');
        expect($output)->toContain('Blocked by:');
        expect($output)->toContain($blocker->short_id);
    });

    it('creates task with --blocked-by and other flags', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Complete blocked task',
            '--description' => 'Blocked feature',
            '--type' => 'feature',
            '--priority' => '2',
            '--labels' => 'backend',
            '--blocked-by' => $blocker->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete blocked task');
        expect($task['description'])->toBe('Blocked feature');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(2);
        expect($task['labels'])->toBe(['backend']);
        expect($task['blocked_by'])->toHaveCount(1);
        expect($task['blocked_by'])->toContain($blocker->short_id);
    });

    it('supports partial IDs in --blocked-by flag', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $partialId = substr((string) $blocker->short_id, 2, 3); // Just hash part

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $partialId,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(1);
        // Note: TaskService.create() stores the ID as provided, so partial ID will be stored
        // The dependency resolution happens when checking blockers, not at creation time
        expect($task['blocked_by'])->toContain($partialId);
    });

    it('creates task with --epic flag', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Task with epic',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        // Verify task is linked to the epic (epic_id is set)
        expect($task['epic_id'])->not->toBeNull();
    });

    it('creates task with -e flag (epic shortcut)', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Task with epic shortcut',
            '-e' => $epic->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        // Verify task is linked to the epic (epic_id is set)
        expect($task['epic_id'])->not->toBeNull();
    });

    it('validates epic exists when using --epic flag', function (): void {
        $this->artisan('add', [
            'title' => 'Task with invalid epic',
            '--epic' => 'e-nonexistent',
        ])
            ->expectsOutputToContain("Epic 'e-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('creates task with --epic and other flags', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Complete task with epic',
            '--description' => 'Epic feature',
            '--type' => 'feature',
            '--priority' => '2',
            '--labels' => 'backend',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete task with epic');
        expect($task['description'])->toBe('Epic feature');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(2);
        expect($task['labels'])->toBe(['backend']);
        // Verify task is linked to the epic (epic_id is set)
        expect($task['epic_id'])->not->toBeNull();
    });

    it('supports partial epic IDs in --epic flag', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');
        $partialId = substr((string) $epic->short_id, 2, 3); // Just hash part

        Artisan::call('add', [
            'title' => 'Task with partial epic ID',
            '--epic' => $partialId,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        // Verify task is linked to the epic (epic_id is set)
        expect($task['epic_id'])->not->toBeNull();
    });

    it('adds item to backlog with --someday flag', function (): void {
        Artisan::call('add', [
            'title' => 'Future idea',
            '--someday' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-');
        expect($output)->toContain('Title: Future idea');
        expect($output)->toContain('Status: someday');

        // Verify it's in tasks with status=someday
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(1);
        expect($tasks->first()->title)->toBe('Future idea');
        expect($tasks->first()->status)->toBe(TaskStatus::Someday);
    });

    it('adds item to backlog with --someday and --description flags', function (): void {
        Artisan::call('add', [
            'title' => 'Future enhancement',
            '--description' => 'This is a future idea',
            '--someday' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $item = json_decode($output, true);

        expect($item['short_id'])->toStartWith('f-');
        expect($item['title'])->toBe('Future enhancement');
        expect($item['description'])->toBe('This is a future idea');
        expect($item['status'])->toBe('someday');
        // Task fields are present (with defaults if not specified)
        expect($item)->toHaveKey('priority');
        expect($item)->toHaveKey('type');
    });

    it('respects task-specific flags when --someday is used', function (): void {
        Artisan::call('add', [
            'title' => 'Backlog item',
            '--someday' => true,
            '--priority' => '4',
            '--type' => 'feature',
            '--labels' => 'urgent',
            '--complexity' => 'complex',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $item = json_decode($output, true);

        // Someday tasks have all task fields
        expect($item['short_id'])->toStartWith('f-');
        expect($item['title'])->toBe('Backlog item');
        expect($item['status'])->toBe('someday');
        expect($item['priority'])->toBe(4);
        expect($item['type'])->toBe('feature');
        expect($item['labels'])->toBe(['urgent']);
        expect($item['complexity'])->toBe('complex');
    });

    it('outputs JSON when --json flag is used with --someday', function (): void {
        Artisan::call('add', [
            'title' => 'JSON backlog item',
            '--someday' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $item = json_decode($output, true);

        expect($item)->toBeArray();
        expect($item['short_id'])->toStartWith('f-');
        expect($item['title'])->toBe('JSON backlog item');
        expect($item['status'])->toBe('someday');
    });

    it('adds item to backlog with --backlog flag (alias for --someday)', function (): void {
        Artisan::call('add', [
            'title' => 'Future idea via backlog',
            '--backlog' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-');
        expect($output)->toContain('Title: Future idea via backlog');
        expect($output)->toContain('Status: someday');

        // Verify it's in tasks with status=someday
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(1);
        expect($tasks->first()->title)->toBe('Future idea via backlog');
        expect($tasks->first()->status)->toBe(TaskStatus::Someday);
    });

    it('creates task from piped input without title argument', function (): void {
        $pipedContent = "Task title from pipe\nThis is the description from piped input";
        $fuelPath = realpath(__DIR__.'/../../../fuel');
        $command = sprintf('%s %s add --json --cwd=%s', escapeshellarg(PHP_BINARY), escapeshellarg($fuelPath), escapeshellarg($this->tempDir));

        $result = runCommandWithPipedInput($command, $pipedContent, $this->tempDir);
        $task = json_decode($result['stdout'], true);

        expect($result['exitCode'])->toBe(0);
        expect($task['title'])->toBe('Task title from pipe');
        expect($task['description'])->toBe('This is the description from piped input');
    });

    it('creates task from piped input with title argument', function (): void {
        $pipedContent = 'This entire content becomes the description';
        $fuelPath = realpath(__DIR__.'/../../../fuel');
        $command = sprintf('%s %s add "Title from argument" --json --cwd=%s', escapeshellarg(PHP_BINARY), escapeshellarg($fuelPath), escapeshellarg($this->tempDir));

        $result = runCommandWithPipedInput($command, $pipedContent, $this->tempDir);
        $task = json_decode($result['stdout'], true);

        expect($result['exitCode'])->toBe(0);
        expect($task['title'])->toBe('Title from argument');
        expect($task['description'])->toBe('This entire content becomes the description');
    });

    it('creates task from piped input with only title (no description)', function (): void {
        $pipedContent = 'Task title only';
        $fuelPath = realpath(__DIR__.'/../../../fuel');
        $command = sprintf('php %s add --json --cwd=%s', escapeshellarg($fuelPath), escapeshellarg($this->tempDir));

        $result = runCommandWithPipedInput($command, $pipedContent, $this->tempDir);
        $task = json_decode($result['stdout'], true);

        expect($result['exitCode'])->toBe(0);
        expect($task['title'])->toBe('Task title only');
        expect($task['description'])->toBeNull();
    });

    it('creates task from piped input with multi-line description', function (): void {
        $pipedContent = "Task Title\nLine 1 of description\nLine 2 of description\nLine 3 of description";
        $fuelPath = realpath(__DIR__.'/../../../fuel');
        $command = sprintf('php %s add --json --cwd=%s', escapeshellarg($fuelPath), escapeshellarg($this->tempDir));

        $result = runCommandWithPipedInput($command, $pipedContent, $this->tempDir);
        $task = json_decode($result['stdout'], true);

        expect($result['exitCode'])->toBe(0);
        expect($task['title'])->toBe('Task Title');
        expect($task['description'])->toBe("Line 1 of description\nLine 2 of description\nLine 3 of description");
    });

    it('creates task from piped input with other flags', function (): void {
        $pipedContent = "Piped Task Title\nPiped description";
        $fuelPath = realpath(__DIR__.'/../../../fuel');
        $command = sprintf(
            'php %s add --type=feature --priority=3 --labels=test,piped --complexity=moderate --json --cwd=%s',
            escapeshellarg($fuelPath),
            escapeshellarg($this->tempDir)
        );

        $result = runCommandWithPipedInput($command, $pipedContent, $this->tempDir);
        $task = json_decode($result['stdout'], true);

        expect($result['exitCode'])->toBe(0);
        expect($task['title'])->toBe('Piped Task Title');
        expect($task['description'])->toBe('Piped description');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(3);
        expect($task['labels'])->toBe(['test', 'piped']);
        expect($task['complexity'])->toBe('moderate');
    });
});
