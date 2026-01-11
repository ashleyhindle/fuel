<?php

use App\Services\BacklogService;
use Illuminate\Support\Facades\Artisan;

describe('backlog command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new App\Services\FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(App\Services\FuelContext::class, fn () => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new App\Services\DatabaseService($context->getDatabasePath());
        $this->app->singleton(App\Services\DatabaseService::class, fn () => $databaseService);

        $this->app->singleton(App\Services\TaskService::class, fn (): App\Services\TaskService => new App\Services\TaskService($databaseService));

        $this->app->singleton(App\Services\RunService::class, fn (): App\Services\RunService => new App\Services\RunService($databaseService));

        $this->app->singleton(App\Services\BacklogService::class, fn (): App\Services\BacklogService => new App\Services\BacklogService($context));

        $this->taskService = $this->app->make(App\Services\TaskService::class);
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
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

    it('shows no backlog items when empty', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $this->artisan('backlog')
            ->expectsOutput('No backlog items.')
            ->assertExitCode(0);
    });

    it('lists backlog items', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('Item 1');
        $item2 = $backlogService->add('Item 2', 'Description');

        Artisan::call('backlog');
        $output = Artisan::output();

        expect($output)->toContain('Backlog items (2):');
        expect($output)->toContain($item1['id']);
        expect($output)->toContain('Item 1');
        expect($output)->toContain($item2['id']);
        expect($output)->toContain('Item 2');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('Item 1');
        $item2 = $backlogService->add('Item 2');

        Artisan::call('backlog', ['--json' => true]);
        $output = Artisan::output();
        $items = json_decode($output, true);

        expect($items)->toBeArray();
        expect($items)->toHaveCount(2);
        expect($items[0]['id'])->toStartWith('b-');
        expect($items[1]['id'])->toStartWith('b-');
    });
});
