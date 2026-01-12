<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ConfigService;
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

        // Create isolated temp directory for this test
        $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->testDir.'/.fuel', 0755, true);

        // Create minimal config file for tests
        $minimalConfig = <<<'YAML'
primary: test-agent
complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent
agents:
  test-agent:
    command: echo
YAML;
        file_put_contents($this->testDir.'/.fuel/config.yaml', $minimalConfig);

        // Configure FuelContext to use the isolated temp directory
        $this->testContext = new FuelContext($this->testDir.'/.fuel');
        $this->testContext->configureDatabase();

        $this->app->singleton(FuelContext::class, fn (): FuelContext => $this->testContext);

        // Rebind ConfigService to use the test FuelContext
        // This must be done AFTER FuelContext is bound to ensure it gets the correct instance
        $this->app->singleton(ConfigService::class, fn (): ConfigService => new ConfigService($this->testContext));

        // Run migrations to create tables
        Artisan::call('migrate', ['--force' => true]);

        // DatabaseService for tests that use raw SQL
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
