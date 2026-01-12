<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Review;
use App\Services\DatabaseService;
use Carbon\Carbon;
use LaravelZero\Framework\Commands\Command;

class ReviewsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'reviews
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--all : Show all reviews}
        {--pending : Show only pending reviews}
        {--failed : Show only failed reviews}';

    protected $description = 'List recent review history';

    public function handle(DatabaseService $databaseService): int
    {
        $this->configureDatabasePath($databaseService);

        try {
            // Determine which reviews to fetch
            $status = null;
            $limit = 10;

            if ($this->option('pending')) {
                $status = 'pending';
            } elseif ($this->option('failed')) {
                $status = 'failed';
            }

            if ($this->option('all')) {
                $limit = null;
            }

            $reviews = $databaseService->getAllReviews($status, $limit);

            if ($this->option('json')) {
                $data = array_map(function (Review $r): array {
                    $reviewData = $r->toArray();
                    if ($r->task instanceof \App\Models\Task) {
                        $reviewData['task_id'] = $r->task->short_id;
                    }

                    return $reviewData;
                }, $reviews);

                $this->outputJson($data);

                return self::SUCCESS;
            }

            // Format output
            if ($reviews === []) {
                $this->info('No reviews found.');
            } else {
                $this->info('Recent Reviews');
                $this->line(str_repeat('─', 60));

                foreach ($reviews as $review) {
                    $this->displayReview($review);
                }
            }

            return self::SUCCESS;
        } catch (\Exception $exception) {
            return $this->outputError('Failed to fetch reviews: '.$exception->getMessage());
        }
    }

    /**
     * Configure the DatabaseService with --cwd option if provided.
     */
    protected function configureDatabasePath(DatabaseService $databaseService): void
    {
        if ($cwd = $this->option('cwd')) {
            $databaseService->setDatabasePath($cwd.'/.fuel/agent.db');
        }
    }

    /**
     * Display a single review in the formatted output.
     */
    private function displayReview(Review $review): void
    {
        $taskId = $review->task?->short_id ?? '';
        $status = $review->status ?? 'pending';
        $agent = $review->agent ?? '';
        $startedAt = $review->started_at ?? null;
        $issues = $review->issues();

        // Status indicator
        $statusIndicator = match ($status) {
            'passed' => '<fg=green>✓</> passed',
            'failed' => '<fg=red>✗</> failed',
            'pending' => '<fg=yellow>⏳</> pending',
            default => $status,
        };

        // Relative time
        $timeAgo = $this->formatRelativeTime($startedAt);

        // Build the line
        $line = sprintf(
            '%s  %s  %s  %s',
            $taskId,
            $statusIndicator,
            $timeAgo,
            $agent
        );

        // Add issues for failed reviews
        if ($status === 'failed' && $issues !== []) {
            $issuesStr = '['.implode(', ', $issues).']';
            $line .= '  '.$issuesStr;
        }

        $this->line($line);
    }

    /**
     * Format a date string into relative time (e.g., "2m ago", "1h ago").
     */
    private function formatRelativeTime(?\DateTimeInterface $dateString): string
    {
        if ($dateString === null) {
            return 'unknown';
        }

        try {
            $date = Carbon::instance($dateString)->setTimezone('UTC');
            $now = Carbon::now('UTC');
            $diff = $now->diff($date);

            // If less than 1 minute ago
            if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0) {
                return 'just now';
            }

            // If less than 1 hour ago
            if ($diff->days === 0 && $diff->h === 0) {
                $minutes = $diff->i;

                return $minutes.'m ago';
            }

            // If less than 24 hours ago
            if ($diff->days === 0) {
                $hours = $diff->h;

                return $hours.'h ago';
            }

            // If less than 7 days ago
            if ($diff->days < 7) {
                $days = $diff->days;

                return $days.'d ago';
            }

            // Older than 7 days, show date
            if ($date->format('Y') === $now->format('Y')) {
                return $date->format('M j');
            }

            return $date->format('M j, Y');
        } catch (\Exception) {
            return 'unknown';
        }
    }
}
