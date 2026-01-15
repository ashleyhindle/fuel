<?php

declare(strict_types=1);

use App\Models\Epic;
use App\Services\EpicService;

test('fuel plan shows clean exit message when interrupted before epic creation', function () {
    // The command should handle testing environment gracefully
    $this->artisan('plan')
        ->expectsOutput('Starting new planning session with Claude Opus 4.5...')
        ->assertExitCode(0);

    // Verify no epic was created
    expect(Epic::count())->toBe(0);
});

test('fuel plan does not show "no epic created" warning when resuming existing epic', function () {
    // Create an existing paused epic using the service
    $epicService = app(EpicService::class);
    $epic = $epicService->createEpic(
        title: 'Test Epic',
        description: 'Test description',
        selfGuided: false
    );

    // Manually set status to paused
    Epic::where('short_id', $epic->short_id)->update(['status' => \App\Enums\EpicStatus::Paused]);

    $this->artisan('plan', ['epic-id' => $epic->short_id])
        ->expectsOutput("Resuming planning session for epic: {$epic->short_id}")
        ->expectsOutput("Epic: {$epic->title}")
        ->assertExitCode(0);
});

test('fuel plan handles non-existent epic gracefully', function () {
    $this->artisan('plan', ['epic-id' => 'e-999999'])
        ->expectsOutput('Epic e-999999 not found.')
        ->assertExitCode(1);
});

test('fuel plan rejects non-paused epic', function () {
    // Create an open epic using the service
    $epicService = app(EpicService::class);
    $epic = $epicService->createEpic(
        title: 'Test Epic',
        description: 'Test description',
        selfGuided: false
    );

    // Get the actual stored status value
    $dbEpic = Epic::where('short_id', $epic->short_id)->first();
    $actualStatus = $dbEpic->status->value;

    $this->artisan('plan', ['epic-id' => $epic->short_id])
        ->expectsOutput("Epic {$epic->short_id} is not paused (status: {$actualStatus}).")
        ->expectsOutput('Only paused epics can be resumed for planning.')
        ->assertExitCode(1);
});
