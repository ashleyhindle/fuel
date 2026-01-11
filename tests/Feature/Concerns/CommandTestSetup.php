<?php

use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;

function setupCommandTest(&$test): void
{
    $test->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($test->tempDir.'/.fuel', 0755, true);

    $context = new FuelContext($test->tempDir.'/.fuel');
    $test->app->singleton(FuelContext::class, fn () => $context);

    $test->dbPath = $context->getDatabasePath();

    $databaseService = new DatabaseService($context->getDatabasePath());
    $test->app->singleton(DatabaseService::class, fn () => $databaseService);

    $test->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));

    $test->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

    $test->app->singleton(BacklogService::class, fn (): BacklogService => new BacklogService($context));

    $test->taskService = $test->app->make(TaskService::class);
}

function cleanupCommandTest($tempDir): void
{
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

    $deleteDir($tempDir);
}
