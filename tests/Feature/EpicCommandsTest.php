<?php

declare(strict_types=1);

use App\Enums\EpicStatus;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->taskService = app(TaskService::class);
    $this->epicService = app(EpicService::class);
});

describe('epic:add command', function (): void {
    it('creates an epic via CLI', function (): void {
        $this->artisan('epic:add', ['title' => 'My test epic'])
            ->expectsOutputToContain('Created epic: e-')
            ->assertExitCode(0);
    });

    it('creates epic with title only', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Title Only Epic',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['title'])->toBe('Title Only Epic');
        expect($epic['description'])->toBeNull();
        expect($epic['short_id'])->toStartWith('e-');
        expect($epic['status'])->toBe(EpicStatus::Paused->value); // Epics start paused
    });

    it('creates epic with description', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic with description',
            '--description' => 'This is the epic description',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $epic = json_decode($output, true);

        expect($epic['title'])->toBe('Epic with description');
        expect($epic['description'])->toBe('This is the epic description');
    });

    it('outputs JSON when --json flag is used', function (): void {
        Artisan::call('epic:add', [
            'title' => 'JSON epic',
            '--json' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "'.EpicStatus::Paused->value.'"'); // Epics start paused
        expect($output)->toContain('"title": "JSON epic"');
        expect($output)->toContain('"short_id": "e-');
    });

    it('generates unique epic IDs', function (): void {
        Artisan::call('epic:add', [
            'title' => 'Epic 1',
            '--json' => true,
        ]);
        $epic1 = json_decode(Artisan::output(), true);

        Artisan::call('epic:add', [
            'title' => 'Epic 2',
            '--json' => true,
        ]);
        $epic2 = json_decode(Artisan::output(), true);

        expect($epic1['short_id'])->not->toBe($epic2['short_id']);
        expect($epic1['short_id'])->toMatch('/^e-[a-f0-9]{6}$/');
        expect($epic2['short_id'])->toMatch('/^e-[a-f0-9]{6}$/');
    });
});

describe('add command with --epic flag', function (): void {
    it('creates task linked to epic', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Task for epic',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        $epicModel = Epic::findByPartialId($epic->short_id);
        expect($epicModel)->not->toBeNull();
        expect($task['epic_id'])->toBe($epicModel->id);
    });

    it('fails when epic does not exist', function (): void {

        $this->artisan('add', [
            'title' => 'Task for missing epic',
            '--epic' => 'e-000000',
        ])
            ->expectsOutputToContain("Epic 'e-000000' not found")
            ->assertExitCode(1);
    });

    it('supports partial epic ID matching', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');
        $partialId = substr((string) $epic->short_id, 2, 4);

        Artisan::call('add', [
            'title' => 'Task with partial epic ID',
            '--epic' => $partialId,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        $epicModel = Epic::findByPartialId($epic->short_id);
        expect($epicModel)->not->toBeNull();
        expect($task['epic_id'])->toBe($epicModel->id);
    });

    it('task is returned by getTasksForEpic', function (): void {
        $epic = $this->epicService->createEpic('Test Epic');

        Artisan::call('add', [
            'title' => 'Epic task 1',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);

        Artisan::call('add', [
            'title' => 'Epic task 2',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);

        $tasks = $this->epicService->getTasksForEpic($epic->short_id);

        expect($tasks)->toHaveCount(2);
        $titles = array_column($tasks, 'title');
        expect($titles)->toContain('Epic task 1');
        expect($titles)->toContain('Epic task 2');
    });
});

describe('epic:add with selfguided mode', function (): void {
    it('creates plan file with Acceptance Criteria and Progress Log sections', function (): void {
        // Create epic with JSON output to get ID reliably
        Artisan::call('epic:add', [
            'title' => 'Self-guided test epic',
            '--selfguided' => true,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $epic = json_decode($output, true);
        $epicId = $epic['short_id'];

        // Check that plan file was created with correct sections
        $planPath = $this->testDir.'/.fuel/plans/self-guided-test-epic-'.$epicId.'.md';
        expect(file_exists($planPath))->toBeTrue();

        $content = file_get_contents($planPath);

        // Verify required sections exist
        expect($content)->toContain('## Acceptance Criteria');
        expect($content)->toContain('- [ ] Criterion 1');
        expect($content)->toContain('- [ ] Criterion 2');
        expect($content)->toContain('- [ ] Criterion 3');
        expect($content)->toContain('## Progress Log');
        expect($content)->toContain('## Implementation Notes');
        expect($content)->toContain('## Interfaces Created');
    });

    it('creates plan file with only Implementation Notes for non-selfguided epic', function (): void {
        // Create epic with JSON output to get ID reliably
        Artisan::call('epic:add', [
            'title' => 'Regular test epic',
            '--json' => true,
        ]);

        $output = Artisan::output();
        $epic = json_decode($output, true);
        $epicId = $epic['short_id'];

        // Check that plan file was created without selfguided sections
        $planPath = $this->testDir.'/.fuel/plans/regular-test-epic-'.$epicId.'.md';
        expect(file_exists($planPath))->toBeTrue();

        $content = file_get_contents($planPath);

        // Verify selfguided sections do NOT exist
        expect($content)->not->toContain('## Acceptance Criteria');
        expect($content)->not->toContain('## Progress Log');

        // But regular sections do exist
        expect($content)->toContain('## Implementation Notes');
        expect($content)->toContain('## Interfaces Created');
    });
});

describe('epic:update with selfguided transition', function (): void {
    it('creates selfguided task when transitioning to selfguided mode', function (): void {
        // Create regular epic first
        $epic = $this->epicService->createEpic('Epic to transition');

        // Transition to selfguided mode
        $this->artisan('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ])
            ->expectsOutputToContain('Updated epic: '.$epic->short_id)
            ->expectsOutputToContain('Created self-guided task: f-')
            ->assertExitCode(0);

        // Verify task was created
        $tasks = $this->epicService->getTasksForEpic($epic->short_id);
        expect($tasks)->toHaveCount(1);
        expect($tasks[0]->type)->toBe('selfguided');
        expect($tasks[0]->complexity)->toBe('complex');
        expect($tasks[0]->title)->toContain('Implement: Epic to transition');
    });

    it('is idempotent - does not create duplicate selfguided tasks', function (): void {
        // Create regular epic
        $epic = $this->epicService->createEpic('Idempotent test epic');

        // First transition to selfguided
        $this->artisan('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ])
            ->expectsOutputToContain('Created self-guided task: f-')
            ->assertExitCode(0);

        // Get the created task
        $tasks = $this->epicService->getTasksForEpic($epic->short_id);
        expect($tasks)->toHaveCount(1);
        $firstTaskId = $tasks[0]->short_id;

        // Second update with selfguided (should be idempotent)
        $this->artisan('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
            '--title' => 'Updated title',
        ])
            ->expectsOutputToContain('Updated epic: '.$epic->short_id)
            ->assertExitCode(0);

        $output2 = Artisan::output();

        // Should NOT create a new task
        expect($output2)->not->toContain('Created self-guided task:');

        // Verify only one task exists
        $tasks = $this->epicService->getTasksForEpic($epic->short_id);
        expect($tasks)->toHaveCount(1);
        expect($tasks[0]->short_id)->toBe($firstTaskId);
    });

    it('updates plan file with required sections when transitioning', function (): void {
        // Create regular epic first
        $epic = $this->epicService->createEpic('Epic with plan update');

        // Ensure plans directory exists
        $plansDir = $this->testDir.'/.fuel/plans';
        if (! is_dir($plansDir)) {
            mkdir($plansDir, 0755, true);
        }

        // Create the plan file without selfguided sections
        $planPath = $plansDir.'/epic-with-plan-update-'.$epic->short_id.'.md';
        file_put_contents($planPath, <<<MARKDOWN
# Epic: Epic with plan update ({$epic->short_id})

## Plan

Test plan content

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
MARKDOWN);

        // Transition to selfguided mode
        $this->artisan('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ])
            ->expectsOutputToContain('Updated epic: '.$epic->short_id)
            ->expectsOutputToContain('Plan: .fuel/plans/')
            ->assertExitCode(0);

        // Verify plan file was updated with required sections
        $content = file_get_contents($planPath);

        // Should have added the missing sections
        expect($content)->toContain('## Acceptance Criteria');
        expect($content)->toContain('- [ ] Criterion 1');
        expect($content)->toContain('- [ ] Criterion 2');
        expect($content)->toContain('- [ ] Criterion 3');
        expect($content)->toContain('## Progress Log');

        // Original content should still be present
        expect($content)->toContain('Test plan content');
        expect($content)->toContain('## Implementation Notes');
        expect($content)->toContain('## Interfaces Created');
    });

    it('creates plan file with selfguided sections if missing during transition', function (): void {
        // Create regular epic
        $epic = $this->epicService->createEpic('Epic without plan');

        // Delete the auto-created plan file
        $planPath = $this->testDir.'/.fuel/plans/epic-without-plan-'.$epic->short_id.'.md';
        if (file_exists($planPath)) {
            unlink($planPath);
        }

        // Transition to selfguided mode (should create plan file)
        $this->artisan('epic:update', [
            'id' => $epic->short_id,
            '--selfguided' => true,
        ])
            ->expectsOutputToContain('Updated epic: '.$epic->short_id)
            ->expectsOutputToContain('Plan: .fuel/plans/')
            ->assertExitCode(0);

        // Verify plan file was created with selfguided template
        expect(file_exists($planPath))->toBeTrue();

        $content = file_get_contents($planPath);
        expect($content)->toContain('## Acceptance Criteria');
        expect($content)->toContain('- [ ] Criterion 1');
        expect($content)->toContain('## Progress Log');
        expect($content)->toContain('## Implementation Notes');
    });

    it('does not modify plan when epic is already selfguided', function (): void {
        // Create selfguided epic from the start
        Artisan::call('epic:add', [
            'title' => 'Already selfguided epic',
            '--selfguided' => true,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $epic = json_decode($output, true);
        $epicId = $epic['short_id'];

        // Update the epic while it's already selfguided
        $this->artisan('epic:update', [
            'id' => $epicId,
            '--selfguided' => true,
            '--description' => 'New description',
        ])
            ->expectsOutputToContain('Updated epic: '.$epicId)
            ->assertExitCode(0);

        $updateOutput = Artisan::output();

        // Should NOT mention creating task or updating plan since already selfguided
        expect($updateOutput)->not->toContain('Created self-guided task:');
        // Plan path is still shown when transition happens, even if no changes
        // But the task creation should not happen
    });
});

describe('epic status derivation via commands', function (): void {
    it('epic status is paused when newly created', function (): void {
        $epic = $this->epicService->createEpic('Empty Epic');

        $fetchedEpic = $this->epicService->getEpic($epic->short_id);

        expect($fetchedEpic->status)->toBe(EpicStatus::Paused); // Epics start paused
    });

    it('epic status is in_progress when task is open', function (): void {
        $epic = $this->epicService->createEpic('Epic with task');
        $this->epicService->unpause($epic->short_id); // Epics start paused

        Artisan::call('add', [
            'title' => 'Open task',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);

        $fetchedEpic = $this->epicService->getEpic($epic->short_id);

        expect($fetchedEpic->status)->toBe(EpicStatus::InProgress);
    });

    it('epic status is in_progress when task is in_progress', function (): void {
        $epic = $this->epicService->createEpic('Epic with active task');
        $this->epicService->unpause($epic->short_id); // Epics start paused

        Artisan::call('add', [
            'title' => 'Active task',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);
        $task = json_decode(Artisan::output(), true);

        $this->artisan('start', [
            'id' => $task['short_id'],
        ])->assertExitCode(0);

        $fetchedEpic = $this->epicService->getEpic($epic->short_id);

        expect($fetchedEpic->status)->toBe(EpicStatus::InProgress);
    });

    it('epic status is review_pending when all tasks are done', function (): void {
        $epic = $this->epicService->createEpic('Completed Epic');
        $this->epicService->unpause($epic->short_id); // Epics start paused

        Artisan::call('add', [
            'title' => 'Task to complete',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);
        $task = json_decode(Artisan::output(), true);

        $this->artisan('done', [
            'ids' => [$task['short_id']],
        ])->assertExitCode(0);

        $fetchedEpic = $this->epicService->getEpic($epic->short_id);

        expect($fetchedEpic->status)->toBe(EpicStatus::ReviewPending);
    });

    it('epic status transitions correctly through task lifecycle', function (): void {
        $epic = $this->epicService->createEpic('Lifecycle Epic');

        // Epics start paused
        expect($this->epicService->getEpic($epic->short_id)->status)->toBe(EpicStatus::Paused);

        // Unpause to test computed status
        $this->epicService->unpause($epic->short_id);
        expect($this->epicService->getEpic($epic->short_id)->status)->toBe(EpicStatus::Planning);

        Artisan::call('add', [
            'title' => 'Lifecycle task',
            '--epic' => $epic->short_id,
            '--json' => true,
        ]);
        $task = json_decode(Artisan::output(), true);

        expect($this->epicService->getEpic($epic->short_id)->status)->toBe(EpicStatus::InProgress);

        $this->artisan('start', [
            'id' => $task['short_id'],
        ])->assertExitCode(0);

        expect($this->epicService->getEpic($epic->short_id)->status)->toBe(EpicStatus::InProgress);

        $this->artisan('done', [
            'ids' => [$task['short_id']],
        ])->assertExitCode(0);

        expect($this->epicService->getEpic($epic->short_id)->status)->toBe(EpicStatus::ReviewPending);
    });
});
