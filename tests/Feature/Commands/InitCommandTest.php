<?php

use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses()->group('feature');
// =============================================================================
// init Command Tests
// =============================================================================

describe('init command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
        $this->dbPath = app(FuelContext::class)->getDatabasePath();
    });

    it('creates .fuel directory', function (): void {
        $fuelDir = $this->testDir.'/.fuel';

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
        expect($tasks->pluck('title')->filter(fn ($t): bool => str_contains($t, 'reality.md'))->count())->toBe(1);
    });

    it('does not create duplicate starter tasks when run multiple times', function (): void {
        // First init
        Artisan::call('init');
        $firstTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t): bool => str_contains($t, 'reality.md'))->count();

        // Second init
        Artisan::call('init');
        $secondTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t): bool => str_contains($t, 'reality.md'))->count();

        // Should have same number of starter tasks
        expect($secondTaskCount)->toBe($firstTaskCount);
        expect($firstTaskCount)->toBe(1);
    });

    it('creates AGENTS.md with fuel guidelines', function (): void {
        $agentsMdPath = $this->testDir.'/AGENTS.md';

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

    it('fails with helpful message when orphaned WAL files exist', function (): void {
        $tempDir = sys_get_temp_dir().'/fuel-wal-test-'.uniqid();
        mkdir($tempDir.'/.fuel', 0755, true);

        $dbPath = $tempDir.'/.fuel/agent.db';

        // Create orphaned WAL file without main database
        file_put_contents($dbPath.'-wal', 'orphaned');

        $context = new FuelContext($tempDir.'/.fuel');

        expect(fn () => $context->configureDatabase())
            ->toThrow(RuntimeException::class, 'Orphaned SQLite WAL files');

        // Cleanup
        @unlink($dbPath.'-wal');
        @rmdir($tempDir.'/.fuel');
        @rmdir($tempDir);
    });

    it('creates prompt files during init', function (): void {
        $promptsDir = $this->testDir.'/.fuel/prompts';

        // Ensure prompts directory doesn't exist
        if (is_dir($promptsDir)) {
            File::deleteDirectory($promptsDir);
        }

        $this->artisan('init')
            ->expectsOutputToContain('Wrote 5 new prompts')
            ->assertExitCode(0);

        expect(is_dir($promptsDir))->toBeTrue();
        expect(file_exists($promptsDir.'/work.md'))->toBeTrue();
        expect(file_exists($promptsDir.'/review.md'))->toBeTrue();
        expect(file_exists($promptsDir.'/verify.md'))->toBeTrue();
        expect(file_exists($promptsDir.'/reality.md'))->toBeTrue();
        expect(file_exists($promptsDir.'/selfguided.md'))->toBeTrue();
    });

    it('detects outdated prompts during init', function (): void {
        $promptsDir = $this->testDir.'/.fuel/prompts';
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Write an outdated prompt (version 0)
        file_put_contents($promptsDir.'/work.md', 'Old prompt without version tag');

        $this->artisan('init')
            ->expectsOutputToContain('outdated')
            ->assertExitCode(0);

        // Verify .new file was created
        expect(file_exists($promptsDir.'/work.md.new'))->toBeTrue();

        // Verify .new file has version tag
        $newContent = file_get_contents($promptsDir.'/work.md.new');
        expect($newContent)->toContain('<fuel-prompt version="1" />');
    });

    it('does not overwrite existing prompts during init', function (): void {
        $promptsDir = $this->testDir.'/.fuel/prompts';
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Write a custom prompt with current version
        $customContent = "<fuel-prompt version=\"1\" />\n\nMy custom work prompt";
        file_put_contents($promptsDir.'/work.md', $customContent);

        $this->artisan('init')
            ->assertExitCode(0);

        // Custom prompt should not be overwritten
        expect(file_get_contents($promptsDir.'/work.md'))->toBe($customContent);
    });
});
