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
