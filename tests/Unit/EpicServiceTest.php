<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\TaskService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-epic-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';
    $this->tasksPath = $this->tempDir.'/.fuel/tasks.jsonl';

    $this->db = new DatabaseService($this->dbPath);
    $this->taskService = new TaskService($this->tasksPath);
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

it('computes epic status as approved when approved_at is set', function () {
    $epic = $this->service->createEpic('Title');
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic['id']]);

    // Set approved_at directly in DB
    $this->db->initialize();
    $this->db->query(
        'UPDATE epics SET approved_at = ? WHERE id = ?',
        [\Carbon\Carbon::now('UTC')->toIso8601String(), $epic['id']]
    );

    $epic = $this->service->getEpic($epic['id']);

    expect($epic['status'])->toBe('approved');
});

it('throws exception for invalid status', function () {
    $created = $this->service->createEpic('Title');

    $this->service->updateEpic($created['id'], ['status' => 'invalid']);
})->throws(RuntimeException::class, "Invalid status 'invalid'. Must be one of:");

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

    expect($result['completed'])->toBeFalse();
    expect($result['review_task_id'])->toBeNull();
});

it('returns not completed when epic has open tasks', function () {
    $epic = $this->service->createEpic('Epic with open tasks');

    $this->taskService->create([
        'title' => 'Open task',
        'epic_id' => $epic['id'],
    ]);

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result['completed'])->toBeFalse();
    expect($result['review_task_id'])->toBeNull();
});

it('returns not completed when epic has in_progress tasks', function () {
    $epic = $this->service->createEpic('Epic with in_progress tasks');

    $task = $this->taskService->create([
        'title' => 'In progress task',
        'epic_id' => $epic['id'],
    ]);
    $this->taskService->start($task['id']);

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result['completed'])->toBeFalse();
    expect($result['review_task_id'])->toBeNull();
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
    expect($result['review_task_id'])->toStartWith('f-');

    $reviewTask = $this->taskService->find($result['review_task_id']);
    expect($reviewTask)->not->toBeNull();
    expect($reviewTask['title'])->toContain('Review completed epic:');
    expect($reviewTask['labels'])->toContain('needs-human');
    expect($reviewTask['labels'])->toContain('epic-review');

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
});

it('returns false for non-existent epic', function () {
    $result = $this->service->checkEpicCompletion('e-000000');

    expect($result['completed'])->toBeFalse();
    expect($result['review_task_id'])->toBeNull();
});

it('includes task commit hashes in summary when available', function () {
    $epic = $this->service->createEpic('Epic with commits');

    $task = $this->taskService->create([
        'title' => 'Task with commit',
        'epic_id' => $epic['id'],
    ]);

    $this->taskService->done($task['id'], null, 'abc1234');

    $result = $this->service->checkEpicCompletion($epic['id']);

    expect($result['completed'])->toBeTrue();

    $reviewTask = $this->taskService->find($result['review_task_id']);
    expect($reviewTask['description'])->toContain('abc1234');
});
