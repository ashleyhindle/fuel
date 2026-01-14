<?php

declare(strict_types=1);

use App\Agents\Tasks\ReviewAgentTask;
use App\Agents\Tasks\WorkAgentTask;
use App\Contracts\ReviewServiceInterface;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\ProcessType;
use App\Prompts\ReviewPrompt;
use App\Services\ConfigService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;

beforeEach(function (): void {
    // Create mock services
    $this->taskService = Mockery::mock(TaskService::class);
    $this->configService = Mockery::mock(ConfigService::class);
    $this->promptBuilder = Mockery::mock(TaskPromptBuilder::class);
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
