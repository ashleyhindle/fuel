<?php

declare(strict_types=1);

namespace Tests;

use App\Services\DatabaseService;
use App\Services\FuelContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Isolated temp directory for each test - tests must NEVER modify real workspace.
     */
    protected string $testDir;

    protected FuelContext $testContext;

    protected DatabaseService $testDatabaseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->testDir.'/.fuel', 0755, true);

        // Configure isolated database for this test - prevents tests from polluting live DB
        $this->testContext = new FuelContext($this->testDir.'/.fuel');
        $this->testContext->configureDatabase();
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $this->testContext);

        // Run migrations to create tables for Eloquent models
        Artisan::call('migrate', ['--force' => true]);

        // Also configure DatabaseService for tests that use raw SQL
        $this->testDatabaseService = new DatabaseService($this->testContext->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $this->testDatabaseService);
    }

    protected function tearDown(): void
    {
        if (isset($this->testDir) && File::exists($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }
}
