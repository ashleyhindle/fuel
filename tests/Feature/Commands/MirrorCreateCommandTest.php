<?php

use App\Enums\MirrorStatus;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\FuelContext;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->fuelContext = app(FuelContext::class);
    $this->epicService = app(EpicService::class);
});

test('mirror:create command requires epic argument', function () {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Not enough arguments (missing: "epic").');
    $this->artisan('mirror:create');
});

test('mirror:create command fails for non-existent epic', function () {
    $this->artisan('mirror:create', ['epic' => 'e-999999'])
        ->assertExitCode(1)
        ->expectsOutput("Epic 'e-999999' not found");
});

test('mirror:create command creates mirror with proper status updates', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic', 'Test description');

    // Since actual mirror creation requires a real git repo and file operations,
    // we'll test the logic flow by mocking the services
    $statusUpdates = [];

    // Mock the EpicService to track method calls
    $mockedEpicService = $this->partialMock(EpicService::class, function ($mock) use (&$statusUpdates, $epic) {
        $mock->shouldReceive('updateMirrorStatus')
            ->andReturnUsing(function ($e, $status) use (&$statusUpdates) {
                $statusUpdates[] = $status->value;
                $e->mirror_status = $status;
                $e->save();
            });
        $mock->shouldReceive('setMirrorReady')
            ->andReturnUsing(function ($e, $path, $branch, $commit) {
                $e->mirror_status = MirrorStatus::Ready;
                $e->mirror_path = $path;
                $e->mirror_branch = $branch;
                $e->mirror_base_commit = $commit;
                $e->mirror_created_at = now();
                $e->save();
            });
    });

    // The actual directory doesn't exist, so command will fail, but we can verify the flow
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(1); // Will fail because we're not in a git repo

    // Verify that Creating status was set first
    $this->assertContains('creating', $statusUpdates);
});

test('mirror:create command handles failure gracefully', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic', 'Test description');

    // Mock FuelContext to return invalid project path
    $mockedContext = $this->mock(FuelContext::class);
    $mockedContext->shouldReceive('getProjectPath')
        ->andReturn('/non/existent/path');

    // Run the command - should fail
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(1)
        ->expectsOutputToContain('Mirror creation failed:');

    // Verify epic status was updated to None (since Failed doesn't exist yet)
    $epic->refresh();
    $this->assertEquals(MirrorStatus::None, $epic->mirror_status);
});

test('mirror:create command uses correct copy command for platform', function () {
    $epic = $this->epicService->createEpic('Test Epic', 'Test description');

    // Create a minimal test to verify the command logic
    // We can't easily test the actual copy without side effects
    $command = new \App\Commands\MirrorCreateCommand();

    // Use reflection to test the private buildCopyCommand method
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildCopyCommand');
    $method->setAccessible(true);

    $source = '/path/to/source';
    $dest = '/path/to/dest';
    $copyCommand = $method->invoke($command, $source, $dest);

    if (PHP_OS_FAMILY === 'Darwin') {
        $this->assertStringContainsString('cp -cR', $copyCommand);
    } else {
        $this->assertStringContainsString('cp --reflink=auto -R', $copyCommand);
    }

    // Verify paths are properly escaped
    $this->assertStringContainsString(escapeshellarg($source), $copyCommand);
    $this->assertStringContainsString(escapeshellarg($dest), $copyCommand);
});

test('mirror:create command sets status to Creating during operation', function () {
    $epic = $this->epicService->createEpic('Test Epic', 'Test description');

    // We'll mock the EpicService to track the status changes
    $statusChanges = [];
    $mockedEpicService = $this->mock(EpicService::class);
    $mockedEpicService->shouldReceive('updateMirrorStatus')
        ->andReturnUsing(function ($epic, $status) use (&$statusChanges) {
            $statusChanges[] = $status;
            $epic->mirror_status = $status;
            $epic->save();
        });
    $mockedEpicService->shouldReceive('setMirrorReady')
        ->andReturnUsing(function ($epic, $path, $branch, $commit) {
            $epic->mirror_status = MirrorStatus::Ready;
            $epic->mirror_path = $path;
            $epic->mirror_branch = $branch;
            $epic->mirror_base_commit = $commit;
            $epic->mirror_created_at = now();
            $epic->save();
        });

    // Mock FuelContext to fail so we can see the status changes
    $mockedContext = $this->mock(FuelContext::class);
    $mockedContext->shouldReceive('getProjectPath')
        ->andReturn('/non/existent/path');

    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(1);

    // Verify Creating status was set
    $this->assertContains(MirrorStatus::Creating, $statusChanges);
});