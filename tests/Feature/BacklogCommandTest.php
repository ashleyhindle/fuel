<?php

use App\Services\BacklogService;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

describe('backlog command', function (): void {
    it('shows no backlog items when empty', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $this->artisan('backlog')
            ->expectsOutput('No backlog items.')
            ->assertExitCode(0);
    });

    it('lists backlog items', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('Item 1');
        $item2 = $backlogService->add('Item 2', 'Description');

        Artisan::call('backlog');
        $output = Artisan::output();

        expect($output)->toContain('Backlog items (2):');
        expect($output)->toContain($item1['id']);
        expect($output)->toContain('Item 1');
        expect($output)->toContain($item2['id']);
        expect($output)->toContain('Item 2');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('Item 1');
        $item2 = $backlogService->add('Item 2');

        Artisan::call('backlog', ['--json' => true]);
        $output = Artisan::output();
        $items = json_decode($output, true);

        expect($items)->toBeArray();
        expect($items)->toHaveCount(2);
        expect($items[0]['id'])->toStartWith('b-');
        expect($items[1]['id'])->toStartWith('b-');
    });
});
