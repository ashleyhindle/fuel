<?php

use App\Repositories\ReviewRepository;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\RunService;
use App\Services\TaskService;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
| Note: TestCase provides $this->testDir - an isolated temp directory for each test.
| Tests must NEVER modify the real workspace.
|
*/

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function makeTaskService(): TaskService
{
    return new TaskService;
}

function makeEpicService(TaskService $taskService): EpicService
{
    return new EpicService($taskService);
}

function makeRunService(): RunService
{
    return new RunService;
}

/**
 * Helper to create a task for testing reviews (needed for FK relationship).
 */
function createTaskForReview(DatabaseService $service, string $shortId): void
{
    $service->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$shortId, 'Test Task '.$shortId, 'open', now()->toIso8601String(), now()->toIso8601String()]
    );
}

/**
 * Helper to create a review for a task using the repository.
 */
function createReviewForTask(
    ReviewRepository $reviewRepo,
    string $taskShortId,
    string $reviewShortId,
    string $agent,
    ?int $runId = null
): void {
    $taskId = $reviewRepo->resolveTaskId($taskShortId);
    if ($taskId === null) {
        throw new RuntimeException(sprintf("Task '%s' not found.", $taskShortId));
    }

    $reviewRepo->createReview($reviewShortId, $taskId, $agent, $runId);
}
