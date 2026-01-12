<?php

declare(strict_types=1);

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Providers\AppServiceProvider;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-epic-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->context = new FuelContext($this->tempDir.'/.fuel');

    $this->db = new DatabaseService($this->context->getDatabasePath());
    $this->context->configureDatabase();
    Artisan::call('migrate', ['--force' => true]);

    // Configure Eloquent to use the test database
    AppServiceProvider::configureDatabasePath($this->context);

    $this->taskService = makeTaskService();
    $this->service = makeEpicService($this->taskService);
});

afterEach(function (): void {
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

it('creates an epic with title and description', function (): void {
    $epic = $this->service->createEpic('My Epic', 'Epic description');

    expect($epic)->toBeInstanceOf(Epic::class);
    expect($epic->short_id)->toStartWith('e-');
    expect(strlen((string) $epic->short_id))->toBe(8);
    expect($epic->title)->toBe('My Epic');
    expect($epic->description)->toBe('Epic description');
    expect($epic->status)->toBe(EpicStatus::Planning);
    expect($epic->created_at)->not->toBeNull();
});

it('creates an epic with title only', function (): void {
    $epic = $this->service->createEpic('Title Only Epic');

    expect($epic)->toBeInstanceOf(Epic::class);
    expect($epic->title)->toBe('Title Only Epic');
    expect($epic->description)->toBeNull();
});

it('gets an epic by ID', function (): void {
    $created = $this->service->createEpic('Test Epic', 'Description');

    $epic = $this->service->getEpic($created->short_id);

    expect($epic)->not->toBeNull();
    expect($epic)->toBeInstanceOf(Epic::class);
    expect($epic->short_id)->toBe($created->short_id);
    expect($epic->title)->toBe('Test Epic');
});

it('returns null for non-existent epic', function (): void {
    $epic = $this->service->getEpic('e-000000');

    expect($epic)->toBeNull();
});

it('gets all epics', function (): void {
    $this->service->createEpic('Epic 1');
    $this->service->createEpic('Epic 2');
    $this->service->createEpic('Epic 3');

    $epics = $this->service->getAllEpics();

    expect($epics)->toHaveCount(3);
    expect($epics[0])->toBeInstanceOf(Epic::class);
});

it('returns empty array when no epics exist', function (): void {

    $epics = $this->service->getAllEpics();

    expect($epics)->toBe([]);
});

it('updates epic title', function (): void {
    $created = $this->service->createEpic('Original Title', 'Description');

    $updated = $this->service->updateEpic($created->short_id, ['title' => 'New Title']);

    expect($updated)->toBeInstanceOf(Epic::class);
    expect($updated->title)->toBe('New Title');
    expect($updated->description)->toBe('Description');

    $fetched = $this->service->getEpic($created->short_id);
    expect($fetched->title)->toBe('New Title');
});

it('updates epic description', function (): void {
    $created = $this->service->createEpic('Title', 'Old Description');

    $updated = $this->service->updateEpic($created->short_id, ['description' => 'New Description']);

    expect($updated)->toBeInstanceOf(Epic::class);
    expect($updated->description)->toBe('New Description');
});

it('clears epic description with null', function (): void {
    $created = $this->service->createEpic('Title', 'Has Description');

    $updated = $this->service->updateEpic($created->short_id, ['description' => null]);

    expect($updated)->toBeInstanceOf(Epic::class);
    expect($updated->description)->toBeNull();
});

it('computes epic status as planning when no tasks', function (): void {
    $created = $this->service->createEpic('Title');

    $epic = $this->service->getEpic($created->short_id);

    expect($epic->status)->toBe(EpicStatus::Planning);
});

it('computes epic status as in_progress when task is open', function (): void {
    $epic = $this->service->createEpic('Title');
    $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::InProgress);
});

it('computes epic status as in_progress when task is in_progress', function (): void {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);
    $this->taskService->start($task->short_id);

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::InProgress);
});

it('computes epic status as review_pending when all tasks are done', function (): void {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::ReviewPending);
});

it('computes epic status as reviewed when reviewed_at is set', function (): void {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    // Initially should be review_pending
    $epic = $this->service->getEpic($epic->short_id);
    expect($epic->status)->toBe(EpicStatus::ReviewPending);

    // Set reviewed_at directly in DB
    $this->db->query(
        'UPDATE epics SET reviewed_at = ? WHERE short_id = ?',
        [Carbon::now('UTC')->toIso8601String(), $epic->short_id]
    );

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::Reviewed);
});

it('ignores status updates since status is computed', function (): void {
    $created = $this->service->createEpic('Title');

    // Status updates are ignored - status is purely computed
    $updated = $this->service->updateEpic($created->short_id, ['status' => 'invalid']);

    // Status should still be computed correctly, not set to 'invalid'
    expect($updated->status)->toBe(EpicStatus::Planning);
});

it('throws exception when updating non-existent epic', function (): void {

    $this->service->updateEpic('e-000000', ['title' => 'New']);
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('deletes an epic', function (): void {
    $created = $this->service->createEpic('To Delete');

    $deleted = $this->service->deleteEpic($created->short_id);

    expect($deleted)->toBeInstanceOf(Epic::class);
    expect($deleted->short_id)->toBe($created->short_id);
    expect($this->service->getEpic($created->short_id))->toBeNull();
});

it('throws exception when deleting non-existent epic', function (): void {

    $this->service->deleteEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('gets tasks for epic', function (): void {
    $epic = $this->service->createEpic('Test Epic');

    $tasks = $this->service->getTasksForEpic($epic->short_id);

    expect($tasks)->toBe([]);
});

it('gets tasks for epic when tasks are linked', function (): void {
    $epic = $this->service->createEpic('Epic with tasks');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic->short_id,
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic->short_id,
    ]);
    // Create a task not linked to epic
    $task3 = $this->taskService->create([
        'title' => 'Task 3',
    ]);

    $tasks = $this->service->getTasksForEpic($epic->short_id);

    expect($tasks)->toHaveCount(2);
    $taskIds = array_column($tasks, 'short_id');
    expect($taskIds)->toContain($task1->short_id);
    expect($taskIds)->toContain($task2->short_id);
    expect($taskIds)->not->toContain($task3->short_id);
});

it('throws exception when getting tasks for non-existent epic', function (): void {

    $this->service->getTasksForEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('supports partial ID matching', function (): void {
    $created = $this->service->createEpic('Test Epic');
    $partialId = substr((string) $created->short_id, 2, 4);

    $epic = $this->service->getEpic($partialId);

    expect($epic)->not->toBeNull();
    expect($epic)->toBeInstanceOf(Epic::class);
    expect($epic->short_id)->toBe($created->short_id);
});

it('throws exception for ambiguous partial ID', function (): void {
    $this->service->createEpic('Epic 1');
    $this->service->createEpic('Epic 2');

    $this->service->getEpic('e-');
})->throws(RuntimeException::class, 'Ambiguous epic ID');

it('generates unique IDs in e-xxxxxx format', function (): void {
    $epic1 = $this->service->createEpic('Epic 1');
    $epic2 = $this->service->createEpic('Epic 2');

    expect($epic1->short_id)->toMatch('/^e-[a-f0-9]{6}$/');
    expect($epic2->short_id)->toMatch('/^e-[a-f0-9]{6}$/');
    expect($epic1->short_id)->not->toBe($epic2->short_id);
});

it('returns not completed when epic has no tasks', function (): void {
    $epic = $this->service->createEpic('Empty Epic');

    $result = $this->service->checkEpicCompletion($epic->short_id);

    expect($result)->toBe(['completed' => false]);
});

it('returns not completed when epic has open tasks', function (): void {
    $epic = $this->service->createEpic('Epic with open tasks');

    $this->taskService->create([
        'title' => 'Open task',
        'epic_id' => $epic->short_id,
    ]);

    $result = $this->service->checkEpicCompletion($epic->short_id);

    expect($result)->toBe(['completed' => false]);
});

it('returns not completed when epic has in_progress tasks', function (): void {
    $epic = $this->service->createEpic('Epic with in_progress tasks');

    $task = $this->taskService->create([
        'title' => 'In progress task',
        'epic_id' => $epic->short_id,
    ]);
    $this->taskService->start($task->short_id);

    $result = $this->service->checkEpicCompletion($epic->short_id);

    expect($result)->toBe(['completed' => false]);
});

it('triggers completion when all tasks are done', function (): void {
    $epic = $this->service->createEpic('Epic with all done tasks', 'Test epic description');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic->short_id,
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic->short_id,
    ]);

    $this->taskService->done($task1->short_id);
    $this->taskService->done($task2->short_id);

    $result = $this->service->checkEpicCompletion($epic->short_id);

    expect($result['completed'])->toBeTrue();
    $updatedEpic = $this->service->getEpic($epic->short_id);
    expect($updatedEpic->status)->toBe(EpicStatus::ReviewPending);
});

it('triggers completion when tasks are done or cancelled', function (): void {
    $epic = $this->service->createEpic('Epic with mixed done/cancelled');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic->short_id,
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic->short_id,
    ]);

    $this->taskService->done($task1->short_id);
    $this->taskService->update($task2->short_id, ['status' => 'cancelled']);

    $result = $this->service->checkEpicCompletion($epic->short_id);

    expect($result['completed'])->toBeTrue();
});

it('returns false for non-existent epic', function (): void {
    $result = $this->service->checkEpicCompletion('e-000000');

    expect($result)->toBe(['completed' => false]);
});

it('does not create review tasks when checkEpicCompletion called multiple times', function (): void {
    $epic = $this->service->createEpic('Epic for idempotency test');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic->short_id,
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic->short_id,
    ]);

    $this->taskService->done($task1->short_id);
    $this->taskService->done($task2->short_id);

    // First call marks completion
    $result1 = $this->service->checkEpicCompletion($epic->short_id);
    expect($result1['completed'])->toBeTrue();

    // Second call should remain completed without creating tasks
    $result2 = $this->service->checkEpicCompletion($epic->short_id);
    expect($result2['completed'])->toBeTrue();

    // Verify no review tasks exist
    $allTasks = $this->taskService->all();
    $reviewTasks = $allTasks->filter(fn (Task $task): bool => in_array('epic-review', $task->labels ?? [], true));
    expect($reviewTasks->count())->toBe(0);
});

it('approves an epic', function (): void {
    $epic = $this->service->createEpic('Epic to approve');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    $approved = $this->service->approveEpic($epic->short_id, 'test-user');

    expect($approved)->toBeInstanceOf(Epic::class);
    expect($approved->approved_at)->not->toBeNull();
    expect($approved->approved_by)->toBe('test-user');
    expect($approved->status)->toBe(EpicStatus::Approved);
});

it('approves an epic with default approved_by', function (): void {
    $epic = $this->service->createEpic('Epic to approve');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    $approved = $this->service->approveEpic($epic->short_id);

    expect($approved)->toBeInstanceOf(Epic::class);
    expect($approved->approved_at)->not->toBeNull();
    expect($approved->approved_by)->toBe('human');
    expect($approved->status)->toBe(EpicStatus::Approved);
});

it('rejects an epic and reopens tasks', function (): void {
    $epic = $this->service->createEpic('Epic to reject');
    $task1 = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);
    $task2 = $this->taskService->create(['title' => 'Task 2', 'epic_id' => $epic->short_id]);

    // Close tasks
    $this->taskService->done($task1->short_id);
    $this->taskService->done($task2->short_id);

    // Reject epic
    $rejected = $this->service->rejectEpic($epic->short_id, 'Needs more work');

    expect($rejected)->toBeInstanceOf(Epic::class);
    expect($rejected->changes_requested_at)->not->toBeNull();
    expect($rejected->approved_at)->toBeNull();
    expect($rejected->approved_by)->toBeNull();
    expect($rejected->status)->toBe(EpicStatus::InProgress); // Tasks reopened, so in_progress

    // Verify tasks were reopened
    $task1Updated = $this->taskService->find($task1->short_id);
    $task2Updated = $this->taskService->find($task2->short_id);
    expect($task1Updated->status)->toBe(TaskStatus::Open);
    expect($task2Updated->status)->toBe(TaskStatus::Open);
});

it('shows changes_requested status when epic rejected but tasks not yet reopened', function (): void {
    $epic = $this->service->createEpic('Epic to reject');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    // Reject epic
    $rejected = $this->service->rejectEpic($epic->short_id);

    // Status should be in_progress because rejectEpic reopens tasks
    expect($rejected->status)->toBe(EpicStatus::InProgress);
});

it('throws exception when approving non-existent epic', function (): void {
    $this->service->approveEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('throws exception when rejecting non-existent epic', function (): void {
    $this->service->rejectEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");
