<?php

use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// =============================================================================
// paused Command Tests
// =============================================================================

describe('paused command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
        $this->epicService = app(EpicService::class);
        // Ensure wide terminal so all columns are shown
        putenv('COLUMNS=200');
    });

    afterEach(function (): void {
        putenv('COLUMNS');
    });

    it('shows no paused items when empty', function (): void {
        Artisan::call('paused', []);

        expect(Artisan::output())->toContain('No paused tasks or epics found');
    });

    it('shows paused tasks', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);
        $this->taskService->pause($task->short_id);

        Artisan::call('paused', []);
        $output = Artisan::output();

        expect($output)->toContain('Paused tasks');
        expect($output)->toContain('Test task');
        expect($output)->toContain($task->short_id);
        expect($output)->toContain("fuel unpause {$task->short_id}");
    });

    it('shows paused epics', function (): void {
        $epic = $this->epicService->createEpic('Test epic', 'Test description');

        Artisan::call('paused', []);
        $output = Artisan::output();

        expect($output)->toContain('Paused epics');
        expect($output)->toContain('Test epic');
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain("fuel unpause {$epic->short_id}");
    });

    it('excludes non-paused tasks', function (): void {
        $openTask = $this->taskService->create(['title' => 'Open task']);
        $pausedTask = $this->taskService->create(['title' => 'Paused task']);
        $this->taskService->pause($pausedTask->short_id);

        Artisan::call('paused', []);
        $output = Artisan::output();

        expect($output)->toContain('Paused task');
        expect($output)->not->toContain('Open task');
    });

    it('excludes non-paused epics', function (): void {
        $pausedEpic = $this->epicService->createEpic('Paused epic', 'Description');
        $unpausedEpic = $this->epicService->createEpic('Unpaused epic', 'Description');
        $this->epicService->unpause($unpausedEpic->short_id);

        Artisan::call('paused', []);
        $output = Artisan::output();

        expect($output)->toContain('Paused epic');
        expect($output)->not->toContain('Unpaused epic');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'Paused task']);
        $this->taskService->pause($task->short_id);

        $epic = $this->epicService->createEpic('Paused epic', 'Test description');

        Artisan::call('paused', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveKey('tasks');
        expect($data)->toHaveKey('epics');
        expect($data['tasks'])->toBeArray();
        expect($data['epics'])->toBeArray();

        // Check task data
        $taskData = array_values($data['tasks']);
        expect($taskData)->toHaveCount(1);
        expect($taskData[0]['short_id'])->toBe($task->short_id);
        expect($taskData[0]['status'])->toBe('paused');

        // Check epic data
        $epicData = array_values($data['epics']);
        expect($epicData)->toHaveCount(1);
        expect($epicData[0]['short_id'])->toBe($epic->short_id);
        expect($epicData[0]['status'])->toBe('paused');
    });

    it('outputs empty arrays as JSON when no paused items', function (): void {
        Artisan::call('paused', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data['tasks'])->toBeEmpty();
        expect($data['epics'])->toBeEmpty();
    });

    it('displays task and epic details in table format', function (): void {
        $task = $this->taskService->create(['title' => 'Test paused task']);
        $this->taskService->pause($task->short_id);

        $epic = $this->epicService->createEpic('Test paused epic', 'Description');

        Artisan::call('paused', []);
        $output = Artisan::output();

        // Check headers and data for tasks
        expect($output)->toContain('ID');
        expect($output)->toContain('Title');
        expect($output)->toContain('Unpause Command');
        expect($output)->toContain($task->short_id);
        expect($output)->toContain('Test paused task');

        // Check headers and data for epics
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('Test paused epic');
    });

    it('truncates long titles', function (): void {
        $longTitle = str_repeat('A', 50).' Long Title';
        $task = $this->taskService->create(['title' => $longTitle]);
        $this->taskService->pause($task->short_id);

        Artisan::call('paused', []);
        $output = Artisan::output();

        // Title should be truncated with ellipsis
        expect($output)->toContain('...');
        expect($output)->not->toContain($longTitle);
    });

    it('shows both tasks and epics when both are paused', function (): void {
        $task = $this->taskService->create(['title' => 'Paused task']);
        $this->taskService->pause($task->short_id);

        $epic = $this->epicService->createEpic('Paused epic', 'Description');

        Artisan::call('paused', []);
        $output = Artisan::output();

        expect($output)->toContain('Paused tasks (1)');
        expect($output)->toContain('Paused epics (1)');
        expect($output)->toContain('Paused task');
        expect($output)->toContain('Paused epic');
    });
});
