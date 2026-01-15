<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\PromptService;
use App\Services\SkillService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class InitCommand extends Command
{
    protected $signature = 'init
        {--cwd= : Working directory (defaults to current directory)}
        {--agent= : Agent to use (cursor-agent|claude|opencode)}
        {--model= : Model to use (for agents that support models)}';

    protected $description = 'Initialize Fuel in the current project';

    public function handle(FuelContext $context, TaskService $taskService, ConfigService $configService, DatabaseService $databaseService, SkillService $skillService, PromptService $promptService): int
    {
        try {
            return $this->doInit($context, $taskService, $configService, $skillService, $promptService);
        } catch (Throwable $throwable) {
            $this->error('Init failed: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function doInit(FuelContext $context, TaskService $taskService, ConfigService $configService, SkillService $skillService, PromptService $promptService): int
    {
        // Use FuelContext as source of truth, --cwd option only overrides if explicitly set
        if ($this->option('cwd')) {
            $context->basePath = $this->option('cwd').'/.fuel';
        }

        $cwd = $context->getProjectPath();

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

        // Create plans directory for epic plan files
        $plansDir = $context->getPlansPath();
        if (! is_dir($plansDir)) {
            mkdir($plansDir, 0755, true);
        }

        // Create stub reality.md
        $realityPath = $context->basePath.'/reality.md';
        if (! file_exists($realityPath)) {
            $stubContent = <<<'REALITY'
# Reality

## Architecture
This section will be populated after the first task completion.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|

## Entry Points
This section will be populated after the first task completion.

## Patterns
This section will be populated after the first task completion.

## Recent Changes
_Last updated: never_

REALITY;
            file_put_contents($realityPath, $stubContent);
        }

        // Create prompts directory for customizable prompt templates
        $promptsDir = $context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }
        $promptService->writeDefaultPrompts();

        // Configure database path and run migrations to create schema
        $context->configureDatabase();
        Artisan::call('migrate', ['--force' => true]);

        // Determine agent and model (from flags or defaults)
        $agent = $this->getAgent();
        $model = $this->getModel();

        // Create default config
        $configService->createDefaultConfig();

        // Update config with provided agent/model if specified
        if ($agent !== null || $model !== null) {
            $this->updateConfig($context->getConfigPath(), $agent, $model);
        }

        // Ensure .fuel paths are in .gitignore
        $this->ensureGitignoreEntry($cwd);

        // Inject guidelines into AGENTS.md
        Artisan::call('guidelines', ['--add' => true, '--cwd' => $cwd]);
        $guidelinesOutput = trim(Artisan::output());
        if ($guidelinesOutput !== '') {
            $this->line($guidelinesOutput);
        }

        // Install Fuel skills to agent skill directories
        $installed = $skillService->installSkills($cwd);
        if ($installed !== []) {
            $this->info('Fuel skills updated: .claude/, .codex/');
        }

        // Add starter task only if no tasks exist
        if ($taskService->all()->isEmpty()) {
            $task = $taskService->create([
                'title' => 'Initialize .fuel/reality.md with codebase architecture',
                'type' => 'task',
                'priority' => 1,
                'complexity' => 'moderate',
                'description' => 'Explore the codebase and populate .fuel/reality.md with: Architecture overview, key modules (table with Module|Purpose|Entry Point), main entry points, coding patterns/conventions, and leave Recent Changes empty. Be concise - this is a quick reference, not documentation.',
            ]);

            $this->info('Created starter task: '.$task->short_id);

            $this->newLine();
            $this->line('Run your favourite agent and ask it to "Consume the fuel"');
        }

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
     * Ensure .fuel transient files are in .gitignore (plans/ is committed).
     */
    private function ensureGitignoreEntry(string $cwd): void
    {
        $gitignorePath = $cwd.'/.gitignore';

        // Selective ignores - plans/ and prompts/ are committed, transient files ignored
        $entries = [
            '.fuel/*.lock',
            '.fuel/*.log',
            '.fuel/agent.db',
            '.fuel/config.yaml',
            '.fuel/processes/',
            '.fuel/runs/',
            '.fuel/prompts/*.new',
        ];

        if (file_exists($gitignorePath)) {
            $content = file_get_contents($gitignorePath);
            if ($content === false) {
                throw new RuntimeException('Failed to read .gitignore file: '.$gitignorePath);
            }

            // Remove old blanket .fuel/ ignore if present
            $content = preg_replace('/^\.fuel\/\s*$/m', '', $content);

            $added = [];
            foreach ($entries as $entry) {
                if (! str_contains($content, $entry)) {
                    $content = rtrim($content)."\n".$entry;
                    $added[] = $entry;
                }
            }

            if ($added !== []) {
                file_put_contents($gitignorePath, rtrim($content)."\n");
                $this->info('Updated .gitignore with fuel entries');
            }
        } else {
            file_put_contents($gitignorePath, implode("\n", $entries)."\n");
            $this->info('Created .gitignore with fuel entries');
        }
    }
}
