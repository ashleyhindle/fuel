<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\ProcessManagerInterface;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
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
        // Use forgetInstance + instance to properly override the AppServiceProvider binding
        $this->testContext = new FuelContext($this->testDir.'/.fuel');
        $this->testContext->configureDatabase();

        $this->app->forgetInstance(FuelContext::class);
        $this->app->instance(FuelContext::class, $this->testContext);

        // Clear any cached services that depend on FuelContext to ensure they get the test context
        // These services cache FuelContext internally, so we need to clear them before rebinding
        $this->app->forgetInstance(ConfigService::class);
        $this->app->forgetInstance(ProcessManager::class);
        $this->app->forgetInstance(ProcessManagerInterface::class);

        // Rebind ConfigService with an explicit instance to ensure it uses test FuelContext
        $configService = new ConfigService($this->testContext);
        $this->app->instance(ConfigService::class, $configService);

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
        $this->app->forgetInstance(ConfigService::class);
        $configService = new ConfigService($this->testContext);
        $this->app->instance(ConfigService::class, $configService);
    }
}
