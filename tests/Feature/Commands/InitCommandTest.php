<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses()->group('feature');
// =============================================================================
// init Command Tests
// =============================================================================

describe('init command', function (): void {
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

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService());

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

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

    it('creates .fuel directory', function (): void {
        $fuelDir = $this->tempDir.'/.fuel';

        // Ensure it doesn't exist first (use recursive delete)
        if (is_dir($fuelDir)) {
            File::deleteDirectory($fuelDir);
        }

        Artisan::call('init');

        expect(is_dir($fuelDir))->toBeTrue();
    });

    it('creates agent.db database file', function (): void {
        Artisan::call('init');

        expect(file_exists($this->dbPath))->toBeTrue();
    });

    it('creates a starter task', function (): void {
        Artisan::call('init');

        // Verify database was created with a starter task
        expect(file_exists($this->dbPath))->toBeTrue();
        $tasks = $this->taskService->all();
        expect($tasks->pluck('title')->filter(fn ($t): bool => str_contains($t, 'README'))->count())->toBe(1);
    });

    it('does not create duplicate starter tasks when run multiple times', function (): void {
        // First init
        Artisan::call('init');
        $firstTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t): bool => str_contains($t, 'README'))->count();

        // Second init
        Artisan::call('init');
        $secondTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t): bool => str_contains($t, 'README'))->count();

        // Should have same number of starter tasks
        expect($secondTaskCount)->toBe($firstTaskCount);
        expect($firstTaskCount)->toBe(1);
    });

    it('creates AGENTS.md with fuel guidelines', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';

        // Remove if exists
        if (file_exists($agentsMdPath)) {
            unlink($agentsMdPath);
        }

        Artisan::call('init');

        expect(file_exists($agentsMdPath))->toBeTrue();
        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });
});
