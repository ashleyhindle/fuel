<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-epic-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->context = new FuelContext($this->tempDir.'/.fuel');

    $this->db = new DatabaseService($this->context->getDatabasePath());
    $this->taskService = new TaskService($this->db);
    $this->service = new EpicService($this->db, $this->taskService);
});

afterEach(function () {
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
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

it('creates an epic with title and description', function () {
    $epic = $this->service->createEpic('My Epic', 'Epic description');

    expect($epic['id'])->toStartWith('e-');
    expect(strlen($epic['id']))->toBe(8);
    expect($epic['title'])->toBe('My Epic');
    expect($epic['description'])->toBe('Epic description');
    expect($epic['status'])->toBe('planning');
    expect($epic['created_at'])->not->toBeNull();
});

it('creates an epic with title only', function () {
    $epic = $this->service->createEpic('Title Only Epic');

    expect($epic['title'])->toBe('Title Only Epic');
    expect($epic['description'])->toBeNull();
});

it('gets an epic by ID', function () {
    $created = $this->service->createEpic('Test Epic', 'Description');

    $epic = $this->service->getEpic($created['id']);

    expect($epic)->not->toBeNull();
    expect($epic['id'])->toBe($created['id']);
    expect($epic['title'])->toBe('Test Epic');
});

it('returns null for non-existent epic', function () {
    $epic = $this->service->getEpic('e-000000');

    expect($epic)->toBeNull();
});

it('gets all epics', function () {
    $this->service->createEpic('Epic 1');
    $this->service->createEpic('Epic 2');
    $this->service->createEpic('Epic 3');

    $epics = $this->service->getAllEpics();

    expect($epics)->toHaveCount(3);
});

it('returns empty array when no epics exist', function () {
    $this->db->initialize();

    $epics = $this->service->getAllEpics();

    expect($epics)->toBe([]);
});

it('updates epic title', function () {
    $created = $this->service->createEpic('Original Title', 'Description');

    $updated = $this->service->updateEpic($created['id'], ['title' => 'New Title']);

    expect($updated['title'])->toBe('New Title');
    expect($updated['description'])->toBe('Description');

    $fetched = $this->service->getEpic($created['id']);
    expect($fetched['title'])->toBe('New Title');
});

it('updates epic description', function () {
    $created = $this->service->createEpic('Title', 'Old Description');

    $updated = $this->service->updateEpic($created['id'], ['description' => 'New Description']);

    expect($updated['description'])->toBe('New Description');
});

it('clears epic description with null', function () {
    $created = $this->service->createEpic('Title', 'Has Description');

    $updated = $this->service->updateEpic($created['id'], ['description' => null]);

    expect($updated['description'])->toBeNull();
});

it('computes epic status as planning when no tasks', function () {
    $created = $this->service->createEpic('Title');

    $epic = $this->service->getEpic($created['id']);

    expect($epic['status'])->toBe('planning');
});

it('computes epic status as in_progress when task is open', function () {
    $epic = $this->service->createEpic('Title');
    $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic['id']]);

    $epic = $this->service->getEpic($epic['id']);

    expect($epic['status'])->toBe('in_progress');
});

it('computes epic status as in_progress when task is in_progress', function () {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic['id']]);
    $this->taskService->start($task['id']);

    $epic = $this->service->getEpic($epic['id']);

    expect($epic['status'])->toBe('in_progress');
});

it('computes epic status as review_pending when all tasks are closed', function () {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic['id']]);
    $this->taskService->done($task['id']);

    $epic = $this->service->getEpic($epic['id']);

    expect($epic['status'])->toBe('review_pending');
});

it('computes epic status as reviewed when reviewed_at is set', function () {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic['id']]);
    $this->taskService->done($task['id']);

    // Initially should be review_pending
    $epic = $this->service->getEpic($epic['id']);
    expect($epic['status'])->toBe('review_pending');

    // Set reviewed_at directly in DB
    $this->db->initialize();
    $this->db->query(
        'UPDATE epics SET reviewed_at = ? WHERE short_id = ?',
        [\Carbon\Carbon::now('UTC')->toIso8601String(), $epic['id']]
    );

    $epic = $this->service->getEpic($epic['id']);

    expect($epic['status'])->toBe('reviewed');
});

it('ignores status updates since status is computed', function () {
    $created = $this->service->createEpic('Title');

    // Status updates are ignored - status is purely computed
    $updated = $this->service->updateEpic($created['id'], ['status' => 'invalid']);

    // Status should still be computed correctly, not set to 'invalid'
    expect($updated['status'])->toBe('planning');
});

it('throws exception when updating non-existent epic', function () {
    $this->db->initialize();

    $this->service->updateEpic('e-000000', ['title' => 'New']);
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('deletes an epic', function () {
    $created = $this->service->createEpic('To Delete');

    $deleted = $this->service->deleteEpic($created['id']);

    expect($deleted['id'])->toBe($created['id']);
    expect($this->service->getEpic($created['id']))->toBeNull();
});

it('throws exception when deleting non-existent epic', function () {
    $this->db->initialize();

    $this->service->deleteEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('gets tasks for epic', function () {
    $epic = $this->service->createEpic('Test Epic');

    $tasks = $this->service->getTasksForEpic($epic['id']);

    expect($tasks)->toBe([]);
});

it('gets tasks for epic when tasks are linked', function () {
    $epic = $this->service->createEpic('Epic with tasks');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic['id'],
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic['id'],
    ]);
    // Create a task not linked to epic
    $task3 = $this->taskService->create([
        'title' => 'Task 3',
    ]);

    $tasks = $this->service->getTasksForEpic($epic['id']);

    expect($tasks)->toHaveCount(2);
    $taskIds = array_column($tasks, 'id');
    expect($taskIds)->toContain($task1['id']);
    expect($taskIds)->toContain($task2['id']);
    expect($taskIds)->not->toContain($task3['id']);
});

it('throws exception when getting tasks for non-existent epic', function () {
    $this->db->initialize();

    $this->service->getTasksForEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('supports partial ID matching', function () {
    $created = $this->service->createEpic('Test Epic');
    $partialId = substr($created['id'], 2, 4);

    $epic = $this->service->getEpic($partialId);

    expect($epic)->not->toBeNull();
    expect($epic['id'])->toBe($created['id']);
});

it('throws exception for ambiguous partial ID', function () {
    $this->service->createEpic('Epic 1');
    $this->service->createEpic('Epic 2');

    $this->service->getEpic('e-');
})->throws(RuntimeException::class, 'Ambiguous epic ID');

it('generates unique IDs in e-xxxxxx format', function () {
    $epic1 = $this->service->createEpic('Epic 1');
    $epic2 = $this->service->createEpic('Epic 2');

    expect($epic1['id'])->toMatch('/^e-[a-f0-9]{6}$/');
    expect($epic2['id'])->toMatch('/^e-[a-f0-9]{6}$/');
    expect($epic1['id'])->not->toBe($epic2['id']);
});

it('returns not completed when epic has no tasks', function () {
    $epic = $this->service->createEpic('Empty Epic');

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result)->toBe(['completed' => false, 'review_task_id' => null]);
});

it('returns not completed when epic has open tasks', function () {
    $epic = $this->service->createEpic('Epic with open tasks');

    $this->taskService->create([
        'title' => 'Open task',
        'epic_id' => $epic['id'],
    ]);

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result)->toBe(['completed' => false, 'review_task_id' => null]);
});

it('returns not completed when epic has in_progress tasks', function () {
    $epic = $this->service->createEpic('Epic with in_progress tasks');

    $task = $this->taskService->create([
        'title' => 'In progress task',
        'epic_id' => $epic['id'],
    ]);
    $this->taskService->start($task['id']);

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result)->toBe(['completed' => false, 'review_task_id' => null]);
});

it('triggers completion when all tasks are closed', function () {
    $epic = $this->service->createEpic('Epic with all closed tasks', 'Test epic description');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic['id'],
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic['id'],
    ]);

    $this->taskService->done($task1['id']);
    $this->taskService->done($task2['id']);

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result['completed'])->toBeTrue();
    expect($result['review_task_id'])->not->toBeNull();

    $updatedEpic = $this->service->getEpic($epic['id']);
    expect($updatedEpic['status'])->toBe('review_pending');
});

it('triggers completion when tasks are closed or cancelled', function () {
    $epic = $this->service->createEpic('Epic with mixed closed/cancelled');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic['id'],
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic['id'],
    ]);

    $this->taskService->done($task1['id']);
    $this->taskService->update($task2['id'], ['status' => 'cancelled']);

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result['completed'])->toBeTrue();
    expect($result['review_task_id'])->not->toBeNull();
});

it('returns false for non-existent epic', function () {
    $result = $this->service->checkEpicCompletion('e-000000');

    expect($result)->toBe(['completed' => false, 'review_task_id' => null]);
});

it('prevents duplicate review tasks when checkEpicCompletion called multiple times', function () {
    $epic = $this->service->createEpic('Epic for idempotency test');

    $task1 = $this->taskService->create([
        'title' => 'Task 1',
        'epic_id' => $epic['id'],
    ]);
    $task2 = $this->taskService->create([
        'title' => 'Task 2',
        'epic_id' => $epic['id'],
    ]);

    $this->taskService->done($task1['id']);
    $this->taskService->done($task2['id']);

    // First call creates review task
    $result1 = $this->service->checkEpicCompletion($epic['id']);
    expect($result1['completed'])->toBeTrue();
    expect($result1['review_task_id'])->not->toBeNull();
    $firstReviewTaskId = $result1['review_task_id'];

    // Second call should return same review task (idempotency)
    $result2 = $this->service->checkEpicCompletion($epic['id']);
    expect($result2['completed'])->toBeTrue();
    expect($result2['review_task_id'])->toBe($firstReviewTaskId);

    // Verify only one review task exists
    $allTasks = $this->taskService->all();
    $reviewTasks = $allTasks->filter(fn (array $task): bool => in_array('epic-review', $task['labels'] ?? [], true));
    expect($reviewTasks->count())->toBe(1);
});

it('approves an epic', function () {
    $epic = $this->service->createEpic('Epic to approve');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic['id']]);
    $this->taskService->done($task['id']);

    $approved = $this->service->approveEpic($epic['id'], 'test-user');

    expect($approved['approved_at'])->not->toBeNull();
    expect($approved['approved_by'])->toBe('test-user');
    expect($approved['status'])->toBe('approved');
});

it('approves an epic with default approved_by', function () {
    $epic = $this->service->createEpic('Epic to approve');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic['id']]);
    $this->taskService->done($task['id']);

    $approved = $this->service->approveEpic($epic['id']);

    expect($approved['approved_at'])->not->toBeNull();
    expect($approved['approved_by'])->toBe('human');
    expect($approved['status'])->toBe('approved');
});

it('rejects an epic and reopens tasks', function () {
    $epic = $this->service->createEpic('Epic to reject');
    $task1 = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic['id']]);
    $task2 = $this->taskService->create(['title' => 'Task 2', 'epic_id' => $epic['id']]);

    // Close tasks
    $this->taskService->done($task1['id']);
    $this->taskService->done($task2['id']);

    // Reject epic
    $rejected = $this->service->rejectEpic($epic['id'], 'Needs more work');

    expect($rejected['changes_requested_at'])->not->toBeNull();
    expect($rejected['approved_at'])->toBeNull();
    expect($rejected['approved_by'])->toBeNull();
    expect($rejected['status'])->toBe('in_progress'); // Tasks reopened, so in_progress

    // Verify tasks were reopened
    $task1Updated = $this->taskService->find($task1['id']);
    $task2Updated = $this->taskService->find($task2['id']);
    expect($task1Updated['status'])->toBe('open');
    expect($task2Updated['status'])->toBe('open');
});

it('shows changes_requested status when epic rejected but tasks not yet reopened', function () {
    $epic = $this->service->createEpic('Epic to reject');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic['id']]);
    $this->taskService->done($task['id']);

    // Reject epic
    $rejected = $this->service->rejectEpic($epic['id']);

    // Status should be in_progress because rejectEpic reopens tasks
    expect($rejected['status'])->toBe('in_progress');
});

it('throws exception when approving non-existent epic', function () {
    $this->service->approveEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");

it('throws exception when rejecting non-existent epic', function () {
    $this->service->rejectEpic('e-000000');
})->throws(RuntimeException::class, "Epic 'e-000000' not found");
