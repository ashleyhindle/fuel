<?php

declare(strict_types=1);

use App\Models\Epic;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('epic:add command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
    });

    it('creates an epic via CLI', function (): void {
        $this->artisan('epic:add', ['title' => 'My test epic'])
            ->expectsOutputToContain('Created epic: e-')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        Artisan::call('epic:add', ['title' => 'JSON epic', '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "paused"'); // Epics start paused
        expect($output)->toContain('"title": "JSON epic"');
        expect($output)->toContain('"short_id": "e-');
    });

    it('creates epic with --description flag', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic with description',
            '--description' => 'This is a detailed description',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['description'])->toBe('This is a detailed description');
        expect($epic['title'])->toBe('Epic with description');
        expect($epic['status'])->toBe('paused'); // Epics start paused
        expect($epic['short_id'])->toStartWith('e-');
    });

    it('creates epic without description', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic without description',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['description'])->toBeNull();
        expect($epic['title'])->toBe('Epic without description');
        expect($epic['status'])->toBe('paused'); // Epics start paused
    });

    it('outputs epic ID in non-JSON mode', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Test Epic',
            '--description' => 'Test description',
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created epic: e-');
        expect($output)->toContain('Title: Test Epic');
        expect($output)->toContain('Description: Test description');
    });

    it('does not output description line when description is null', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Test Epic',
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created epic: e-');
        expect($output)->toContain('Title: Test Epic');
        expect($output)->not->toContain('Description:');
    });

    it('stores plan_filename on epic using slug format', function (): void {
        Artisan::call('epic:add', [
            'title' => 'SVG creation for OpenCode',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        $epic = Epic::where('short_id', $data['short_id'])->first();

        // Should use slug format (lowercase, hyphens for spaces)
        // NOT kebab format (which would split SVG into s-v-g)
        expect($epic->plan_filename)->toBe('svg-creation-for-opencode-'.$epic->short_id.'.md');
        expect($epic->plan_filename)->not->toContain('s-v-g');
    });
});
