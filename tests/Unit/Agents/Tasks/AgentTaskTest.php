<?php

declare(strict_types=1);

use App\Agents\Tasks\ReviewAgentTask;
use App\Agents\Tasks\SelfGuidedAgentTask;
use App\Agents\Tasks\UpdateRealityAgentTask;
use App\Agents\Tasks\WorkAgentTask;
use App\Contracts\ReviewServiceInterface;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\ProcessType;
use App\Prompts\ReviewPrompt;
use App\Services\ConfigService;
use App\Services\PromptService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;

beforeEach(function (): void {
    // Create mock services
    $this->taskService = Mockery::mock(TaskService::class);
    $this->configService = Mockery::mock(ConfigService::class);
    $this->promptBuilder = Mockery::mock(TaskPromptBuilder::class);
    $this->promptService = Mockery::mock(PromptService::class);
    $this->reviewPrompt = Mockery::mock(ReviewPrompt::class);
    $this->reviewService = Mockery::mock(ReviewServiceInterface::class);
});

describe('WorkAgentTask', function (): void {
    it('returns task ID from underlying task', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open']);

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
        );

        expect($agentTask->getTaskId())->toBe('f-abc123');
    });

    it('gets agent name using complexity-based routing', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open', 'complexity' => 'moderate']);

        $this->configService->shouldReceive('getAgentForComplexity')
            ->with('moderate')
            ->once()
            ->andReturn('medium-agent');

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
        );

        expect($agentTask->getAgentName($this->configService))->toBe('medium-agent');
    });

    it('defaults to simple complexity when not set', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open']);

        $this->configService->shouldReceive('getAgentForComplexity')
            ->with('simple')
            ->once()
            ->andReturn('simple-agent');

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
        );

        expect($agentTask->getAgentName($this->configService))->toBe('simple-agent');
    });

    it('uses agent override when provided', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open', 'complexity' => 'moderate']);

        // ConfigService should NOT be called when agent override is set
        $this->configService->shouldNotReceive('getAgentForComplexity');

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
            reviewService: null,
            reviewEnabled: false,
            agentOverride: 'sonnet',
        );

        expect($agentTask->getAgentName($this->configService))->toBe('sonnet');
    });

    it('uses complexity routing when agent override is null', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open', 'complexity' => 'moderate']);

        $this->configService->shouldReceive('getAgentForComplexity')
            ->with('moderate')
            ->once()
            ->andReturn('medium-agent');

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
            reviewService: null,
            reviewEnabled: false,
        );

        expect($agentTask->getAgentName($this->configService))->toBe('medium-agent');
    });

    it('builds prompt using TaskPromptBuilder', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open']);

        $this->promptBuilder->shouldReceive('build')
            ->with($task, '/test/cwd')
            ->once()
            ->andReturn('Generated prompt content');

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
        );

        expect($agentTask->buildPrompt('/test/cwd'))->toBe('Generated prompt content');
    });

    it('returns Task process type', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'open']);

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
        );

        expect($agentTask->getProcessType())->toBe(ProcessType::Task);
    });

    it('triggers review on success when review is enabled', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'in_progress']);

        $this->taskService->shouldReceive('find')
            ->with('f-abc123')
            ->once()
            ->andReturn($task);

        $this->reviewService->shouldReceive('triggerReview')
            ->with('f-abc123', 'test-agent')
            ->once()
            ->andReturn(true);

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
            $this->reviewService,
            reviewEnabled: true,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'test-agent',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Success,
        );

        $agentTask->onSuccess($completion);
    });

    it('auto-completes when review is disabled', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'in_progress']);

        $this->taskService->shouldReceive('find')
            ->with('f-abc123')
            ->once()
            ->andReturn($task);

        $this->taskService->shouldReceive('done')
            ->with('f-abc123', 'Auto-completed by consume (review skipped)')
            ->once();

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
            reviewEnabled: false,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'test-agent',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Success,
        );

        $agentTask->onSuccess($completion);
    });

    it('calls epic completion callback when set', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'in_progress']);
        $callbackCalled = false;
        $callbackTaskId = null;

        $this->taskService->shouldReceive('find')
            ->with('f-abc123')
            ->once()
            ->andReturn($task);

        $this->taskService->shouldReceive('done')
            ->once();

        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
            reviewEnabled: false,
        );

        $agentTask->setEpicCompletionCallback(function (string $taskId) use (&$callbackCalled, &$callbackTaskId): void {
            $callbackCalled = true;
            $callbackTaskId = $taskId;
        });

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'test-agent',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Success,
        );

        $agentTask->onSuccess($completion);

        expect($callbackCalled)->toBeTrue();
        expect($callbackTaskId)->toBe('f-abc123');
    });
});

describe('ReviewAgentTask', function (): void {
    it('returns prefixed task ID for reviews', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'review']);

        $agentTask = new ReviewAgentTask(
            $task,
            $this->taskService,
            $this->reviewPrompt,
            gitDiff: '',
            gitStatus: '',
        );

        expect($agentTask->getTaskId())->toBe('review-f-abc123');
        expect($agentTask->getOriginalTaskId())->toBe('f-abc123');
    });

    it('gets review agent from config', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'review']);

        $this->configService->shouldReceive('getReviewAgent')
            ->once()
            ->andReturn('review-agent');

        $agentTask = new ReviewAgentTask(
            $task,
            $this->taskService,
            $this->reviewPrompt,
            gitDiff: '',
            gitStatus: '',
        );

        expect($agentTask->getAgentName($this->configService))->toBe('review-agent');
    });

    it('returns null when no review agent configured', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'review']);

        $this->configService->shouldReceive('getReviewAgent')
            ->once()
            ->andReturn(null);

        $agentTask = new ReviewAgentTask(
            $task,
            $this->taskService,
            $this->reviewPrompt,
            gitDiff: '',
            gitStatus: '',
        );

        expect($agentTask->getAgentName($this->configService))->toBeNull();
    });

    it('builds prompt using ReviewPrompt', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'review']);
        $gitDiff = 'diff content';
        $gitStatus = 'status content';

        $this->reviewPrompt->shouldReceive('generate')
            ->with($task, $gitDiff, $gitStatus)
            ->once()
            ->andReturn('Generated review prompt');

        $agentTask = new ReviewAgentTask(
            $task,
            $this->taskService,
            $this->reviewPrompt,
            gitDiff: $gitDiff,
            gitStatus: $gitStatus,
        );

        expect($agentTask->buildPrompt('/test/cwd'))->toBe('Generated review prompt');
    });

    it('returns Review process type', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'review']);

        $agentTask = new ReviewAgentTask(
            $task,
            $this->taskService,
            $this->reviewPrompt,
            gitDiff: '',
            gitStatus: '',
        );

        expect($agentTask->getProcessType())->toBe(ProcessType::Review);
    });

    it('reopens task when review fails', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => TaskStatus::Review]);

        $this->taskService->shouldReceive('find')
            ->with('f-abc123')
            ->once()
            ->andReturn($task);

        $this->taskService->shouldReceive('reopen')
            ->with('f-abc123')
            ->once();

        $agentTask = new ReviewAgentTask(
            $task,
            $this->taskService,
            $this->reviewPrompt,
            gitDiff: '',
            gitStatus: '',
        );

        $completion = new CompletionResult(
            taskId: 'review-f-abc123',
            agentName: 'review-agent',
            exitCode: 1,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Failed,
        );

        $agentTask->onFailure($completion);
    });
});

describe('UpdateRealityAgentTask', function (): void {
    it('returns task ID for solo task', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        expect($agentTask->getTaskId())->toBe('f-abc123');
    });

    it('returns task ID for epic', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);
        $epic = new Epic(['short_id' => 'e-xyz789', 'title' => 'Test Epic', 'description' => 'Epic desc']);

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
            $epic,
        );

        expect($agentTask->getTaskId())->toBe('f-abc123');
    });

    it('gets reality agent from config', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $this->configService->shouldReceive('getRealityAgent')
            ->once()
            ->andReturn('reality-agent');

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        expect($agentTask->getAgentName($this->configService))->toBe('reality-agent');
    });

    it('falls back to primary when reality agent not configured', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $this->configService->shouldReceive('getRealityAgent')
            ->once()
            ->andReturn('primary-agent');

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        expect($agentTask->getAgentName($this->configService))->toBe('primary-agent');
    });

    it('builds prompt for solo task', function (): void {
        $task = new Task([
            'short_id' => 'f-abc123',
            'title' => 'Add new feature',
            'type' => 'feature',
            'description' => 'Implement feature X',
            'status' => 'done',
        ]);

        $this->promptService->shouldReceive('loadTemplate')
            ->with('reality')
            ->once()
            ->andReturn('UPDATE REALITY INDEX {{ context.reality_path }} {{ context.completed_work }}');

        $this->promptService->shouldReceive('render')
            ->once()
            ->andReturnUsing(fn ($template, $vars) => str_replace(
                ['{{ context.reality_path }}', '{{ context.completed_work }}'],
                [$vars['context']['reality_path'], $vars['context']['completed_work']],
                $template
            ));

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        $prompt = $agentTask->buildPrompt('/test/cwd');

        expect($prompt)->toContain('UPDATE REALITY INDEX');
        expect($prompt)->toContain('/test/cwd/.fuel/reality.md');
        expect($prompt)->toContain('Add new feature');
        expect($prompt)->toContain('f-abc123');
        expect($prompt)->toContain('feature');
        expect($prompt)->toContain('Implement feature X');
    });

    it('returns Task process type', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        expect($agentTask->getProcessType())->toBe(ProcessType::Task);
    });

    it('creates from task using static factory', function (): void {
        // Bind mocks to container
        app()->instance(TaskService::class, $this->taskService);
        app()->instance(PromptService::class, $this->promptService);

        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $this->taskService->shouldReceive('create')
            ->once()
            ->andReturn(new Task(['short_id' => 'f-def456', 'title' => 'Update reality: Test Task', 'type' => 'reality', 'status' => 'in_progress']));

        $agentTask = UpdateRealityAgentTask::fromTask($task, '/test/cwd');

        expect($agentTask->getTaskId())->toBe('f-def456');
        expect($agentTask->getTask()->type)->toBe('reality');
    });

    it('creates from epic using static factory', function (): void {
        // Bind mocks to container
        app()->instance(TaskService::class, $this->taskService);
        app()->instance(PromptService::class, $this->promptService);

        $epic = new Epic(['short_id' => 'e-xyz789', 'title' => 'Test Epic', 'description' => 'Epic desc']);

        $this->taskService->shouldReceive('create')
            ->once()
            ->andReturn(new Task(['short_id' => 'f-ghi789', 'title' => 'Update reality: Test Epic', 'type' => 'reality', 'status' => 'in_progress']));

        $agentTask = UpdateRealityAgentTask::fromEpic($epic, '/test/cwd');

        expect($agentTask->getTaskId())->toBe('f-ghi789');
        expect($agentTask->getTask()->type)->toBe('reality');
    });

    it('onSuccess is fire-and-forget (no exception)', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $this->taskService->shouldReceive('done')
            ->with('f-abc123', 'Auto-completed reality update')
            ->once();

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'reality-agent',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Success,
        );

        // Should not throw
        $agentTask->onSuccess($completion);
        expect(true)->toBeTrue();
    });

    it('onFailure is fire-and-forget (no exception)', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Test Task', 'status' => 'done']);

        $this->taskService->shouldReceive('delete')
            ->with('f-abc123')
            ->once();

        $agentTask = new UpdateRealityAgentTask(
            $task,
            $this->taskService,
            $this->promptService,
            '/test/cwd',
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'reality-agent',
            exitCode: 1,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Failed,
        );

        // Should not throw
        $agentTask->onFailure($completion);
        expect(true)->toBeTrue();
    });
});

describe('SelfGuidedAgentTask', function (): void {
    it('returns task ID from underlying task', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Self-guided Task', 'status' => 'open']);

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        expect($agentTask->getTaskId())->toBe('f-abc123');
    });

    it('always returns primary agent from config', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Self-guided Task', 'status' => 'open', 'complexity' => 'simple']);

        // Should use getPrimaryAgent(), not getAgentForComplexity()
        $this->configService->shouldNotReceive('getAgentForComplexity');
        $this->configService->shouldReceive('getPrimaryAgent')->once()->andReturn('claude-opus');

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        expect($agentTask->getAgentName($this->configService))->toBe('claude-opus');
    });

    it('returns Task process type', function (): void {
        $task = new Task(['short_id' => 'f-abc123', 'title' => 'Self-guided Task', 'status' => 'open']);

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        expect($agentTask->getProcessType())->toBe(ProcessType::Task);
    });

    it('increments iteration and resets stuck count on success, reopens if in_progress', function (): void {
        $task = new Task([
            'short_id' => 'f-abc123',
            'title' => 'Self-guided Task',
            'status' => 'in_progress',
            'selfguided_iteration' => 5,
            'selfguided_stuck_count' => 2,
        ]);

        $this->taskService->shouldReceive('update')
            ->with('f-abc123', [
                'selfguided_iteration' => 6,
                'selfguided_stuck_count' => 0,
            ])
            ->once();

        // onSuccess now finds the task to check status
        $this->taskService->shouldReceive('find')
            ->with('f-abc123')
            ->once()
            ->andReturn($task);

        // Task is in_progress, so reopen for next iteration
        $this->taskService->shouldReceive('reopen')
            ->with('f-abc123')
            ->once();

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'primary',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Success,
        );

        $agentTask->onSuccess($completion);
    });

    it('increments stuck count on failure and reopens for retry if under threshold', function (): void {
        $task = new Task([
            'short_id' => 'f-abc123',
            'title' => 'Self-guided Task',
            'status' => 'in_progress',
            'selfguided_iteration' => 3,
            'selfguided_stuck_count' => 1,
        ]);

        $this->taskService->shouldReceive('update')
            ->with('f-abc123', ['selfguided_stuck_count' => 2])
            ->once();

        // Stuck count is 2, under threshold of 3, so reopen for retry
        $this->taskService->shouldReceive('reopen')
            ->with('f-abc123')
            ->once();

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'primary',
            exitCode: 1,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Failed,
        );

        $agentTask->onFailure($completion);
    });

    it('creates needs-human task when stuck count reaches threshold', function (): void {
        $task = new Task([
            'short_id' => 'f-abc123',
            'title' => 'Self-guided Task',
            'status' => 'in_progress',
            'selfguided_iteration' => 3,
            'selfguided_stuck_count' => 2, // Will become 3, which triggers needs-human
            'epic_id' => 5,
        ]);

        $needsHumanTask = new Task([
            'short_id' => 'f-def456',
            'title' => 'Self-guided task stuck after 3 consecutive failures',
            'status' => 'open',
        ]);

        $this->taskService->shouldReceive('update')
            ->with('f-abc123', ['selfguided_stuck_count' => 3])
            ->once();

        $this->taskService->shouldReceive('create')
            ->with(Mockery::on(function ($data) {
                return $data['title'] === 'Self-guided task stuck after 3 consecutive failures'
                    && in_array('needs-human', $data['labels'], true)
                    && $data['epic_id'] === 5;
            }))
            ->once()
            ->andReturn($needsHumanTask);

        $this->taskService->shouldReceive('addDependency')
            ->with('f-abc123', 'f-def456')
            ->once();

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'primary',
            exitCode: 1,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Failed,
        );

        $agentTask->onFailure($completion);
    });

    it('handles null selfguided_iteration in onSuccess', function (): void {
        $task = new Task([
            'short_id' => 'f-abc123',
            'title' => 'Self-guided Task',
            'status' => 'in_progress',
            // selfguided_iteration not set (null)
        ]);

        $this->taskService->shouldReceive('update')
            ->with('f-abc123', [
                'selfguided_iteration' => 1,
                'selfguided_stuck_count' => 0,
            ])
            ->once();

        $this->taskService->shouldReceive('find')
            ->with('f-abc123')
            ->once()
            ->andReturn($task);

        $this->taskService->shouldReceive('reopen')
            ->with('f-abc123')
            ->once();

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'primary',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Success,
        );

        $agentTask->onSuccess($completion);
    });

    it('handles null selfguided_stuck_count in onFailure', function (): void {
        $task = new Task([
            'short_id' => 'f-abc123',
            'title' => 'Self-guided Task',
            'status' => 'in_progress',
            // selfguided_stuck_count not set (null)
        ]);

        $this->taskService->shouldReceive('update')
            ->with('f-abc123', ['selfguided_stuck_count' => 1])
            ->once();

        // Stuck count is 1, under threshold of 3, so reopen for retry
        $this->taskService->shouldReceive('reopen')
            ->with('f-abc123')
            ->once();

        $agentTask = new SelfGuidedAgentTask(
            $task,
            $this->taskService,
        );

        $completion = new CompletionResult(
            taskId: 'f-abc123',
            agentName: 'primary',
            exitCode: 1,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: '',
            type: CompletionType::Failed,
        );

        $agentTask->onFailure($completion);
    });
});
