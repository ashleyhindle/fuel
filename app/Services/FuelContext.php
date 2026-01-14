<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Get the project root directory (parent of .fuel).
     */
    public function getProjectPath(): string
    {
        return dirname($this->basePath);
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
     */
    public function getFuelBinaryPath(): string
    {
        // Try multiple sources for the script path
        $candidates = [
            $_SERVER['SCRIPT_FILENAME'] ?? null,
            $_SERVER['argv'][0] ?? null,
            $_SERVER['_'] ?? null, // Bash often sets this to the full path
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            // Try realpath first
            $realPath = realpath($candidate);
            if ($realPath !== false && is_executable($realPath)) {
                return $realPath;
            }

            // If it's an absolute path that exists, use it directly
            if (str_starts_with($candidate, '/') && is_file($candidate)) {
                return $candidate;
            }
        }

        // Try to find 'fuel' in PATH using shell
        $whichFuel = trim((string) shell_exec('which fuel 2>/dev/null'));
        if ($whichFuel !== '' && is_executable($whichFuel)) {
            return $whichFuel;
        }

        // Last resort: check if we're in a project with a local fuel script
        $localFuel = $this->getProjectPath().'/fuel';
        if (is_file($localFuel) && is_executable($localFuel)) {
            return $localFuel;
        }

        throw new \RuntimeException('Unable to determine fuel binary path');
    }

    private function ensureMigrationCompatibility(): void
    {
        if (! is_file($this->getDatabasePath())) {
            return;
        }

        if (! Schema::hasTable('schema_version')) {
            return;
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
