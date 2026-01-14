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
     *
     * Tries multiple sources in order:
     * 1. argv[0] with realpath - works for ./fuel and /full/path/to/fuel
     * 2. PHP_SELF - reliable for phars, gives full path
     * 3. 'which' command - fallback when argv[0] is bare command name
     */
    public function getFuelBinaryPath(): string
    {
        // Try argv[0] first - works for ./fuel and /full/path/to/fuel
        $scriptPath = $_SERVER['argv'][0] ?? null;
        if ($scriptPath !== null) {
            $realPath = realpath($scriptPath);
            if ($realPath !== false) {
                return $realPath;
            }
        }

        // Try PHP_SELF - for phars this contains the full binary path
        $phpSelf = $_SERVER['PHP_SELF'] ?? null;
        if ($phpSelf !== null) {
            $realPath = realpath($phpSelf);
            if ($realPath !== false) {
                return $realPath;
            }
        }

        // Last resort: use 'which' when argv[0] is a bare command name
        if ($scriptPath !== null && ! str_contains($scriptPath, '/')) {
            $whichPath = trim((string) shell_exec('which '.escapeshellarg($scriptPath).' 2>/dev/null'));
            if ($whichPath !== '' && is_executable($whichPath)) {
                return $whichPath;
            }
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
