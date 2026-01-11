<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('backlog command', function (): void {
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

    it('shows no backlog items when empty', function (): void {
        $this->artisan('backlog')
            ->expectsOutput('No backlog items.')
            ->assertExitCode(0);
    });

    it('lists backlog items', function (): void {
        $taskService = $this->app->make(TaskService::class);

        $item1 = $taskService->create(['title' => 'Item 1']);
        $taskService->update($item1['id'], ['status' => 'someday']);
        $item1 = $taskService->find($item1['id']);

        $item2 = $taskService->create(['title' => 'Item 2', 'description' => 'Description']);
        $taskService->update($item2['id'], ['status' => 'someday']);
        $item2 = $taskService->find($item2['id']);

        Artisan::call('backlog');
        $output = Artisan::output();

        expect($output)->toContain('Backlog items (2):');
        expect($output)->toContain($item1['id']);
        expect($output)->toContain('Item 1');
        expect($output)->toContain($item2['id']);
        expect($output)->toContain('Item 2');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $taskService = $this->app->make(TaskService::class);

        $item1 = $taskService->create(['title' => 'Item 1']);
        $taskService->update($item1['id'], ['status' => 'someday']);

        $item2 = $taskService->create(['title' => 'Item 2']);
        $taskService->update($item2['id'], ['status' => 'someday']);

        Artisan::call('backlog', ['--json' => true]);
        $output = Artisan::output();
        $items = json_decode($output, true);

        expect($items)->toBeArray();
        expect($items)->toHaveCount(2);
        expect($items[0]['id'])->toStartWith('f-');
        expect($items[1]['id'])->toStartWith('f-');
    });
});
