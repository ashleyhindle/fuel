<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ReviewShowCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'review:show
        {id : The review ID (r-xxxxx, supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--raw : Show raw stdout output instead of truncated}';

    protected $description = 'Show detailed review information including agent output';

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService): int
    {
        $this->configureCwd($context);

        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $reviewId = $this->argument('id');

        // Strip 'review-' prefix if present (from ShowCommand delegation)
        if (str_starts_with($reviewId, 'review-')) {
            $reviewId = 'r-'.substr($reviewId, 7);
        }

        $review = $dbService->getReview($reviewId);

        if ($review === null) {
            return $this->outputError(sprintf("Review '%s' not found", $reviewId));
        }

        // Get associated task info
        $task = null;
        if ($review->task_id) {
            $task = $taskService->find($review->task_id);
        }

        // Get stdout from process directory
        $stdout = $this->getReviewOutput($review->task_id, $context);

        if ($this->option('json')) {
            $data = $review->toArray();
            if ($task !== null) {
                $data['task_title'] = $task->title;
            }
            if ($stdout !== null) {
                $data['stdout'] = $stdout;
            }
            $this->outputJson($data);

            return self::SUCCESS;
        }

        // Display review details
        $statusColor = match ($review->status) {
            'passed' => 'green',
            'failed' => 'red',
            default => 'yellow',
        };

        $this->info('Review: '.$review->id);
        $this->line('  Task: '.($review->task_id ?? 'N/A').($task ? ' - '.$task->title : ''));
        $this->line(sprintf('  Status: <fg=%s>%s</>', $statusColor, strtoupper($review->status ?? 'pending')));
        $this->line('  Agent: '.($review->agent ?? 'N/A'));

        if ($review->started_at) {
            $this->line('  Started: '.$this->formatDateTime($review->started_at));
        }

        if ($review->completed_at) {
            $this->line('  Completed: '.$this->formatDateTime($review->completed_at));
        }

        // Show issues if failed
        $issues = $review->issues();
        if ($issues !== []) {
            $this->newLine();
            $this->line('  <fg=cyan>── Issues ──</>');
            foreach ($issues as $index => $issue) {
                $this->line(sprintf('  %d. %s', $index + 1, $issue));
            }
        }

        // Show agent output
        if ($stdout !== null) {
            $this->newLine();
            $this->line('  <fg=cyan>── Agent Output ──</>');

            if ($this->option('raw')) {
                $this->getOutput()->write(OutputFormatter::escape($stdout));
            } else {
                $lines = explode("\n", $stdout);
                $maxLines = 50;

                if (count($lines) > $maxLines) {
                    $this->line(sprintf('  <fg=yellow>(showing last %d of %d lines)</>', $maxLines, count($lines)));
                    $lines = array_slice($lines, -$maxLines);
                }

                foreach ($lines as $line) {
                    $this->line('  '.OutputFormatter::escape($line));
                }
            }
        }

        return self::SUCCESS;
    }

    private function getReviewOutput(?string $taskId, FuelContext $context): ?string
    {
        if ($taskId === null) {
            return null;
        }

        $cwd = $this->option('cwd') ?: getcwd();
        $stdoutPath = $cwd.'/.fuel/processes/review-'.$taskId.'/stdout.log';

        if (! File::exists($stdoutPath)) {
            return null;
        }

        try {
            $content = File::get($stdoutPath);

            return $content !== false && $content !== '' ? $content : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function formatDateTime(string $dateTimeString): string
    {
        if ($dateTimeString === '') {
            return '';
        }

        try {
            $date = new \DateTime($dateTimeString);

            return $date->format('M j H:i');
        } catch (\Exception) {
            return $dateTimeString;
        }
    }
}
