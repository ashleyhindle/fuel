<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

describe('init command', function (): void {
    it('creates .fuel/ directory', function (): void {
        Artisan::call('init', []);

        expect(is_dir($this->testDir.'/.fuel'))->toBeTrue();
    });

    it('creates processes directory', function (): void {
        Artisan::call('init', []);

        expect(is_dir($this->testDir.'/.fuel/processes'))->toBeTrue();
    });

    it('creates plans directory', function (): void {
        Artisan::call('init', []);

        expect(is_dir($this->testDir.'/.fuel/plans'))->toBeTrue();
    });

    it('creates agent.db with all required tables', function (): void {
        Artisan::call('init', []);

        $dbPath = $this->testDir.'/.fuel/agent.db';
        expect(file_exists($dbPath))->toBeTrue();

        $requiredTables = ['tasks', 'epics', 'runs', 'reviews', 'agent_health', 'migrations'];
        foreach ($requiredTables as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });

    it('creates starter task', function (): void {
        Artisan::call('init', []);

        // Use TaskService directly to verify (path resolution now happens at boot).
        $context = new FuelContext($this->testDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(1);
        expect($tasks->first()->title)->toContain('reality.md');
        expect($tasks->first()->short_id)->toStartWith('f-');
    });

    it('is idempotent - can be run multiple times safely', function (): void {
        Artisan::call('init', []);
        $secondExitCode = Artisan::call('init', []);

        expect($secondExitCode)->toBe(0);

        // Use TaskService directly to verify (path resolution now happens at boot).
        $context = new FuelContext($this->testDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(1);
    });

    it('adds selective fuel entries to .gitignore', function (): void {
        Artisan::call('init', []);

        $gitignorePath = $this->testDir.'/.gitignore';
        expect(file_exists($gitignorePath))->toBeTrue();

        $content = file_get_contents($gitignorePath);
        // Uses wildcard with explicit allows for tracked files
        expect($content)->toContain('.fuel/*');
        expect($content)->toContain('!.fuel/reality.md');
        expect($content)->toContain('!.fuel/plans/');
        expect($content)->toContain('!.fuel/prompts/');
        expect($content)->toContain('.fuel/prompts/*.new');
    });

    it('creates new .gitignore if it does not exist', function (): void {
        Artisan::call('init', []);

        $gitignorePath = $this->testDir.'/.gitignore';
        expect(file_exists($gitignorePath))->toBeTrue();

        $content = file_get_contents($gitignorePath);
        expect($content)->toContain('.fuel/*');
        expect($content)->toContain('!.fuel/reality.md');
        expect($content)->toContain('!.fuel/plans/');
        expect($content)->toContain('!.fuel/prompts/');
    });

    it('appends fuel entries to existing .gitignore without duplicating', function (): void {
        file_put_contents($this->testDir.'/.gitignore', "node_modules\nvendor\n");

        Artisan::call('init', []);

        $content = file_get_contents($this->testDir.'/.gitignore');
        expect($content)->toContain('node_modules');
        expect($content)->toContain('vendor');
        expect($content)->toContain('.fuel/*');
        expect($content)->toContain('!.fuel/reality.md');

        // Run init again - should not duplicate entries
        Artisan::call('init', []);
        $content2 = file_get_contents($this->testDir.'/.gitignore');
        expect(substr_count($content2, '.fuel/*'))->toBe(1);
    });

    it('updates AGENTS.md with guidelines', function (): void {
        Artisan::call('init', []);

        $agentsMdPath = $this->testDir.'/AGENTS.md';
        expect(file_exists($agentsMdPath))->toBeTrue();

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('Fuel');
    });

    it('creates config.yaml with default configuration', function (): void {
        Artisan::call('init', []);

        $configPath = $this->testDir.'/.fuel/config.yaml';
        expect(file_exists($configPath))->toBeTrue();

        $content = file_get_contents($configPath);
        expect($content)->toContain('primary:');
        expect($content)->toContain('complexity:');
    });

    it('creates default config.yaml when --agent flag is provided', function (): void {
        Artisan::call('init', [
            '--agent' => 'claude-opus',
        ]);

        $configPath = $this->testDir.'/.fuel/config.yaml';
        expect(file_exists($configPath))->toBeTrue();

        $content = file_get_contents($configPath);
        expect($content)->toContain('primary:');
        expect($content)->toContain('complexity:');
        expect($content)->toContain('agents:');
    });

    it('does not create starter task if tasks already exist', function (): void {
        Artisan::call('init', []);

        // Use TaskService directly to add a task (path resolution now happens at boot).
        $context = new FuelContext($this->testDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $taskService->create(['title' => 'Existing task']);

        Artisan::call('init', []);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(2);
    });

    it('regression: Schema::hasTable returns true for all tables after init', function (): void {
        Artisan::call('init', []);

        $tables = ['tasks', 'epics', 'runs', 'reviews', 'agent_health'];
        foreach ($tables as $table) {
            expect(Schema::hasTable($table))->toBeTrue(sprintf('Table %s should exist', $table));
        }
    });

    it('shows "run your favourite agent" message only on fresh install', function (): void {
        $this->artisan('init', [])
            ->expectsOutput("Configure '.fuel/config.yaml' then run 'fuel consume'")
            ->assertExitCode(0);
    });

    it('does not show "run your favourite agent" message when tasks already exist', function (): void {
        Artisan::call('init', []);

        // Use TaskService directly to add a task (path resolution now happens at boot).
        $context = new FuelContext($this->testDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        // Add an existing task
        $taskService->create(['title' => 'Existing task']);

        // Run init again - should not show the message
        $this->artisan('init', [])
            ->doesntExpectOutput("Configure '.fuel/config.yaml' then run 'fuel consume'")
            ->assertExitCode(0);
    });

    it('creates stub reality.md', function (): void {
        Artisan::call('init', []);

        $realityPath = $this->testDir.'/.fuel/reality.md';
        expect(file_exists($realityPath))->toBeTrue();

        $content = file_get_contents($realityPath);
        expect($content)->toContain('# Reality');
        expect($content)->toContain('## Architecture');
        expect($content)->toContain('## Modules');
        expect($content)->toContain('| Module | Purpose | Entry Point |');
        expect($content)->toContain('## Entry Points');
        expect($content)->toContain('## Patterns');
        expect($content)->toContain('## Recent Changes');
        expect($content)->toContain('_Last updated: never_');
    });
});
