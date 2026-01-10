<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-epic-cmd-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn () => $context);

    $this->db = new DatabaseService($context->getDatabasePath());
    $this->taskService = new TaskService($this->db);
    $this->epicService = new EpicService($this->db, $this->taskService);

    $this->app->singleton(TaskService::class, fn (): TaskService => $this->taskService);
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $this->db);
    $this->app->singleton(EpicService::class, fn (): EpicService => $this->epicService);
});

afterEach(function (): void {
    $deleteDir = function (string $dir) use (&$deleteDir): void {
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
                @unlink($path);
            }
        }

        @rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('epic:add command', function (): void {
    it('creates an epic via CLI', function (): void {
        $this->artisan('epic:add', ['title' => 'My test epic', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Created epic: e-')
            ->assertExitCode(0);
    });

    it('creates epic with title only', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Title Only Epic',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['title'])->toBe('Title Only Epic');
        expect($epic['description'])->toBeNull();
        expect($epic['id'])->toStartWith('e-');
        expect($epic['status'])->toBe('planning');
    });

    it('creates epic with description', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic with description',
            '--description' => 'This is the epic description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['title'])->toBe('Epic with description');
        expect($epic['description'])->toBe('This is the epic description');
    });

    it('outputs JSON when --json flag is used', function (): void {
        Artisan::call('epic:add', [
            'title' => 'JSON epic',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "planning"');
        expect($output)->toContain('"title": "JSON epic"');
        expect($output)->toContain('"id": "e-');
    });

    it('generates unique epic IDs', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic 1',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $epic1 = json_decode(Artisan::output(), true);

        Artisan::call('epic:add', [
            'title' => 'Epic 2',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $epic2 = json_decode(Artisan::output(), true);

        expect($epic1['id'])->not->toBe($epic2['id']);
        expect($epic1['id'])->toMatch('/^e-[a-f0-9]{6}$/');
        expect($epic2['id'])->toMatch('/^e-[a-f0-9]{6}$/');
    });
});

describe('add command with --epic flag', function (): void {
    it('creates task linked to epic', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Task for epic',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['epic_id'])->toBe($epic['id']);
    });

    it('fails when epic does not exist', function (): void {
        $this->db->initialize();

        $this->artisan('add', [
            'title' => 'Task for missing epic',
            '--epic' => 'e-000000',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Epic 'e-000000' not found")
            ->assertExitCode(1);
    });

    it('supports partial epic ID matching', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');
        $partialId = substr($epic['id'], 2, 4);

        Artisan::call('add', [
            'title' => 'Task with partial epic ID',
            '--epic' => $partialId,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['epic_id'])->toBe($epic['id']);
    });

    it('task is returned by getTasksForEpic', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Epic task 1',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);

        Artisan::call('add', [
            'title' => 'Epic task 2',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);

        $tasks = $this->epicService->getTasksForEpic($epic['id']);

        expect($tasks)->toHaveCount(2);
        $titles = array_column($tasks, 'title');
        expect($titles)->toContain('Epic task 1');
        expect($titles)->toContain('Epic task 2');
    });
});

describe('epic status derivation via commands', function (): void {
    it('epic status is planning when no tasks', function (): void {
        $epic = $this->epicService->createEpic('Empty Epic');

        $fetchedEpic = $this->epicService->getEpic($epic['id']);

        expect($fetchedEpic['status'])->toBe('planning');
    });

    it('epic status is in_progress when task is open', function (): void {
        $epic = $this->epicService->createEpic('Epic with task');

        Artisan::call('add', [
            'title' => 'Open task',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);

        $fetchedEpic = $this->epicService->getEpic($epic['id']);

        expect($fetchedEpic['status'])->toBe('in_progress');
    });

    it('epic status is in_progress when task is in_progress', function (): void {
        $epic = $this->epicService->createEpic('Epic with active task');

        Artisan::call('add', [
            'title' => 'Active task',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $task = json_decode(Artisan::output(), true);

        $this->artisan('start', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ])->assertExitCode(0);

        $fetchedEpic = $this->epicService->getEpic($epic['id']);

        expect($fetchedEpic['status'])->toBe('in_progress');
    });

    it('epic status is review_pending when all tasks are closed', function (): void {
        $epic = $this->epicService->createEpic('Completed Epic');

        Artisan::call('add', [
            'title' => 'Task to complete',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $task = json_decode(Artisan::output(), true);

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
        ])->assertExitCode(0);

        $fetchedEpic = $this->epicService->getEpic($epic['id']);

        expect($fetchedEpic['status'])->toBe('review_pending');
    });

    it('epic status transitions correctly through task lifecycle', function (): void {
        $epic = $this->epicService->createEpic('Lifecycle Epic');

        expect($this->epicService->getEpic($epic['id'])['status'])->toBe('planning');

        Artisan::call('add', [
            'title' => 'Lifecycle task',
            '--epic' => $epic['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $task = json_decode(Artisan::output(), true);

        expect($this->epicService->getEpic($epic['id'])['status'])->toBe('in_progress');

        $this->artisan('start', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ])->assertExitCode(0);

        expect($this->epicService->getEpic($epic['id'])['status'])->toBe('in_progress');

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
        ])->assertExitCode(0);

        expect($this->epicService->getEpic($epic['id'])['status'])->toBe('review_pending');
    });
});
