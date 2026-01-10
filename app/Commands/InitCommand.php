<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class InitCommand extends Command
{
    protected $signature = 'init
        {--cwd= : Working directory (defaults to current directory)}
        {--agent= : Agent to use (cursor-agent|claude|opencode)}
        {--model= : Model to use (for agents that support models)}';

    protected $description = 'Initialize Fuel in the current project';

    public function handle(FuelContext $context, TaskService $taskService, ConfigService $configService, DatabaseService $databaseService): int
    {
        $cwd = $this->option('cwd') ?: getcwd();

        // Configure FuelContext with the working directory
        $context->basePath = $cwd.'/.fuel';

        // Create .fuel directory and subdirectories
        $fuelDir = $context->basePath;
        if (! is_dir($fuelDir)) {
            mkdir($fuelDir, 0755, true);
            $this->info('Created .fuel/ directory');
        }

        // Create processes directory for agent output capture
        $processesDir = $context->getProcessesPath();
        if (! is_dir($processesDir)) {
            mkdir($processesDir, 0755, true);
        }

        // Initialize TaskService (creates database schema if needed)
        // DatabaseService needs to be reconfigured since FuelContext changed
        $databaseService->setDatabasePath($context->getDatabasePath());
        $taskService->initialize();

        // Initialize database (creates agent.db with schema if needed)
        $dbPath = $context->getDatabasePath();
        $databaseService->initialize();
        if (! file_exists($dbPath)) {
            $this->info('Created agent.db with schema');
        }

        // Determine agent and model (from flags or defaults)
        $agent = $this->getAgent();
        $model = $this->getModel();

        // Create default config
        $configService->createDefaultConfig();

        // Update config with provided agent/model if specified
        if ($agent !== null || $model !== null) {
            $this->updateConfig($context->getConfigPath(), $agent, $model);
        }

        // Ensure .fuel/runs/ is in .gitignore
        $this->ensureGitignoreEntry($cwd);

        // Inject guidelines into AGENTS.md
        Artisan::call('guidelines', ['--add' => true, '--cwd' => $cwd]);
        $this->line(Artisan::output());

        // Add starter task only if tasks.jsonl is empty
        if ($taskService->all()->isEmpty()) {
            $task = $taskService->create([
                'title' => 'Update README to mention this project uses Fuel for task management',
                'type' => 'task',
                'priority' => 2,
            ]);

            $this->info('Created starter task: '.$task['id']);
        }

        $this->newLine();
        $this->line('Run your favourite agent and ask it to "Consume the fuel"');

        return self::SUCCESS;
    }

    /**
     * Get agent from flag or use defaults.
     */
    private function getAgent(): ?string
    {
        // Accept any agent string - no validation needed
        return $this->option('agent');
    }

    /**
     * Get model from flag or use defaults.
     */
    private function getModel(): ?string
    {
        return $this->option('model');
    }

    /**
     * Update config file with provided agent/model.
     */
    private function updateConfig(string $configPath, ?string $agent, ?string $model): void
    {
        if (! file_exists($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new RuntimeException('Failed to read config file: '.$configPath);
        }

        $config = Yaml::parse($content);
        if (! is_array($config)) {
            throw new RuntimeException('Invalid config format: expected array');
        }

        // Update complexity levels with provided agent/model
        if (isset($config['complexity']) && is_array($config['complexity'])) {
            foreach ($config['complexity'] as &$complexityConfig) {
                if (! is_array($complexityConfig)) {
                    continue;
                }

                // Update agent if provided
                if ($agent !== null) {
                    $complexityConfig['agent'] = $agent;
                }

                // Update model if provided
                if ($model !== null) {
                    $complexityConfig['model'] = $model;
                }
            }

            unset($complexityConfig); // Break reference
        }

        $yaml = Yaml::dump($config, 4);
        file_put_contents($configPath, $yaml);
    }

    /**
     * Ensure .fuel/runs/, .fuel/processes/, and .fuel/agent.db are in .gitignore.
     */
    private function ensureGitignoreEntry(string $cwd): void
    {
        $gitignorePath = $cwd.'/.gitignore';
        $entries = ['.fuel/runs/', '.fuel/processes/', '.fuel/agent.db'];

        // Check if .gitignore exists
        if (file_exists($gitignorePath)) {
            $content = file_get_contents($gitignorePath);
            if ($content === false) {
                throw new RuntimeException('Failed to read .gitignore file: '.$gitignorePath);
            }

            $added = [];
            foreach ($entries as $entry) {
                if (! str_contains($content, $entry)) {
                    $content = rtrim($content)."\n".$entry."\n";
                    $added[] = $entry;
                }
            }

            if (! empty($added)) {
                file_put_contents($gitignorePath, $content);
                $this->info('Added '.implode(', ', $added).' to .gitignore');
            }
        } else {
            // Create new .gitignore with entries
            file_put_contents($gitignorePath, implode("\n", $entries)."\n");
            $this->info('Created .gitignore with '.implode(', ', $entries));
        }
    }
}
