<?php

declare(strict_types=1);

use App\Agents\Tasks\MergeEpicAgentTask;
use App\Enums\MirrorStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\PromptService;
use App\Services\TaskService;

beforeEach(function (): void {
    $this->taskService = Mockery::mock(TaskService::class);
    $this->epicService = Mockery::mock(EpicService::class);
    $this->promptService = Mockery::mock(PromptService::class);
    $this->configService = Mockery::mock(ConfigService::class);

    $this->epic = new Epic;
    $this->epic->id = 1;
    $this->epic->title = 'Test Epic';
    $this->epic->description = 'Test epic description';
    $this->epic->short_id = 'e-abc123';
    $this->epic->mirror_path = '/home/user/.fuel/mirrors/project/e-abc123';
    $this->epic->mirror_branch = 'epic/e-abc123';
    $this->epic->mirror_base_commit = 'abcdef123456';

    $this->task = new Task;
    $this->task->id = 1;
    $this->task->short_id = 'f-123456';
    $this->task->title = 'Merge epic/e-abc123 into main';
    $this->task->type = 'merge';
    $this->task->status = TaskStatus::InProgress;
    $this->task->epic_id = 1;
});

afterEach(function (): void {
    Mockery::close();
});

test('fromEpic creates merge task correctly', function (): void {
    $epic = new Epic;
    $epic->id = 1;
    $epic->title = 'Feature Implementation';
    $epic->short_id = 'e-def456';

    $createdTask = new Task;
    $createdTask->id = 2;
    $createdTask->short_id = 'f-789abc';
    $createdTask->title = 'Merge epic/e-def456 into main';
    $createdTask->type = 'merge';
    $createdTask->status = TaskStatus::Open;

    $mockTaskService = Mockery::mock(TaskService::class);
    $mockTaskService->shouldReceive('create')
        ->once()
        ->with([
            'title' => 'Merge epic/e-def456 into main',
            'description' => 'Merge epic "Feature Implementation" from mirror branch into main project',
            'type' => 'merge',
            'status' => TaskStatus::Open->value,
            'epic_id' => 1,
        ])
        ->andReturn($createdTask);

    app()->instance(TaskService::class, $mockTaskService);
    app()->instance(EpicService::class, Mockery::mock(EpicService::class));
    app()->instance(PromptService::class, Mockery::mock(PromptService::class));

    $mergeTask = MergeEpicAgentTask::fromEpic($epic);

    expect($mergeTask)->toBeInstanceOf(MergeEpicAgentTask::class);
    expect($mergeTask->getTaskId())->toBe('f-789abc');
});

test('fromTaskModel creates MergeEpicAgentTask from existing task', function (): void {
    // Create a mock epic
    $epic = new Epic;
    $epic->id = 1;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-abc123';
    $epic->mirror_path = '/path/to/mirror';
    $epic->mirror_branch = 'epic/e-abc123';
    $epic->mirror_base_commit = 'abc123';

    // Mock Epic::find to return our epic
    Epic::unguard();
    $epic->save();

    $task = new Task;
    $task->id = 99;
    $task->short_id = 'f-merge1';
    $task->title = 'Merge epic/e-abc123 into main';
    $task->type = 'chore';
    $task->agent = 'merge';
    $task->status = TaskStatus::Open;
    $task->epic_id = $epic->id;

    $mergeTask = MergeEpicAgentTask::fromTaskModel($task);

    expect($mergeTask)->toBeInstanceOf(MergeEpicAgentTask::class);
    expect($mergeTask->getTaskId())->toBe('f-merge1');
});

test('fromTaskModel throws when task has no epic_id', function (): void {
    $task = new Task;
    $task->id = 99;
    $task->short_id = 'f-merge1';
    $task->epic_id = null;

    MergeEpicAgentTask::fromTaskModel($task);
})->throws(RuntimeException::class, 'Merge task must have an epic_id');

test('getAgentName returns primary agent', function (): void {
    $this->configService->shouldReceive('getPrimaryAgent')
        ->once()
        ->andReturn('claude');

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $agentName = $mergeTask->getAgentName($this->configService);

    expect($agentName)->toBe('claude');
});

test('getProcessType returns Task', function (): void {
    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    expect($mergeTask->getProcessType())->toBe(ProcessType::Task);
});

test('buildPrompt constructs merge prompt with variables', function (): void {
    $template = 'Merge {{epic.id}} from {{mirror.path}} to {{project.path}}';

    $this->promptService->shouldReceive('loadTemplate')
        ->once()
        ->with('merge')
        ->andReturn($template);

    $this->promptService->shouldReceive('render')
        ->once()
        ->with($template, Mockery::on(function ($vars): bool {
            return $vars['epic']['id'] === 'e-abc123'
                && $vars['epic']['title'] === 'Test Epic'
                && $vars['epic']['plan_file'] === 'test-epic-e-abc123.md'
                && $vars['mirror']['path'] === '/home/user/.fuel/mirrors/project/e-abc123'
                && $vars['mirror']['branch'] === 'epic/e-abc123'
                && $vars['mirror']['base_commit'] === 'abcdef123456'
                && $vars['project']['path'] === '/project/path'
                && str_contains($vars['quality_gates'], 'Pest');
        }))
        ->andReturn('Rendered prompt');

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $prompt = $mergeTask->buildPrompt('/project/path');

    expect($prompt)->toBe('Rendered prompt');
});

test('buildPrompt uses default quality gates when reality.md missing', function (): void {
    $template = '{{quality_gates}}';

    $this->promptService->shouldReceive('loadTemplate')
        ->once()
        ->with('merge')
        ->andReturn($template);

    $this->promptService->shouldReceive('render')
        ->once()
        ->with($template, Mockery::on(function ($vars): bool {
            return str_contains($vars['quality_gates'], 'Pest')
                && str_contains($vars['quality_gates'], 'Pint');
        }))
        ->andReturn('Rendered with defaults');

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $prompt = $mergeTask->buildPrompt('/nonexistent/path');

    expect($prompt)->toBe('Rendered with defaults');
});

test('onSuccess cleans up mirror and marks task done', function (): void {
    $this->epicService->shouldReceive('cleanupMirror')
        ->once()
        ->with($this->epic);

    $this->taskService->shouldReceive('done')
        ->once()
        ->with('f-123456', 'Successfully merged epic');

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $result = new CompletionResult(
        taskId: 'f-123456',
        agentName: 'claude',
        exitCode: 0,
        duration: 120,
        sessionId: 'session-123',
        costUsd: 0.5,
        output: 'Merge completed',
        type: \App\Process\CompletionType::Success,
        message: 'Success',
        processType: ProcessType::Task
    );

    $mergeTask->onSuccess($result);
});

test('onSuccess handles cleanup exceptions gracefully', function (): void {
    $this->epicService->shouldReceive('cleanupMirror')
        ->once()
        ->with($this->epic)
        ->andThrow(new RuntimeException('Cleanup failed'));

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $result = new CompletionResult(
        taskId: 'f-123456',
        agentName: 'claude',
        exitCode: 0,
        duration: 120,
        sessionId: 'session-123',
        costUsd: 0.5,
        output: 'Merge completed',
        type: \App\Process\CompletionType::Success,
        message: 'Success',
        processType: ProcessType::Task
    );

    // Should not throw
    $mergeTask->onSuccess($result);
});

test('onFailure pauses epic and updates mirror status', function (): void {
    $this->epicService->shouldReceive('pause')
        ->once()
        ->with('e-abc123', 'Merge failed - needs human attention');

    $this->epicService->shouldReceive('updateMirrorStatus')
        ->once()
        ->with($this->epic, MirrorStatus::MergeFailed);

    $this->taskService->shouldReceive('delete')
        ->once()
        ->with('f-123456');

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $result = new CompletionResult(
        taskId: 'f-123456',
        agentName: 'claude',
        exitCode: 1,
        duration: 120,
        sessionId: 'session-123',
        costUsd: 0.5,
        output: 'Merge failed',
        type: \App\Process\CompletionType::Failed,
        message: 'Merge conflicts',
        processType: ProcessType::Task
    );

    $mergeTask->onFailure($result);
});

test('onFailure handles exceptions gracefully', function (): void {
    $this->epicService->shouldReceive('pause')
        ->once()
        ->with('e-abc123', 'Merge failed - needs human attention')
        ->andThrow(new RuntimeException('Pause failed'));

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $result = new CompletionResult(
        taskId: 'f-123456',
        agentName: 'claude',
        exitCode: 1,
        duration: 120,
        sessionId: 'session-123',
        costUsd: 0.5,
        output: 'Merge failed',
        type: \App\Process\CompletionType::Failed,
        message: 'Merge conflicts',
        processType: ProcessType::Task
    );

    // Should not throw
    $mergeTask->onFailure($result);
});

test('extracts quality gates from reality.md correctly', function (): void {
    $realityContent = <<<'REALITY'
# Reality

## Quality Gates
| Tool | Command | Purpose |
|------|---------|---------|
| Pest | `./vendor/bin/pest --parallel --compact` | Test runner |
| Pint | `./vendor/bin/pint` | Code formatter |
| Rector | `./vendor/bin/rector` | Auto-refactoring |

## Other Section
More content here
REALITY;

    // Create a temporary directory and file
    $tempDir = sys_get_temp_dir().'/fuel_test_'.uniqid();
    mkdir($tempDir);
    mkdir($tempDir.'/.fuel');
    file_put_contents($tempDir.'/.fuel/reality.md', $realityContent);

    $template = '{{quality_gates}}';

    $this->promptService->shouldReceive('loadTemplate')
        ->once()
        ->with('merge')
        ->andReturn($template);

    $this->promptService->shouldReceive('render')
        ->once()
        ->with($template, Mockery::on(function ($vars): bool {
            $gates = $vars['quality_gates'];

            return str_contains($gates, 'Pest - Test runner')
                && str_contains($gates, './vendor/bin/pest --parallel --compact')
                && str_contains($gates, 'Pint - Code formatter')
                && str_contains($gates, './vendor/bin/pint')
                && str_contains($gates, 'Rector - Auto-refactoring')
                && str_contains($gates, './vendor/bin/rector');
        }))
        ->andReturn('Extracted gates');

    $mergeTask = new MergeEpicAgentTask(
        $this->task,
        $this->taskService,
        $this->epic,
        $this->epicService,
        $this->promptService
    );

    $prompt = $mergeTask->buildPrompt($tempDir);

    expect($prompt)->toBe('Extracted gates');

    // Cleanup
    unlink($tempDir.'/.fuel/reality.md');
    rmdir($tempDir.'/.fuel');
    rmdir($tempDir);
});
