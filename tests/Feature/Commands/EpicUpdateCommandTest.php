<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('epic:update command', function (): void {
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
        $this->epicService = app(EpicService::class);
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

    it('updates epic title', function (): void {
        $epic = $this->epicService->createEpic('Original Title');

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--title' => 'Updated Title',
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->title)->toBe('Updated Title');
    });

    it('updates epic description', function (): void {
        $epic = $this->epicService->createEpic('Test Epic', 'Original description');

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--description' => 'Updated description',
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->description)->toBe('Updated description');
    });

    it('enables selfguided flag', function (): void {
        $epic = $this->epicService->createEpic('Test Epic', null, false);
        expect($epic->self_guided)->toBeFalse();

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->self_guided)->toBeTrue();
    });

    it('disables selfguided flag', function (): void {
        $epic = $this->epicService->createEpic('Test Epic', null, true);
        expect($epic->self_guided)->toBeTrue();

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--no-selfguided' => true,
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->self_guided)->toBeFalse();
    });

    it('outputs updated epic in non-JSON mode', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--title' => 'New Title',
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Updated epic: '.$epic->short_id);
        expect($output)->toContain('Title: New Title');
    });

    it('outputs updated epic with selfguided status in non-JSON mode', function (): void {
        $epic = $this->epicService->createEpic('Test Epic', null, false);

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Updated epic: '.$epic->short_id);
        expect($output)->toContain('Self-guided: enabled');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--title' => 'Updated via JSON',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['title'])->toBe('Updated via JSON');
        expect($result['short_id'])->toBe($epic->short_id);
    });

    it('returns error when no update fields provided', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('No update fields provided');
    });

    it('returns error when epic not found', function (): void {
        Artisan::call('epic:update', [
            'id' => 'e-nonexistent',
            '--title' => 'New Title',
        ]);
        $output = Artisan::output();

        expect($output)->toContain('not found');
    });

    it('supports partial ID matching', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');
        $partialId = substr((string) $epic->short_id, 0, 5);

        Artisan::call('epic:update', [
            'id' => $partialId,
            '--title' => 'Updated via Partial',
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->title)->toBe('Updated via Partial');
    });

    it('returns error when both selfguided flags are used', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
            '--no-selfguided' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Cannot use both --selfguided and --no-selfguided');
    });

    it('selfguided flag is idempotent when enabling', function (): void {
        $epic = $this->epicService->createEpic('Test Epic', null, true);
        expect($epic->self_guided)->toBeTrue();

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->self_guided)->toBeTrue();
    });

    it('no-selfguided flag is idempotent when disabling', function (): void {
        $epic = $this->epicService->createEpic('Test Epic', null, false);
        expect($epic->self_guided)->toBeFalse();

        Artisan::call('epic:update', [
            'id' => $epic->short_id,
            '--no-selfguided' => true,
        ]);

        $updatedEpic = $this->epicService->getEpic($epic->short_id);
        expect($updatedEpic->self_guided)->toBeFalse();
    });
});
