<?php

declare(strict_types=1);

use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    // Bind our test service instances
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));
    $this->app->singleton(EpicService::class, fn (): EpicService => new EpicService(
        $this->app->make(DatabaseService::class),
        $this->app->make(TaskService::class)
    ));

    $this->databaseService = $this->app->make(DatabaseService::class);
    $this->databaseService->initialize();
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

describe('epic:add command', function (): void {
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

    it('creates an epic via CLI', function (): void {
        $this->artisan('epic:add', ['title' => 'My test epic', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Created epic: e-')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        Artisan::call('epic:add', ['title' => 'JSON epic', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "planning"');
        expect($output)->toContain('"title": "JSON epic"');
        expect($output)->toContain('"id": "e-');
    });

    it('creates epic with --description flag', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic with description',
            '--description' => 'This is a detailed description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['description'])->toBe('This is a detailed description');
        expect($epic['title'])->toBe('Epic with description');
        expect($epic['status'])->toBe('planning');
        expect($epic['id'])->toStartWith('e-');
    });

    it('creates epic without description', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic without description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['description'])->toBeNull();
        expect($epic['title'])->toBe('Epic without description');
        expect($epic['status'])->toBe('planning');
    });

    it('outputs epic ID in non-JSON mode', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Test Epic',
            '--description' => 'Test description',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created epic: e-');
        expect($output)->toContain('Title: Test Epic');
        expect($output)->toContain('Description: Test description');
    });

    it('does not output description line when description is null', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Test Epic',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created epic: e-');
        expect($output)->toContain('Title: Test Epic');
        expect($output)->not->toContain('Description:');
    });
});
