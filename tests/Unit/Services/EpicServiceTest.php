<?php

declare(strict_types=1);

use App\Enums\EpicStatus;
use App\Enums\MirrorStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Providers\AppServiceProvider;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\UpdateRealityService;
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

    // Mock UpdateRealityService to prevent config errors
    $mockUpdateReality = Mockery::mock(UpdateRealityService::class);
    $mockUpdateReality->shouldReceive('triggerUpdate')->andReturnNull();
    app()->instance(UpdateRealityService::class, $mockUpdateReality);

    $this->taskService = makeTaskService();
    $this->service = makeEpicService($this->taskService, $this->context);
});

afterEach(function (): void {
    Mockery::close();

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
    expect($epic->status)->toBe(EpicStatus::Paused); // Epics start paused
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
    $this->service->unpause($created->short_id); // Epics start paused

    $epic = $this->service->getEpic($created->short_id);

    expect($epic->status)->toBe(EpicStatus::Planning);
});

it('computes epic status as in_progress when task is open', function (): void {
    $epic = $this->service->createEpic('Title');
    $this->service->unpause($epic->short_id); // Epics start paused
    $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::InProgress);
});

it('computes epic status as in_progress when task is in_progress', function (): void {
    $epic = $this->service->createEpic('Title');
    $this->service->unpause($epic->short_id); // Epics start paused
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);
    $this->taskService->start($task->short_id);

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::InProgress);
});

it('computes epic status as review_pending when all tasks are done', function (): void {
    $epic = $this->service->createEpic('Title');
    $this->service->unpause($epic->short_id); // Epics start paused
    $task = $this->taskService->create(['title' => 'Task 1', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    $epic = $this->service->getEpic($epic->short_id);

    expect($epic->status)->toBe(EpicStatus::ReviewPending);
});

it('computes epic status as reviewed when reviewed_at is set', function (): void {
    $epic = $this->service->createEpic('Title');
    $this->service->unpause($epic->short_id); // Epics start paused
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
    $this->service->unpause($created->short_id); // Epics start paused

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
    $this->service->unpause($epic->short_id); // Epics start paused

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
    $this->service->unpause($epic->short_id); // Epics start paused
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
    $this->service->unpause($epic->short_id); // Epics start paused
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

it('persists approved status to database', function (): void {
    $epic = $this->service->createEpic('Epic to approve');
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    $this->service->approveEpic($epic->short_id);

    // Query the database directly to verify status was persisted
    $result = $this->db->fetchAll(
        'SELECT status FROM epics WHERE short_id = ?',
        [$epic->short_id]
    );

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('approved');
});

it('persists paused status to database', function (): void {
    $epic = $this->service->createEpic('Epic to pause');

    $this->service->pause($epic->short_id);

    // Query the database directly to verify status was persisted
    $result = $this->db->fetchAll(
        'SELECT status FROM epics WHERE short_id = ?',
        [$epic->short_id]
    );

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('paused');
});

it('persists reviewed status to database', function (): void {
    $epic = $this->service->createEpic('Epic to review');
    $this->service->unpause($epic->short_id); // Epics start paused
    $task = $this->taskService->create(['title' => 'Task', 'epic_id' => $epic->short_id]);
    $this->taskService->done($task->short_id);

    $this->service->markAsReviewed($epic->short_id);

    // Query the database directly to verify status was persisted
    $result = $this->db->fetchAll(
        'SELECT status FROM epics WHERE short_id = ?',
        [$epic->short_id]
    );

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('reviewed');
});

it('delegates getProjectPath to FuelContext', function (): void {
    $projectPath = $this->service->getProjectPath();

    expect($projectPath)->toBe($this->tempDir);
});

it('updates mirror status of an epic', function (): void {
    $epic = $this->service->createEpic('Epic with mirror');

    $this->service->updateMirrorStatus($epic, MirrorStatus::Creating);

    $updatedEpic = $this->service->getEpic($epic->short_id);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Creating);
});

it('sets mirror as ready with all required fields', function (): void {
    $epic = $this->service->createEpic('Epic with mirror');
    $mirrorPath = '/home/user/.fuel/mirrors/project/e-123456';
    $branch = 'epic/e-123456';
    $baseCommit = 'abc123def456';

    $this->service->setMirrorReady($epic, $mirrorPath, $branch, $baseCommit);

    $updatedEpic = $this->service->getEpic($epic->short_id);
    expect($updatedEpic->mirror_path)->toBe($mirrorPath);
    expect($updatedEpic->mirror_branch)->toBe($branch);
    expect($updatedEpic->mirror_base_commit)->toBe($baseCommit);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Ready);
    expect($updatedEpic->mirror_created_at)->not->toBeNull();
});

it('cleans up mirror directory and updates status', function (): void {
    // Create a temporary mirror directory
    $mirrorDir = $this->tempDir.'/test-mirror';
    mkdir($mirrorDir, 0755, true);
    file_put_contents($mirrorDir.'/test.txt', 'test content');

    // Create epic and set it up with a mirror
    $epic = $this->service->createEpic('Epic with mirror to clean');
    $this->service->setMirrorReady($epic, $mirrorDir, 'epic/test', 'commit123');

    // Verify directory exists
    expect(is_dir($mirrorDir))->toBeTrue();

    // Clean up the mirror
    $epicWithMirror = $this->service->getEpic($epic->short_id);
    $this->service->cleanupMirror($epicWithMirror);

    // Verify directory was removed
    expect(is_dir($mirrorDir))->toBeFalse();

    // Verify status was updated
    $updatedEpic = $this->service->getEpic($epic->short_id);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Cleaned);
});

it('handles cleanupMirror when mirror_path is empty', function (): void {
    $epic = $this->service->createEpic('Epic without mirror path');

    // Should not throw error even when mirror_path is empty
    $this->service->cleanupMirror($epic);

    $updatedEpic = $this->service->getEpic($epic->short_id);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Cleaned);
});

it('handles cleanupMirror when mirror directory does not exist', function (): void {
    $epic = $this->service->createEpic('Epic with non-existent mirror');
    $nonExistentPath = '/path/that/does/not/exist';

    // Set mirror path but don't create the directory
    $this->db->query(
        'UPDATE epics SET mirror_path = ? WHERE short_id = ?',
        [$nonExistentPath, $epic->short_id]
    );

    $epicWithPath = $this->service->getEpic($epic->short_id);

    // Should not throw error even when directory doesn't exist
    $this->service->cleanupMirror($epicWithPath);

    $updatedEpic = $this->service->getEpic($epic->short_id);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Cleaned);
});

it('gets epics with merge failed status', function (): void {
    // Create epics with various mirror statuses
    $epic1 = $this->service->createEpic('Epic with merge failed');
    $this->service->updateMirrorStatus($epic1, MirrorStatus::MergeFailed);

    $epic2 = $this->service->createEpic('Another merge failed');
    $this->service->updateMirrorStatus($epic2, MirrorStatus::MergeFailed);

    $epic3 = $this->service->createEpic('Epic with ready status');
    $this->service->updateMirrorStatus($epic3, MirrorStatus::Ready);

    // Get epics with merge failed status
    $mergeFailedEpics = $this->service->getEpicsWithMergeFailed();

    expect($mergeFailedEpics)->toHaveCount(2);
    // Verify both epics are in the result (order may vary)
    $ids = array_map(fn ($e) => $e->short_id, $mergeFailedEpics);
    expect($ids)->toContain($epic1->short_id);
    expect($ids)->toContain($epic2->short_id);
});

it('gets epics with stale mirrors', function (): void {
    // Create epic with old mirror
    $epic1 = $this->service->createEpic('Stale epic');
    $this->service->setMirrorReady($epic1, '/path/to/mirror1', 'epic/old', 'commit1');

    // Set updated_at to 8 days ago
    $eightDaysAgo = Carbon::now('UTC')->subDays(8)->toIso8601String();
    $this->db->query(
        'UPDATE epics SET updated_at = ? WHERE short_id = ?',
        [$eightDaysAgo, $epic1->short_id]
    );

    // Create epic with recent mirror
    $epic2 = $this->service->createEpic('Recent epic');
    $this->service->setMirrorReady($epic2, '/path/to/mirror2', 'epic/new', 'commit2');

    // Create epic with old mirror but approved
    $epic3 = $this->service->createEpic('Approved epic');
    $this->service->setMirrorReady($epic3, '/path/to/mirror3', 'epic/approved', 'commit3');
    $this->db->query(
        'UPDATE epics SET updated_at = ?, approved_at = ? WHERE short_id = ?',
        [$eightDaysAgo, Carbon::now('UTC')->toIso8601String(), $epic3->short_id]
    );

    // Get stale mirrors
    $staleEpics = $this->service->getEpicsWithStaleMirrors();

    expect($staleEpics)->toHaveCount(1);
    expect($staleEpics[0]->short_id)->toBe($epic1->short_id);
});

it('finds orphaned mirrors', function (): void {
    // Create mirrors directory structure
    $projectSlug = basename($this->tempDir);
    $mirrorsBasePath = $_SERVER['HOME'].'/.fuel/mirrors/'.$projectSlug;
    mkdir($mirrorsBasePath, 0755, true);

    // Create orphaned mirror (epic doesn't exist)
    $orphanedId = 'e-abc123';
    mkdir($mirrorsBasePath.'/'.$orphanedId, 0755, true);

    // Create mirror for approved epic
    $approvedEpic = $this->service->createEpic('Approved epic');
    mkdir($mirrorsBasePath.'/'.$approvedEpic->short_id, 0755, true);
    $this->db->query(
        'UPDATE epics SET approved_at = ? WHERE short_id = ?',
        [Carbon::now('UTC')->toIso8601String(), $approvedEpic->short_id]
    );

    // Create mirror with invalid ID format
    $invalidId = 'invalid-format';
    mkdir($mirrorsBasePath.'/'.$invalidId, 0755, true);

    // Create valid mirror (should not be in orphaned list)
    $validEpic = $this->service->createEpic('Valid epic');
    mkdir($mirrorsBasePath.'/'.$validEpic->short_id, 0755, true);

    // Get orphaned mirrors
    $orphaned = $this->service->findOrphanedMirrors();

    expect($orphaned)->toHaveCount(3);

    // Clean up test directories
    exec('rm -rf '.escapeshellarg($mirrorsBasePath));
});

it('returns empty array when mirrors directory does not exist', function (): void {
    $orphaned = $this->service->findOrphanedMirrors();

    expect($orphaned)->toBe([]);
});

it('checks if any epic has active merge', function (): void {
    // Initially no merges
    expect($this->service->hasActiveMerge())->toBeFalse();

    // Create epic with Merging status
    $epic1 = $this->service->createEpic('Epic 1');
    $this->db->query(
        'UPDATE epics SET mirror_status = ? WHERE short_id = ?',
        [MirrorStatus::Merging->value, $epic1->short_id]
    );

    // Now should detect active merge
    expect($this->service->hasActiveMerge())->toBeTrue();

    // Create another epic with Ready status
    $epic2 = $this->service->createEpic('Epic 2');
    $this->db->query(
        'UPDATE epics SET mirror_status = ? WHERE short_id = ?',
        [MirrorStatus::Ready->value, $epic2->short_id]
    );

    // Should still detect active merge (epic1 is still merging)
    expect($this->service->hasActiveMerge())->toBeTrue();

    // Update epic1 to Merged status
    $this->db->query(
        'UPDATE epics SET mirror_status = ? WHERE short_id = ?',
        [MirrorStatus::Merged->value, $epic1->short_id]
    );

    // No longer has active merge
    expect($this->service->hasActiveMerge())->toBeFalse();
});
