<?php

use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;

$beforeEach = function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn () => $context);

    $this->dbPath = $context->getDatabasePath();

    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn () => $databaseService);

    $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));

    $this->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

    $this->app->singleton(BacklogService::class, fn (): BacklogService => new BacklogService($context));

    $this->taskService = $this->app->make(TaskService::class);
};

$afterEach = function (): void {
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
};
