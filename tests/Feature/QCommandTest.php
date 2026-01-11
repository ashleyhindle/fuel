<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

uses()->group('commands');

describe('q command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new App\Services\FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(App\Services\FuelContext::class, fn () => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new App\Services\DatabaseService($context->getDatabasePath());
        $this->app->singleton(App\Services\DatabaseService::class, fn () => $databaseService);

        $this->app->singleton(App\Services\TaskService::class, fn (): App\Services\TaskService => new App\Services\TaskService($databaseService));

        $this->app->singleton(App\Services\RunService::class, fn (): App\Services\RunService => new App\Services\RunService($databaseService));

        $this->app->singleton(App\Services\BacklogService::class, fn (): App\Services\BacklogService => new App\Services\BacklogService($context));

        $this->taskService = $this->app->make(App\Services\TaskService::class);
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
                } else {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

    it('creates task and outputs only the ID', function (): void {
        $this->taskService->initialize();

        Artisan::call('q', ['title' => 'Quick task', '--cwd' => $this->tempDir]);
        $output = trim(Artisan::output());

        expect($output)->toStartWith('f-');
        expect(strlen($output))->toBe(8); // f- + 6 chars

        // Verify task was actually created
        $task = $this->taskService->find($output);
        expect($task)->not->toBeNull();
        expect($task['title'])->toBe('Quick task');
    });

    it('returns exit code 0 on success', function (): void {
        $this->taskService->initialize();

        $this->artisan('q', ['title' => 'Quick task', '--cwd' => $this->tempDir])
            ->assertExitCode(0);
    });

    it('handles RuntimeException from TaskService::create()', function (): void {
        // Create a mock TaskService that throws RuntimeException
        $mockTaskService = \Mockery::mock(TaskService::class);
        $mockTaskService->shouldReceive('initialize')->once();
        $mockTaskService->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('Failed to create task'));

        // Bind the mock to the service container
        $this->app->singleton(TaskService::class, fn () => $mockTaskService);

        $exitCode = Artisan::call('q', ['title' => 'Test task', '--cwd' => $this->tempDir]);
        $output = trim(Artisan::output());

        expect($output)->toContain('Failed to create task');
        expect($exitCode)->toBe(Command::FAILURE);
    });
});
