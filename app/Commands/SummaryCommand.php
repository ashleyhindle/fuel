<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\AgentDriverRegistry;
use App\Agents\Drivers\AgentDriverInterface;
use App\Commands\Concerns\CalculatesDuration;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Run;
use App\Models\Task;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class SummaryCommand extends Command
{
    use CalculatesDuration;
    use HandlesJsonOutput;

    protected $signature = 'summary
        {id : The task ID (supports partial matching)}
        {--all : Show all runs instead of just the latest}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'View task outcome summary with intelligent output parsing';

    public function handle(TaskService $taskService, RunService $runService): int
    {
        try {
            // Validate task exists
            $task = $taskService->find($this->argument('id'));

            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $this->argument('id')));
            }

            $taskId = $task->short_id;

            // Get runs
            $runs = $runService->getRuns($taskId);

            if ($runs === []) {
                return $this->outputError(sprintf("No runs found for task '%s'", $taskId));
            }

            // Prepare data
            $runsToDisplay = $this->option('all') ? $runs : [end($runs)];

            if ($this->option('json')) {
                // JSON output: return task + runs with parsed summaries
                $output = [
                    'task' => $task->toArray(),
                    'runs' => array_map($this->enrichRunData(...), $runsToDisplay),
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
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }

    /**
     * Display task header with basic info.
     */
    private function displayTaskHeader(Task $task, array $runs): void
    {
        $statusLabel = match ($task->status) {
            TaskStatus::Open => '<fg=yellow>open</>',
            TaskStatus::InProgress => '<fg=blue>in progress</>',
            TaskStatus::Review => '<fg=magenta>review</>',
            TaskStatus::Done => '<fg=green>done</>',
            TaskStatus::Cancelled => '<fg=gray>cancelled</>',
            default => $task->status->value,
        };

        $this->line(sprintf('<fg=cyan>Task:</> %s - %s', $task->short_id, $task->title));
        $this->line('<fg=cyan>Status:</> '.$statusLabel);
        $this->line('<fg=cyan>Runs:</> '.count($runs));
    }

    /**
     * Display a single run summary.
     */
    private function displayRunSummary(Run $run, bool $showAsLatest = false): void
    {
        $header = $showAsLatest ? 'Latest Run' : 'Run';
        $this->line(sprintf('<fg=white;options=bold>%s (%s)</>', $header, $run->run_id));

        // Agent info
        $driver = null;
        if (isset($run->agent) && $run->agent !== null) {
            try {
                $registry = new AgentDriverRegistry;
                $driver = $registry->getForAgentName($run->agent);
                $agentLabel = $driver->getLabel();
                $model = $run->model ?? null;
                $agentDisplay = $model ? sprintf('%s (%s)', $agentLabel, $model) : $agentLabel;
                $this->line('  <fg=cyan>Agent:</> '.$agentDisplay);
            } catch (\RuntimeException) {
                // Driver not found, fall back to raw agent name
                $this->line('  <fg=cyan>Agent:</> '.$run->agent);
            }
        }

        // Duration
        $duration = $this->calculateDuration($run->started_at ?? null, $run->ended_at ?? null);
        if ($duration !== '') {
            $this->line('  <fg=cyan>Duration:</> '.$duration);
        }

        // Cost
        if (isset($run->cost_usd) && $run->cost_usd !== null) {
            $cost = '$'.number_format($run->cost_usd, 4);
            $this->line('  <fg=cyan>Cost:</> '.$cost);
        }

        // Exit code
        if (isset($run->exit_code) && $run->exit_code !== null) {
            $exitColor = $run->exit_code === 0 ? 'green' : 'red';
            $exitLabel = $run->exit_code === 0 ? 'success' : 'failed';
            $this->line(sprintf('  <fg=cyan>Exit:</> <fg=%s>%s (%s)</>', $exitColor, $run->exit_code, $exitLabel));
        }

        // Session ID and resume command
        if (isset($run->session_id) && $run->session_id !== null && $driver instanceof AgentDriverInterface) {
            $this->newLine();
            $this->line('  <fg=cyan>Session:</> '.$run->session_id);
            $this->line('  <fg=cyan>Resume:</> '.$driver->getResumeCommand($run->session_id));
        }

        // Parse and display output summary
        if (isset($run->output) && $run->output !== null && $run->output !== '') {
            $this->newLine();
            $this->line('  <fg=white;options=bold>Output Summary:</>');
            $this->displayOutputSummary($run->output);
        }
    }

    /**
     * Parse output and display intelligent summary.
     */
    private function displayOutputSummary(string $output): void
    {
        $summary = $this->parseOutput($output);

        if ($summary === []) {
            $this->line('    <fg=gray>No actionable items detected in output</>');

            return;
        }

        foreach ($summary as $item) {
            $this->line('    - '.$item);
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
            $summary[] = 'Created file: '.$file;
        }

        $fileModified = $this->extractPattern($output, '/(?:Modified|Updated|Editing|Edit|Changed).*?(?:file)?[\s:]+([^\s\n]+\.(?:php|js|ts|jsx|tsx|json|yaml|yml|md|txt|html|css))/i');
        foreach ($fileModified as $file) {
            $summary[] = 'Modified file: '.$file;
        }

        $fileDeleted = $this->extractPattern($output, '/(?:Deleted|Removed|Removing).*?(?:file)?[\s:]+([^\s\n]+\.(?:php|js|ts|jsx|tsx|json|yaml|yml|md|txt|html|css))/i');
        foreach ($fileDeleted as $file) {
            $summary[] = 'Deleted file: '.$file;
        }

        // Test results
        if (preg_match('/(\d+)\s+(?:tests?|specs?)\s+passed/i', $output, $matches)) {
            $summary[] = $matches[1].' tests passed';
        }

        if (preg_match('/(\d+)\s+(?:tests?|specs?)\s+failed/i', $output, $matches)) {
            $summary[] = sprintf('<fg=red>%s tests failed</>', $matches[1]);
        }

        // Pest/PHPUnit specific
        if (preg_match('/Tests:\s+(\d+)\s+passed/i', $output, $matches)) {
            $summary[] = $matches[1].' tests passed';
        }

        if (preg_match('/Assertions:\s+(\d+)\s+passed/i', $output, $matches)) {
            $summary[] = $matches[1].' assertions passed';
        }

        // Git commits
        $commits = $this->extractPattern($output, '/\[(?:main|master|develop|[\w\-\/]+)\s+([a-f0-9]{7,})\]/i');
        foreach ($commits as $commit) {
            $summary[] = 'Git commit: '.$commit;
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
            $summary[] = 'Created task: '.$taskId;
        }

        if (preg_match('/fuel done\s+(f-[a-f0-9]{6})/i', $output, $matches)) {
            $summary[] = 'Completed task: '.$matches[1];
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
     * Enrich run data with calculated fields and parsed summary for JSON output.
     */
    private function enrichRunData(Run $run): array
    {
        $runData = $run->toArray();
        $runData['duration'] = $this->calculateDuration($run->started_at ?? null, $run->ended_at ?? null);

        if (isset($run->output) && $run->output !== null && $run->output !== '') {
            $runData['parsed_summary'] = $this->parseOutput($run->output);
        }

        return $runData;
    }
}
