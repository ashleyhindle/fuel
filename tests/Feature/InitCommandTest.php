<?php

declare(strict_types=1);

use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-init-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Bind FuelContext to the test's temp directory so init operates there
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->forgetInstance(FuelContext::class);
    $this->app->instance(FuelContext::class, $context);

    // Rebind ConfigService to use the new FuelContext
    $this->app->forgetInstance(ConfigService::class);
    $this->app->singleton(ConfigService::class, fn (): ConfigService => new ConfigService($context));
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
                @unlink($path);
            }
        }

        @rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('init command', function (): void {
    it('creates .fuel/ directory', function (): void {
        Artisan::call('init', []);

        expect(is_dir($this->tempDir.'/.fuel'))->toBeTrue();
    });

    it('creates processes directory', function (): void {
        Artisan::call('init', []);

        expect(is_dir($this->tempDir.'/.fuel/processes'))->toBeTrue();
    });

    it('creates plans directory', function (): void {
        Artisan::call('init', []);

        expect(is_dir($this->tempDir.'/.fuel/plans'))->toBeTrue();
    });

    it('creates agent.db with all required tables', function (): void {
        Artisan::call('init', []);

        $dbPath = $this->tempDir.'/.fuel/agent.db';
        expect(file_exists($dbPath))->toBeTrue();

        $requiredTables = ['tasks', 'epics', 'runs', 'reviews', 'agent_health', 'migrations'];
        foreach ($requiredTables as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });

    it('creates starter task', function (): void {
        Artisan::call('init', []);

        // Use TaskService directly to verify (path resolution now happens at boot).
        $context = new FuelContext($this->tempDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(1);
        expect($tasks->first()->title)->toContain('Update README to ');
        expect($tasks->first()->short_id)->toStartWith('f-');
    });

    it('is idempotent - can be run multiple times safely', function (): void {
        Artisan::call('init', []);
        $secondExitCode = Artisan::call('init', []);

        expect($secondExitCode)->toBe(0);

        // Use TaskService directly to verify (path resolution now happens at boot).
        $context = new FuelContext($this->tempDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(1);
    });

    it('adds selective fuel entries to .gitignore', function (): void {
        Artisan::call('init', []);

        $gitignorePath = $this->tempDir.'/.gitignore';
        expect(file_exists($gitignorePath))->toBeTrue();

        $content = file_get_contents($gitignorePath);
        expect($content)->toContain('.fuel/*.lock');
        expect($content)->toContain('.fuel/agent.db');
        expect($content)->toContain('.fuel/config.yaml');
        expect($content)->toContain('.fuel/processes/');
        expect($content)->toContain('.fuel/runs/');
        // plans/ should NOT be ignored (committed to git)
        expect($content)->not->toContain('.fuel/plans/');
    });

    it('creates new .gitignore if it does not exist', function (): void {
        Artisan::call('init', []);

        $gitignorePath = $this->tempDir.'/.gitignore';
        expect(file_exists($gitignorePath))->toBeTrue();

        $content = file_get_contents($gitignorePath);
        expect($content)->toContain('.fuel/*.lock');
        expect($content)->toContain('.fuel/agent.db');
        expect($content)->toContain('.fuel/processes/');
        expect($content)->toContain('.fuel/runs/');
    });

    it('appends fuel entries to existing .gitignore without duplicating', function (): void {
        file_put_contents($this->tempDir.'/.gitignore', "node_modules\nvendor\n");

        Artisan::call('init', []);

        $content = file_get_contents($this->tempDir.'/.gitignore');
        expect($content)->toContain('node_modules');
        expect($content)->toContain('vendor');
        expect($content)->toContain('.fuel/*.lock');
        expect($content)->toContain('.fuel/agent.db');

        // Run init again - should not duplicate entries
        Artisan::call('init', []);
        $content2 = file_get_contents($this->tempDir.'/.gitignore');
        expect(substr_count($content2, '.fuel/*.lock'))->toBe(1);
    });

    it('updates AGENTS.md with guidelines', function (): void {
        Artisan::call('init', []);

        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        expect(file_exists($agentsMdPath))->toBeTrue();

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('Fuel');
    });

    it('creates config.yaml with default configuration', function (): void {
        Artisan::call('init', []);

        $configPath = $this->tempDir.'/.fuel/config.yaml';
        expect(file_exists($configPath))->toBeTrue();

        $content = file_get_contents($configPath);
        expect($content)->toContain('primary:');
        expect($content)->toContain('complexity:');
    });

    it('creates default config.yaml when --agent flag is provided', function (): void {
        Artisan::call('init', [
            '--agent' => 'claude-opus',
        ]);

        $configPath = $this->tempDir.'/.fuel/config.yaml';
        expect(file_exists($configPath))->toBeTrue();

        $content = file_get_contents($configPath);
        expect($content)->toContain('primary:');
        expect($content)->toContain('complexity:');
        expect($content)->toContain('agents:');
    });

    it('does not create starter task if tasks already exist', function (): void {
        Artisan::call('init', []);

        // Use TaskService directly to add a task (path resolution now happens at boot).
        $context = new FuelContext($this->tempDir.'/.fuel');
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
            ->expectsOutput('Run your favourite agent and ask it to "Consume the fuel"')
            ->assertExitCode(0);
    });

    it('does not show "run your favourite agent" message when tasks already exist', function (): void {
        Artisan::call('init', []);

        // Use TaskService directly to add a task (path resolution now happens at boot).
        $context = new FuelContext($this->tempDir.'/.fuel');
        $context->configureDatabase();

        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        // Add an existing task
        $taskService->create(['title' => 'Existing task']);

        // Run init again - should not show the message
        $this->artisan('init', [])
            ->doesntExpectOutput('Run your favourite agent and ask it to "Consume the fuel"')
            ->assertExitCode(0);
    });
});
