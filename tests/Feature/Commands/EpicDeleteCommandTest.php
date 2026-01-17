<?php

declare(strict_types=1);

use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('epic:delete command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
    });

    it('deletes an epic', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:delete', ['id' => $epic->short_id]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted epic: '.$epic->short_id);

        $deletedEpic = $epicService->getEpic($epic->short_id);
        expect($deletedEpic)->toBeNull();
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:delete', ['id' => 'e-nonexistent']);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epic:delete', ['id' => $epic->short_id, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['short_id'])->toBe($epic->short_id);
        expect($data['deleted'])->toBeArray();
        expect($data['deleted']['title'])->toBe('JSON Epic');
        expect($data['unlinked_tasks'])->toBeArray();
        expect($data['unlinked_tasks'])->toBeEmpty();
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        $partialId = substr((string) $epic->short_id, 2);

        Artisan::call('epic:delete', ['id' => $partialId]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted epic: '.$epic->short_id);

        $deletedEpic = $epicService->getEpic($epic->short_id);
        expect($deletedEpic)->toBeNull();
    });

    it('unlinks tasks when deleting an epic', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Tasks');
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic->short_id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('epic:delete', ['id' => $epic->short_id]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted epic: '.$epic->short_id);
        expect($output)->toContain('Unlinked tasks:');
        expect($output)->toContain($task1->short_id);
        expect($output)->toContain($task2->short_id);

        $updatedTask1 = $taskService->find($task1->short_id);
        $updatedTask2 = $taskService->find($task2->short_id);
        expect($updatedTask1->epic_id)->toBeNull();
        expect($updatedTask2->epic_id)->toBeNull();

        $deletedEpic = $epicService->getEpic($epic->short_id);
        expect($deletedEpic)->toBeNull();
    });

    it('includes unlinked task IDs in JSON output', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Tasks JSON');
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic->short_id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('epic:delete', ['id' => $epic->short_id, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['short_id'])->toBe($epic->short_id);
        expect($data['unlinked_tasks'])->toContain($task1->short_id);
        expect($data['unlinked_tasks'])->toContain($task2->short_id);
    });

    it('shows error in JSON format when --json flag is used and epic not found', function (): void {
        Artisan::call('epic:delete', ['id' => 'e-nonexistent', '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['error'])->toContain("Epic 'e-nonexistent' not found");
    });
});
