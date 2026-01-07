<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigService;
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

    public function handle(TaskService $taskService, ConfigService $configService): int
    {
        $cwd = $this->option('cwd') ?: getcwd();

        // Create .fuel directory
        $fuelDir = $cwd.'/.fuel';
        if (! is_dir($fuelDir)) {
            mkdir($fuelDir, 0755, true);
            $this->info('Created .fuel/ directory');
        }

        // Initialize TaskService (creates tasks.jsonl if needed)
        $taskService->setStoragePath($fuelDir.'/tasks.jsonl');
        $taskService->initialize();

        // Handle agent/model configuration
        $configPath = $fuelDir.'/config.yaml';
        $configService->setConfigPath($configPath);

        // Determine agent and model (from flags or defaults)
        $agent = $this->getAgent();
        $model = $this->getModel($agent);

        // Create default config
        $configService->createDefaultConfig();

        // Update config with provided agent/model if specified
        if ($agent !== null || $model !== null) {
            $this->updateConfig($configPath, $agent, $model);
        }

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

            $this->info("Created starter task: {$task['id']}");
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
        $agentFlag = $this->option('agent');

        // Accept any agent string - no validation needed
        return $agentFlag;
    }

    /**
     * Get model from flag or use defaults.
     */
    private function getModel(?string $agent): ?string
    {
        $modelFlag = $this->option('model');

        if ($modelFlag !== null) {
            return $modelFlag;
        }

        // No flag provided - use defaults from ConfigService
        return null;
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
            throw new RuntimeException("Failed to read config file: {$configPath}");
        }

        $config = Yaml::parse($content);
        if (! is_array($config)) {
            throw new RuntimeException('Invalid config format: expected array');
        }

        // Update complexity levels with provided agent/model
        if (isset($config['complexity']) && is_array($config['complexity'])) {
            foreach ($config['complexity'] as $complexity => &$complexityConfig) {
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
}
