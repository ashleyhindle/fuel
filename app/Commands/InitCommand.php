<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Initialize Fuel in the current project';

    public function handle(TaskService $taskService): int
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

        // Inject guidelines into AGENTS.md
        Artisan::call('guidelines', ['--add' => true, '--cwd' => $cwd]);
        $this->line(Artisan::output());

        // Add starter task
        $task = $taskService->create([
            'title' => 'Update README to mention this project uses Fuel for task management',
            'type' => 'task',
            'priority' => 2,
        ]);

        $this->info("Created starter task: {$task['id']}");
        $this->newLine();
        $this->line('Run your favourite agent and ask it to "Consume the fuel"');

        return self::SUCCESS;
    }
}
