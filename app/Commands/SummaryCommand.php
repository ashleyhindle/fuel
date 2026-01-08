<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\Agent;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class SummaryCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'summary
        {id : The task ID (supports partial matching)}
        {--all : Show all runs instead of just the latest}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'View task outcome summary with intelligent output parsing';

    public function handle(TaskService $taskService, RunService $runService): int
    {
        $this->configureCwd($taskService);

        try {
            // Validate task exists
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError("Task '{$this->argument('id')}' not found");
            }

            $taskId = $task['id'];

            // Get runs
            $runs = $runService->getRuns($taskId);

            if (empty($runs)) {
                return $this->outputError("No runs found for task '{$taskId}'");
            }

            // Prepare data
            $runsToDisplay = $this->option('all') ? $runs : [end($runs)];

            if ($this->option('json')) {
                // JSON output: return task + runs with parsed summaries
                $output = [
                    'task' => $task,
                    'runs' => array_map(fn (array $run) => $this->enrichRunData($run), $runsToDisplay),
                ];
                $this->outputJson($output);
            } else {
                // Human-friendly output
                $this->displayTaskHeader($task, $runs);
                $this->newLine();

                foreach ($runsToDisplay as $index => $run) {
                    $isLatest = $index === count($runsToDisplay) - 1 && count($runsToDisplay) === 1;
                    $this->displayRunSummary($run, $isLatest);

                    if ($index < count($runsToDisplay) - 1) {
                        $this->newLine();
                        $this->line('  <fg=gray>────────────────────────────────────────</>');
                        $this->newLine();
                    }
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }

    /**
     * Display task header with basic info.
     */
    private function displayTaskHeader(array $task, array $runs): void
    {
        $statusLabel = match ($task['status']) {
            'open' => '<fg=yellow>open</>',
            'in_progress' => '<fg=blue>in progress</>',
            'closed' => '<fg=green>closed</>',
            default => $task['status'],
        };

        $this->line("<fg=cyan>Task:</> {$task['id']} - {$task['title']}");
        $this->line("<fg=cyan>Status:</> {$statusLabel}");
        $this->line('<fg=cyan>Runs:</> '.count($runs));
    }

    /**
     * Display a single run summary.
     */
    private function displayRunSummary(array $run, bool $showAsLatest = false): void
    {
        $header = $showAsLatest ? 'Latest Run' : 'Run';
        $this->line("<fg=white;options=bold>{$header} ({$run['run_id']})</>");

        // Agent info
        $agent = Agent::fromString($run['agent'] ?? null);
        if ($agent !== null) {
            $agentLabel = $agent->label();
            $model = $run['model'] ?? null;
            $agentDisplay = $model ? "{$agentLabel} ({$model})" : $agentLabel;
            $this->line("  <fg=cyan>Agent:</> {$agentDisplay}");
        } elseif (isset($run['agent']) && $run['agent'] !== null) {
            $this->line("  <fg=cyan>Agent:</> {$run['agent']}");
        }

        // Duration
        $duration = $this->calculateDuration($run['started_at'] ?? null, $run['ended_at'] ?? null);
        if ($duration !== '') {
            $this->line("  <fg=cyan>Duration:</> {$duration}");
        }

        // Cost
        if (isset($run['cost_usd']) && $run['cost_usd'] !== null) {
            $cost = '$'.number_format($run['cost_usd'], 4);
            $this->line("  <fg=cyan>Cost:</> {$cost}");
        }

        // Exit code
        if (isset($run['exit_code']) && $run['exit_code'] !== null) {
            $exitColor = $run['exit_code'] === 0 ? 'green' : 'red';
            $exitLabel = $run['exit_code'] === 0 ? 'success' : 'failed';
            $this->line("  <fg=cyan>Exit:</> <fg={$exitColor}>{$run['exit_code']} ({$exitLabel})</>");
        }

        // Session ID and resume command
        if (isset($run['session_id']) && $run['session_id'] !== null && $agent !== null) {
            $this->newLine();
            $this->line("  <fg=cyan>Session:</> {$run['session_id']}");
            $this->line("  <fg=cyan>Resume:</> {$agent->resumeCommand($run['session_id'])}");
        }

        // Parse and display output summary
        if (isset($run['output']) && $run['output'] !== null && $run['output'] !== '') {
            $this->newLine();
            $this->line('  <fg=white;options=bold>Output Summary:</>');
            $this->displayOutputSummary($run['output']);
        }
    }

    /**
     * Parse output and display intelligent summary.
     */
    private function displayOutputSummary(string $output): void
    {
        $summary = $this->parseOutput($output);

        if (empty($summary)) {
            $this->line('    <fg=gray>No actionable items detected in output</>');

            return;
        }

        foreach ($summary as $item) {
            $this->line("    - {$item}");
        }
    }

    /**
     * Parse output for common patterns.
     *
     * @return array<int, string> Array of summary items
     */
    private function parseOutput(string $output): array
    {
        $summary = [];

        // File operations
        $fileCreated = $this->extractPattern($output, '/(?:Created|Writing|Wrote|Created new file|Create).*?(?:file|class)?[\s:]+([^\s\n]+\.(?:php|js|ts|jsx|tsx|json|yaml|yml|md|txt|html|css))/i');
        foreach ($fileCreated as $file) {
            $summary[] = "Created file: {$file}";
        }

        $fileModified = $this->extractPattern($output, '/(?:Modified|Updated|Editing|Edit|Changed).*?(?:file)?[\s:]+([^\s\n]+\.(?:php|js|ts|jsx|tsx|json|yaml|yml|md|txt|html|css))/i');
        foreach ($fileModified as $file) {
            $summary[] = "Modified file: {$file}";
        }

        $fileDeleted = $this->extractPattern($output, '/(?:Deleted|Removed|Removing).*?(?:file)?[\s:]+([^\s\n]+\.(?:php|js|ts|jsx|tsx|json|yaml|yml|md|txt|html|css))/i');
        foreach ($fileDeleted as $file) {
            $summary[] = "Deleted file: {$file}";
        }

        // Test results
        if (preg_match('/(\d+)\s+(?:tests?|specs?)\s+passed/i', $output, $matches)) {
            $summary[] = "{$matches[1]} tests passed";
        }

        if (preg_match('/(\d+)\s+(?:tests?|specs?)\s+failed/i', $output, $matches)) {
            $summary[] = "<fg=red>{$matches[1]} tests failed</>";
        }

        // Pest/PHPUnit specific
        if (preg_match('/Tests:\s+(\d+)\s+passed/i', $output, $matches)) {
            $summary[] = "{$matches[1]} tests passed";
        }

        if (preg_match('/Assertions:\s+(\d+)\s+passed/i', $output, $matches)) {
            $summary[] = "{$matches[1]} assertions passed";
        }

        // Git commits
        $commits = $this->extractPattern($output, '/\[(?:main|master|develop|[\w\-\/]+)\s+([a-f0-9]{7,})\]/i');
        foreach ($commits as $commit) {
            $summary[] = "Git commit: {$commit}";
        }

        // Error/warning patterns
        if (preg_match_all('/(?:Error|Exception|Fatal):\s*(.{0,60})/i', $output, $matches)) {
            foreach (array_slice($matches[1], 0, 3) as $error) {
                $summary[] = '<fg=red>Error: '.trim($error).'</>';
            }
        }

        if (preg_match_all('/Warning:\s*(.{0,60})/i', $output, $matches)) {
            foreach (array_slice($matches[1], 0, 3) as $warning) {
                $summary[] = '<fg=yellow>Warning: '.trim($warning).'</>';
            }
        }

        // Command executions
        if (preg_match('/(?:npm|composer|yarn|pnpm)\s+install/i', $output)) {
            $summary[] = 'Installed dependencies';
        }

        if (preg_match('/(?:npm|yarn|pnpm)\s+(?:run\s+)?build/i', $output)) {
            $summary[] = 'Built project';
        }

        // Fuel task operations
        $fuelTasks = $this->extractPattern($output, '/(?:fuel add|Created task).*?(f-[a-f0-9]{6})/i');
        foreach ($fuelTasks as $taskId) {
            $summary[] = "Created task: {$taskId}";
        }

        if (preg_match('/fuel done\s+(f-[a-f0-9]{6})/i', $output, $matches)) {
            $summary[] = "Completed task: {$matches[1]}";
        }

        return array_unique($summary);
    }

    /**
     * Extract matches from a regex pattern.
     *
     * @return array<int, string>
     */
    private function extractPattern(string $text, string $pattern): array
    {
        $matches = [];
        if (preg_match_all($pattern, $text, $results)) {
            $matches = array_unique($results[1] ?? []);
        }

        return array_values($matches);
    }

    /**
     * Calculate duration between two timestamps.
     */
    private function calculateDuration(?string $startedAt, ?string $endedAt): string
    {
        if ($startedAt === null) {
            return '';
        }

        try {
            $start = new \DateTime($startedAt);
            $end = $endedAt !== null ? new \DateTime($endedAt) : new \DateTime;

            $diff = $start->diff($end);

            // Format duration
            $parts = [];

            if ($diff->days > 0) {
                $parts[] = $diff->days.'d';
            }

            if ($diff->h > 0) {
                $parts[] = $diff->h.'h';
            }

            if ($diff->i > 0) {
                $parts[] = $diff->i.'m';
            }

            if ($diff->s > 0 || empty($parts)) {
                $parts[] = $diff->s.'s';
            }

            return implode(' ', $parts);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Enrich run data with calculated fields and parsed summary for JSON output.
     */
    private function enrichRunData(array $run): array
    {
        $run['duration'] = $this->calculateDuration($run['started_at'] ?? null, $run['ended_at'] ?? null);

        if (isset($run['output']) && $run['output'] !== null && $run['output'] !== '') {
            $run['parsed_summary'] = $this->parseOutput($run['output']);
        }

        return $run;
    }
}
