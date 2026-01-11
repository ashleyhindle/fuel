<?php

use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Add Command Tests
describe('add command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

        $this->app->singleton(BacklogService::class, fn (): BacklogService => new BacklogService($context));

        $this->taskService = $this->app->make(TaskService::class);
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
        $this->artisan('add', ['title' => 'My test task', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Created task: f-')
            ->assertExitCode(0);

        expect(file_exists($this->dbPath))->toBeTrue();
    });

    it('outputs JSON when --json flag is used', function (): void {
        Artisan::call('add', ['title' => 'JSON task', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "open"');
        expect($output)->toContain('"title": "JSON task"');
        expect($output)->toContain('"id": "f-');
    });

    it('creates task in custom cwd', function (): void {
        $this->artisan('add', ['title' => 'Custom path task', '--cwd' => $this->tempDir])
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
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['description'])->toBe('Short description');
    });

    it('creates task with --type flag', function (): void {
        Artisan::call('add', [
            'title' => 'Bug fix',
            '--type' => 'bug',
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task type')
            ->assertExitCode(1);
    });

    it('creates task with --priority flag', function (): void {
        Artisan::call('add', [
            'title' => 'High priority task',
            '--priority' => '4',
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('validates --priority flag is integer', function (): void {
        $this->artisan('add', [
            'title' => 'Invalid priority',
            '--priority' => 'high',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('creates task with --labels flag', function (): void {
        Artisan::call('add', [
            'title' => 'Labeled task',
            '--labels' => 'frontend,backend,urgent',
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['complexity'])->toBe('complex');
    });

    it('defaults complexity to simple when not specified', function (): void {
        Artisan::call('add', [
            'title' => 'Task without complexity',
            '--cwd' => $this->tempDir,
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
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task complexity')
            ->assertExitCode(1);
    });

    it('creates task with --blocked-by flag (single blocker)', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(1);
        expect($task['blocked_by'])->toContain($blocker['id']);
    });

    it('creates task with --blocked-by flag (multiple blockers)', function (): void {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
        $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker1['id'].','.$blocker2['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(2);
        expect($task['blocked_by'])->toContain($blocker1['id']);
        expect($task['blocked_by'])->toContain($blocker2['id']);
    });

    it('creates task with --blocked-by flag (with spaces)', function (): void {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
        $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker1['id'].', '.$blocker2['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(2);
        expect($task['blocked_by'])->toContain($blocker1['id']);
        expect($task['blocked_by'])->toContain($blocker2['id']);
    });

    it('displays blocked-by info in non-JSON output', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker['id'],
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-');
        expect($output)->toContain('Blocked by:');
        expect($output)->toContain($blocker['id']);
    });

    it('creates task with --blocked-by and other flags', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Complete blocked task',
            '--description' => 'Blocked feature',
            '--type' => 'feature',
            '--priority' => '2',
            '--labels' => 'backend',
            '--blocked-by' => $blocker['id'],
            '--cwd' => $this->tempDir,
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
        expect($task['blocked_by'])->toContain($blocker['id']);
    });

    it('supports partial IDs in --blocked-by flag', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $partialId = substr((string) $blocker['id'], 2, 3); // Just hash part

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $partialId,
            '--cwd' => $this->tempDir,
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
        $databaseService->initialize();

        $epicService = new EpicService($databaseService);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Task with epic',
            '--epic' => $epic->id,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['epic_id'])->toBe($epic->id);
    });

    it('creates task with -e flag (epic shortcut)', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);
        $databaseService->initialize();

        $epicService = new EpicService($databaseService);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Task with epic shortcut',
            '-e' => $epic->id,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['epic_id'])->toBe($epic->id);
    });

    it('validates epic exists when using --epic flag', function (): void {
        $this->artisan('add', [
            'title' => 'Task with invalid epic',
            '--epic' => 'e-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Epic 'e-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('creates task with --epic and other flags', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);
        $databaseService->initialize();

        $epicService = new EpicService($databaseService);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Complete task with epic',
            '--description' => 'Epic feature',
            '--type' => 'feature',
            '--priority' => '2',
            '--labels' => 'backend',
            '--epic' => $epic->id,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete task with epic');
        expect($task['description'])->toBe('Epic feature');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(2);
        expect($task['labels'])->toBe(['backend']);
        expect($task['epic_id'])->toBe($epic->id);
    });

    it('supports partial epic IDs in --epic flag', function (): void {
        $databaseService = $this->app->make(DatabaseService::class);
        $databaseService->initialize();

        $epicService = new EpicService($databaseService);
        $epic = $epicService->createEpic('Test Epic');
        $partialId = substr($epic->id, 2, 3); // Just hash part

        Artisan::call('add', [
            'title' => 'Task with partial epic ID',
            '--epic' => $partialId,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['epic_id'])->toBe($epic->id);
    });

    it('adds item to backlog with --someday flag', function (): void {
        $backlogService = $this->app->make(BacklogService::class);

        Artisan::call('add', [
            'title' => 'Future idea',
            '--someday' => true,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Added to backlog: b-');
        expect($output)->toContain('Title: Future idea');

        // Verify it's in backlog, not tasks
        $backlogService->initialize();
        $all = $backlogService->all();
        expect($all->count())->toBe(1);
        expect($all->first()['title'])->toBe('Future idea');

        // Verify it's NOT in tasks
        $this->taskService->initialize();
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(0);
    });

    it('adds item to backlog with --someday and --description flags', function (): void {
        $backlogService = $this->app->make(BacklogService::class);

        Artisan::call('add', [
            'title' => 'Future enhancement',
            '--description' => 'This is a future idea',
            '--someday' => true,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $item = json_decode($output, true);

        expect($item['id'])->toStartWith('b-');
        expect($item['title'])->toBe('Future enhancement');
        expect($item['description'])->toBe('This is a future idea');
        expect($item)->not->toHaveKey('status');
        expect($item)->not->toHaveKey('priority');
        expect($item)->not->toHaveKey('type');
    });

    it('ignores task-specific flags when --someday is used', function (): void {
        $backlogService = $this->app->make(BacklogService::class);

        Artisan::call('add', [
            'title' => 'Backlog item',
            '--someday' => true,
            '--priority' => '4',
            '--type' => 'feature',
            '--labels' => 'urgent',
            '--complexity' => 'complex',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $item = json_decode($output, true);

        // Backlog items should only have id, title, description, created_at
        expect($item['id'])->toStartWith('b-');
        expect($item['title'])->toBe('Backlog item');
        expect($item)->not->toHaveKey('priority');
        expect($item)->not->toHaveKey('type');
        expect($item)->not->toHaveKey('labels');
        expect($item)->not->toHaveKey('complexity');
        expect($item)->not->toHaveKey('status');
    });

    it('outputs JSON when --json flag is used with --someday', function (): void {
        Artisan::call('add', [
            'title' => 'JSON backlog item',
            '--someday' => true,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $item = json_decode($output, true);

        expect($item)->toBeArray();
        expect($item['id'])->toStartWith('b-');
        expect($item['title'])->toBe('JSON backlog item');
    });

    it('adds item to backlog with --backlog flag (alias for --someday)', function (): void {
        $backlogService = $this->app->make(BacklogService::class);

        Artisan::call('add', [
            'title' => 'Future idea via backlog',
            '--backlog' => true,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Added to backlog: b-');
        expect($output)->toContain('Title: Future idea via backlog');

        // Verify it's in backlog, not tasks
        $backlogService->initialize();
        $all = $backlogService->all();
        expect($all->count())->toBe(1);
        expect($all->first()['title'])->toBe('Future idea via backlog');

        // Verify it's NOT in tasks
        $this->taskService->initialize();
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(0);
    });
});
