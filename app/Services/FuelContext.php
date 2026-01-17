<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FuelContext
{
    public string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? getcwd().'/.fuel';
    }

    public function getDatabasePath(): string
    {
        return $this->basePath.'/agent.db';
    }

    public function configureDatabase(): void
    {
        config(['database.connections.sqlite.database' => $this->getDatabasePath()]);
        $this->ensureMigrationCompatibility();
    }

    public function getRunsPath(): string
    {
        return $this->basePath.'/runs';
    }

    public function getProcessesPath(): string
    {
        return $this->basePath.'/processes';
    }

    public function getConfigPath(): string
    {
        return $this->basePath.'/config.yaml';
    }

    public function getPlansPath(): string
    {
        return $this->basePath.'/plans';
    }

    public function getPromptsPath(): string
    {
        return $this->basePath.'/prompts';
    }

    /**
     * Get the project root directory (parent of .fuel).
     */
    public function getProjectPath(): string
    {
        return dirname($this->basePath);
    }

    /**
     * Get a slugified project name from the project directory.
     */
    public function getProjectName(): string
    {
        return Str::slug(basename($this->getProjectPath()));
    }

    /**
     * Get the PID file path (absolute).
     */
    public function getPidFilePath(): string
    {
        return $this->basePath.'/consume.pid';
    }

    /**
     * Get the path to the fuel binary that is currently running.
     *
     * PHP_SELF contains the script path - relative for dev (./fuel),
     * absolute for phars (/path/to/fuel). realpath() handles both.
     */
    public function getFuelBinaryPath(): string
    {
        $phpSelf = $_SERVER['PHP_SELF'] ?? null;
        if ($phpSelf !== null) {
            $realPath = realpath($phpSelf);
            if ($realPath !== false) {
                return $realPath;
            }
        }

        throw new \RuntimeException('Unable to determine fuel binary path');
    }

    private function ensureMigrationCompatibility(): void
    {
        $dbPath = $this->getDatabasePath();

        if (! is_file($dbPath)) {
            // Check for orphaned WAL files (main db deleted but WAL files remain)
            if (is_file($dbPath.'-wal') || is_file($dbPath.'-shm')) {
                throw new \RuntimeException(
                    "Orphaned SQLite WAL files found without main database.\n".
                    sprintf('Delete them to start fresh: rm %s*', $dbPath)
                );
            }

            return;
        }

        // Empty files are not valid SQLite databases - likely from interrupted init
        if (filesize($dbPath) === 0) {
            throw new \RuntimeException(
                "Database file exists but is empty (likely from interrupted init).\n".
                ('Delete it manually to start fresh: rm '.$dbPath)
            );
        }

        try {
            if (! Schema::hasTable('schema_version')) {
                return;
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(sprintf('Database file exists but appears corrupt: %s%s', $throwable->getMessage(), PHP_EOL).
            ('Delete it manually to start fresh: rm '.$dbPath), $throwable->getCode(), $throwable);
        }

        $version = (int) (DB::table('schema_version')->value('version') ?? 0);
        if ($version < 14) {
            return;
        }

        if (Schema::hasTable('migrations')) {
            return;
        }

        Schema::create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });

        DB::table('migrations')->insert([
            ['migration' => '0001_01_01_000001_create_tasks_table', 'batch' => 1],
            ['migration' => '0001_01_01_000002_create_epics_table', 'batch' => 1],
            ['migration' => '0001_01_01_000003_create_runs_table', 'batch' => 1],
            ['migration' => '0001_01_01_000004_create_reviews_table', 'batch' => 1],
            ['migration' => '0001_01_01_000005_create_agent_health_table', 'batch' => 1],
        ]);
    }
}
