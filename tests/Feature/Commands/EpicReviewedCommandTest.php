<?php

declare(strict_types=1);

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
    $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));
    $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService(
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

describe('epic:reviewed command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

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

    it('marks an epic as reviewed', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:reviewed', ['id' => $epic->id, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s marked as reviewed', $epic->id));

        // Verify the epic was actually marked as reviewed
        $updatedEpic = $epicService->getEpic($epic->id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:reviewed', ['id' => 'e-nonexistent', '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epic:reviewed', ['id' => $epic->id, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['id'])->toBe($epic->id);
        expect($data['title'])->toBe('JSON Epic');
        expect($data['reviewed_at'])->not->toBeNull();
        expect($data['reviewed_at'])->toBeString();
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        // Use partial ID (without e- prefix)
        $partialId = substr((string) $epic->id, 2);

        Artisan::call('epic:reviewed', ['id' => $partialId, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s marked as reviewed', $epic->id));

        // Verify the epic was actually marked as reviewed
        $updatedEpic = $epicService->getEpic($epic->id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();
    });

    it('updates reviewed_at timestamp', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Timestamp Test Epic');

        // Initially reviewed_at should be null
        $initialEpic = $epicService->getEpic($epic->id);
        expect($initialEpic->reviewed_at)->toBeNull();

        // Mark as reviewed
        Artisan::call('epic:reviewed', ['id' => $epic->id, '--cwd' => $this->tempDir]);

        // Verify reviewed_at is now set
        $updatedEpic = $epicService->getEpic($epic->id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();
        expect($updatedEpic->reviewed_at)->toBeString();
    });
});
