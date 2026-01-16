<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('backlog command', function (): void {
    it('shows no backlog items when empty', function (): void {
        $this->artisan('backlog')
            ->expectsOutput('No backlog items.')
            ->assertExitCode(0);
    });

    it('lists backlog items', function (): void {
        $taskService = $this->app->make(TaskService::class);

        $item1 = $taskService->create(['title' => 'Item 1']);
        $taskService->update($item1->short_id, ['status' => 'someday']);
        $item1 = $taskService->find($item1->short_id);

        $item2 = $taskService->create(['title' => 'Item 2', 'description' => 'Description']);
        $taskService->update($item2->short_id, ['status' => 'someday']);
        $item2 = $taskService->find($item2->short_id);

        Artisan::call('backlog');
        $output = Artisan::output();

        expect($output)->toContain('Backlog items (2):');
        expect($output)->toContain($item1->short_id);
        expect($output)->toContain('Item 1');
        expect($output)->toContain($item2->short_id);
        expect($output)->toContain('Item 2');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $taskService = $this->app->make(TaskService::class);

        $item1 = $taskService->create(['title' => 'Item 1']);
        $taskService->update($item1->short_id, ['status' => 'someday']);

        $item2 = $taskService->create(['title' => 'Item 2']);
        $taskService->update($item2->short_id, ['status' => 'someday']);

        Artisan::call('backlog', ['--json' => true]);
        $output = Artisan::output();
        $items = json_decode($output, true);

        expect($items)->toBeArray();
        expect($items)->toHaveCount(2);
        expect($items[0]['short_id'])->toStartWith('f-');
        expect($items[1]['short_id'])->toStartWith('f-');
    });
});
