<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-init-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
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

describe('init command', function (): void {
    it('creates .fuel/ directory', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(is_dir($this->tempDir.'/.fuel'))->toBeTrue();
    });

    it('creates processes directory', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(is_dir($this->tempDir.'/.fuel/processes'))->toBeTrue();
    });

    it('creates agent.db with all required tables', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $dbPath = $this->tempDir.'/.fuel/agent.db';
        expect(file_exists($dbPath))->toBeTrue();

        $requiredTables = ['tasks', 'epics', 'runs', 'reviews', 'agent_health', 'migrations'];
        foreach ($requiredTables as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });

    it('creates starter task', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        // Use TaskService directly to verify (avoids commands that use broken configureCwd)
        $context = new FuelContext($this->tempDir.'/.fuel');
        $context->configureDatabase();
        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(1);
        expect($tasks->first()->title)->toContain('Update README to mention this project uses Fuel');
        expect($tasks->first()->short_id)->toStartWith('f-');
    });

    it('is idempotent - can be run multiple times safely', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $secondExitCode = Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect($secondExitCode)->toBe(0);

        // Use TaskService directly to verify (avoids commands that use broken configureCwd)
        $context = new FuelContext($this->tempDir.'/.fuel');
        $context->configureDatabase();
        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(1);
    });

    it('adds .fuel/ to .gitignore', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $gitignorePath = $this->tempDir.'/.gitignore';
        expect(file_exists($gitignorePath))->toBeTrue();

        $content = file_get_contents($gitignorePath);
        expect($content)->toContain('.fuel/');
    });

    it('creates new .gitignore if it does not exist', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $gitignorePath = $this->tempDir.'/.gitignore';
        expect(file_exists($gitignorePath))->toBeTrue();

        $content = file_get_contents($gitignorePath);
        expect(trim($content))->toBe('.fuel/');
    });

    it('appends .fuel/ to existing .gitignore without duplicating', function (): void {
        file_put_contents($this->tempDir.'/.gitignore', "node_modules\nvendor\n");

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $content = file_get_contents($this->tempDir.'/.gitignore');
        expect($content)->toContain('node_modules');
        expect($content)->toContain('vendor');
        expect($content)->toContain('.fuel/');

        $count = substr_count($content, '.fuel/');
        expect($count)->toBe(1);
    });

    it('updates AGENTS.md with guidelines', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        expect(file_exists($agentsMdPath))->toBeTrue();

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('Fuel');
    });

    it('creates config.yaml with default configuration', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $configPath = $this->tempDir.'/.fuel/config.yaml';
        expect(file_exists($configPath))->toBeTrue();

        $content = file_get_contents($configPath);
        expect($content)->toContain('primary:');
        expect($content)->toContain('complexity:');
    });

    it('creates default config.yaml when --agent flag is provided', function (): void {
        Artisan::call('init', [
            '--cwd' => $this->tempDir,
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
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        // Use TaskService directly to add a task (avoids commands that use broken configureCwd)
        $context = new FuelContext($this->tempDir.'/.fuel');
        $context->configureDatabase();
        $dbService = new DatabaseService($context->getDatabasePath());
        $taskService = new TaskService($dbService);

        $taskService->create(['title' => 'Existing task']);

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $tasks = $taskService->all();
        expect($tasks)->toHaveCount(2);
    });

    it('regression: Schema::hasTable returns true for all tables after init', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        $tables = ['tasks', 'epics', 'runs', 'reviews', 'agent_health'];
        foreach ($tables as $table) {
            expect(Schema::hasTable($table))->toBeTrue("Table $table should exist");
        }
    });
});
