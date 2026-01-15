<?php

declare(strict_types=1);

use App\Models\Epic;
use App\Enums\EpicStatus;

test('fuel plan resumes planning on existing paused epic', function () {
    // Create a paused epic
    $epic = Epic::create([
        'id' => 'e-abc123',
        'short_id' => 'abc123',
        'title' => 'Test Epic',
        'description' => 'A test epic for resume',
        'status' => EpicStatus::Paused,
        'is_self_guided' => false,
    ]);

    $this->artisan('plan', ['epic-id' => 'e-abc123'])
        ->expectsOutput('Resuming planning session for epic: e-abc123')
        ->expectsOutput('Epic: Test Epic')
        ->assertExitCode(0);
});

test('fuel plan fails when epic does not exist', function () {
    $this->artisan('plan', ['epic-id' => 'e-nonexistent'])
        ->expectsOutput('Epic e-nonexistent not found.')
        ->assertExitCode(0);
});

test('fuel plan fails when epic is not paused', function () {
    // Create an open epic (not paused)
    $epic = Epic::create([
        'id' => 'e-def456',
        'short_id' => 'def456',
        'title' => 'Open Epic',
        'description' => 'An open epic',
        'status' => EpicStatus::Planning,
        'is_self_guided' => false,
    ]);

    $this->artisan('plan', ['epic-id' => 'e-def456'])
        ->expectsOutput('Epic e-def456 is not paused (status: planning).')
        ->expectsOutput('Only paused epics can be resumed for planning.')
        ->assertExitCode(0);
});

test('fuel plan loads existing plan file when resuming', function () {
    // Create a paused epic
    $epic = Epic::create([
        'id' => 'e-ghi789',
        'short_id' => 'ghi789',
        'title' => 'Epic With Plan',
        'description' => 'Epic that has an existing plan',
        'status' => EpicStatus::Paused,
        'is_self_guided' => false,
    ]);

    // Create a plan file
    $planDir = '.fuel/plans';
    if (! is_dir($planDir)) {
        mkdir($planDir, 0777, true);
    }

    $planFile = "{$planDir}/epic-with-plan-e-ghi789.md";
    file_put_contents($planFile, "# Epic: Epic With Plan (e-ghi789)\n\n## Plan\nExisting plan content\n\n## Acceptance Criteria\n- [ ] Task 1\n- [ ] Task 2");

    // The actual interaction will happen with Claude, we just test that it starts correctly
    $this->artisan('plan', ['epic-id' => 'e-ghi789'])
        ->expectsOutput('Resuming planning session for epic: e-ghi789')
        ->expectsOutput('Epic: Epic With Plan')
        ->assertExitCode(0);

    // Clean up
    if (file_exists($planFile)) {
        unlink($planFile);
    }
});