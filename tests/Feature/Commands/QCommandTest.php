<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

uses()->group('commands');

describe('q command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('creates task and outputs only the ID', function (): void {

        Artisan::call('q', ['title' => 'Quick task']);
        $output = trim(Artisan::output());

        expect($output)->toStartWith('f-');
        expect(strlen($output))->toBe(8); // f- + 6 chars

        // Verify task was actually created
        $task = $this->taskService->find($output);
        expect($task)->not->toBeNull();
        expect($task->title)->toBe('Quick task');
    });

    it('returns exit code 0 on success', function (): void {

        $this->artisan('q', ['title' => 'Quick task'])
            ->assertExitCode(0);
    });

    it('handles RuntimeException from TaskService::create()', function (): void {
        // Create a mock TaskService that throws RuntimeException
        $mockTaskService = \Mockery::mock(TaskService::class);
        $mockTaskService->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('Failed to create task'));

        // Bind the mock to the service container
        $this->app->singleton(TaskService::class, fn () => $mockTaskService);

        $exitCode = Artisan::call('q', ['title' => 'Test task']);
        $output = trim(Artisan::output());

        expect($output)->toContain('Failed to create task');
        expect($exitCode)->toBe(Command::FAILURE);
    });
});
