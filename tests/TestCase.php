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
        @mkdir($this->testDir.'/.fuel', 0755, true);

        // Create minimal config file for tests
        file_put_contents($this->testDir.'/.fuel/config.yaml', $this->getDefaultConfigYaml());

        // Configure FuelContext to use the isolated temp directory
        $this->testContext = $this->app->make(FuelContext::class);
        $this->testContext->basePath = $this->testDir.'/.fuel';
        $this->testContext->configureDatabase();

        // Ensure cached config is reloaded against the test context
        $this->app->make(ConfigService::class)->reload();

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

    /**
     * Get the default minimal config YAML for tests.
     */
    protected function getDefaultConfigYaml(): string
    {
        return <<<'YAML'
primary: test-agent
complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent
agents:
  test-agent:
    driver: claude
    command: echo
YAML;
    }

    /**
     * Set custom config YAML and reset ConfigService to use it.
     *
     * @param  string  $yaml  The YAML config content to write
     */
    protected function setConfig(string $yaml): void
    {
        // Write config to test directory
        file_put_contents($this->testDir.'/.fuel/config.yaml', $yaml);

        // Reset ConfigService in container to pick up new config
        $this->app->make(ConfigService::class)->reload();
    }
}
