<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('feature');

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// =============================================================================
// init Command Tests
// =============================================================================

describe('init command', function (): void {
    it('creates .fuel directory', function (): void {
        $fuelDir = $this->tempDir.'/.fuel';

        // Ensure it doesn't exist first
        if (is_dir($fuelDir)) {
            rmdir($fuelDir);
        }

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(is_dir($fuelDir))->toBeTrue();
    });

    it('creates agent.db database file', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(file_exists($this->dbPath))->toBeTrue();
    });

    it('creates a starter task', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        // Verify database was created with a starter task
        expect(file_exists($this->dbPath))->toBeTrue();
        $tasks = $this->taskService->all();
        expect($tasks->pluck('title')->filter(fn ($t) => str_contains($t, 'README'))->count())->toBe(1);
    });

    it('does not create duplicate starter tasks when run multiple times', function (): void {
        // First init
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $firstTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t) => str_contains($t, 'README'))->count();

        // Second init
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $secondTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t) => str_contains($t, 'README'))->count();

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

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(file_exists($agentsMdPath))->toBeTrue();
        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });
});
