<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Isolated temp directory for each test - tests must NEVER modify real workspace.
     */
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->testDir.'/.fuel', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->testDir) && File::exists($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }
}
