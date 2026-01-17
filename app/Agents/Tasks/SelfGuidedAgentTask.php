<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Daemon\DaemonLogger;
use App\Enums\TaskStatus;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;
use App\Services\FuelContext;
use App\Services\PromptService;

/**
 * Agent task for self-guided epic execution.
 *
 * Iterates until all acceptance criteria are met, tracking progress
 * across iterations. Uses 'primary' agent (capable model) always.
 */
class SelfGuidedAgentTask extends AbstractAgentTask
{
    private const MAX_ITERATIONS = 50;

    private const MAX_STUCK_COUNT = 3;

    /**
     * Always use 'primary' agent (capable model) for self-guided tasks.
     */
    public function getAgentName(ConfigService $configService): ?string
    {
        return $configService->getPrimaryAgent();
    }

    /**
     * Build prompt using selfguided.md template.
     */
    public function buildPrompt(string $cwd): string
    {
        $promptService = app(PromptService::class);
        $template = $promptService->loadTemplate('selfguided');

        $epic = $this->task->epic;
        $epicShortId = $epic?->short_id ?? '';
        $epicPlanFilename = $this->getEpicPlanFilename($epicShortId);
        $planContent = $this->loadPlanFile($epicPlanFilename);
        $progressLog = $this->extractProgressLog($planContent);

        // Debug logging to catch prompt mismatch issues
        DaemonLogger::getInstance()->debug('SelfGuidedAgentTask.buildPrompt', [
            'task_short_id' => $this->task->short_id,
            'task_id' => $this->task->id,
            'epic_id' => $this->task->epic_id,
            'epic_short_id' => $epicShortId,
            'plan_filename' => $epicPlanFilename,
        ]);

        $variables = [
            'iteration' => ($this->task->selfguided_iteration ?? 0) + 1,
            'max_iterations' => self::MAX_ITERATIONS,
            'reality' => $this->loadRealityFile($cwd),
            'plan' => $planContent,
            'progress_log' => $progressLog,
            'task' => [
                'short_id' => $this->task->short_id,
                'title' => $this->task->title,
            ],
            'epic' => [
                'short_id' => $epicShortId,
            ],
            'epic_plan_filename' => $epicPlanFilename,
        ];

        return $promptService->render($template, $variables);
    }

    public function getProcessType(): ProcessType
    {
        return ProcessType::Task;
    }

    /**
     * Handle successful completion.
     *
     * Increment iteration, reset stuck count.
     * Reopen task for next iteration if agent called selfguided:continue (task still in_progress).
     * If agent called `done`, task status is already Done - don't reopen.
     */
    public function onSuccess(CompletionResult $result): void
    {
        $this->taskService->update($this->task->short_id, [
            'selfguided_iteration' => ($this->task->selfguided_iteration ?? 0) + 1,
            'selfguided_stuck_count' => 0,
        ]);

        // Refresh task to get current status (may have changed during run)
        $task = $this->taskService->find($this->task->short_id);

        // If task is still in_progress (continue was called, not done), reopen for next iteration
        // This prevents race condition: reopen happens AFTER run completes, not during
        if ($task && $task->status === TaskStatus::InProgress) {
            $this->taskService->reopen($this->task->short_id);
        }
    }

    /**
     * Handle failed completion.
     *
     * Increment stuck count. If >= 3, create needs-human task.
     * Otherwise, reopen for retry.
     */
    public function onFailure(CompletionResult $result): void
    {
        $newStuckCount = ($this->task->selfguided_stuck_count ?? 0) + 1;

        $this->taskService->update($this->task->short_id, [
            'selfguided_stuck_count' => $newStuckCount,
        ]);

        if ($newStuckCount >= self::MAX_STUCK_COUNT) {
            $this->createNeedsHumanTask(
                'Self-guided task stuck after '.$newStuckCount.' consecutive failures',
                'Task '.$this->task->short_id.' has failed '.$newStuckCount.' times in a row. '.
                'Please investigate and either fix the issue or provide guidance.'
            );
            // Task will be blocked by the needs-human dependency
        } else {
            // Reopen for retry - task stays on same iteration until successful
            $this->taskService->reopen($this->task->short_id);
        }
    }

    /**
     * Get epic plan filename from .fuel/plans/ directory.
     */
    private function getEpicPlanFilename(string $epicShortId): string
    {
        if ($epicShortId === '') {
            return '';
        }

        $context = app(FuelContext::class);
        $plansPath = $context->getPlansPath();

        // Look for plan file matching epic ID pattern
        $pattern = '*-'.$epicShortId.'.md';
        $files = glob($plansPath.'/'.$pattern);

        if ($files !== false && $files !== []) {
            return basename($files[0]);
        }

        return '';
    }

    /**
     * Load plan file contents.
     */
    private function loadPlanFile(string $filename): string
    {
        if ($filename === '') {
            return '';
        }

        $context = app(FuelContext::class);
        $path = $context->getPlansPath().'/'.$filename;

        if (! file_exists($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return $content !== false ? $content : '';
    }

    /**
     * Load reality.md file contents.
     */
    private function loadRealityFile(string $cwd): string
    {
        $path = $cwd.'/.fuel/reality.md';

        if (! file_exists($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return $content !== false ? $content : '';
    }

    /**
     * Extract progress log section from plan content.
     */
    private function extractProgressLog(string $planContent): string
    {
        if ($planContent === '') {
            return '';
        }

        // Look for "## Progress Log" section and extract everything after it
        $marker = '## Progress Log';
        $pos = stripos($planContent, $marker);

        if ($pos === false) {
            return '';
        }

        $afterMarker = substr($planContent, $pos + strlen($marker));

        // Find next section (## heading) or end of file
        if (preg_match('/\n##\s/', $afterMarker, $matches, PREG_OFFSET_CAPTURE)) {
            $afterMarker = substr($afterMarker, 0, $matches[0][1]);
        }

        return trim($afterMarker);
    }

    /**
     * Create a needs-human task and block this task on it.
     */
    private function createNeedsHumanTask(string $title, string $description): void
    {
        $needsHumanTask = $this->taskService->create([
            'title' => $title,
            'description' => $description,
            'type' => 'task',
            'priority' => 1,
            'complexity' => 'simple',
            'labels' => ['needs-human'],
            'epic_id' => $this->task->epic_id,
        ]);

        $this->taskService->addDependency($this->task->short_id, $needsHumanTask->short_id);
    }
}
