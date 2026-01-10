<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ArchiveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'archive
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--days=30 : Archive closed tasks older than N days}
        {--all : Archive all closed tasks regardless of age}';

    protected $description = 'Move closed tasks to archive file (.fuel/archive.jsonl)';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $all = $this->option('all');
        $days = (int) $this->option('days');

        if ($days < 1 && ! $all) {
            return $this->outputError('Days must be a positive integer (or use --all to archive all closed tasks)');
        }

        $result = $taskService->archiveTasks($days, $all);

        if ($this->option('json')) {
            $this->outputJson($result);
        } elseif ($result['archived'] === 0) {
            $this->info('No tasks to archive.');
        } else {
            $this->info(sprintf('Archived %s task(s) to .fuel/archive.jsonl', $result['archived']));
        }

        return self::SUCCESS;
    }
}
