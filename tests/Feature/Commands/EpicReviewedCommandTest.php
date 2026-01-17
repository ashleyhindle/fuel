<?php

declare(strict_types=1);

use App\Enums\MirrorStatus;
use App\Models\Task;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

describe('epic:reviewed command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
        $this->context = $this->app->make(FuelContext::class);
        $this->configPath = $this->context->getConfigPath();
    });

    it('marks an epic as reviewed', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:reviewed', ['id' => $epic->short_id]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s marked as reviewed', $epic->short_id));

        // Verify the epic was actually marked as reviewed
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:reviewed', ['id' => 'e-nonexistent']);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epic:reviewed', ['id' => $epic->short_id, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['short_id'])->toBe($epic->short_id);
        expect($data['title'])->toBe('JSON Epic');
        expect($data['reviewed_at'])->not->toBeNull();
        expect($data['reviewed_at'])->toBeString();
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        // Use partial ID (without e- prefix)
        $partialId = substr((string) $epic->short_id, 2);

        Artisan::call('epic:reviewed', ['id' => $partialId]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s marked as reviewed', $epic->short_id));

        // Verify the epic was actually marked as reviewed
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();
    });

    it('updates reviewed_at timestamp', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Timestamp Test Epic');

        // Initially reviewed_at should be null
        $initialEpic = $epicService->getEpic($epic->short_id);
        expect($initialEpic->reviewed_at)->toBeNull();

        // Mark as reviewed
        Artisan::call('epic:reviewed', ['id' => $epic->short_id]);

        // Verify reviewed_at is now set
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();
        expect($updatedEpic->reviewed_at)->toBeInstanceOf(\DateTimeInterface::class);
    });

    it('does not create merge task when mirrors disabled', function (): void {
        // Ensure mirrors are disabled
        $config = [
            'agents' => ['claude' => ['driver' => 'claude']],
            'complexity' => ['simple' => 'claude'],
            'primary' => 'claude',
            'epic_mirrors' => false,
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('No Mirror Epic');

        // Simulate epic with mirror path (but mirrors disabled)
        $epicService->updateMirrorStatus($epic, MirrorStatus::Ready);
        $epic->mirror_path = '/tmp/test-mirror';
        $epic->save();

        Artisan::call('epic:reviewed', ['id' => $epic->short_id]);

        // Verify epic was reviewed
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();

        // Verify no merge task was created (mirror_status should still be Ready, not Merging)
        expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Ready);

        // Verify no merge tasks exist
        $mergeTasks = Task::where('title', 'LIKE', 'Merge epic/%')->get();
        expect($mergeTasks)->toBeEmpty();
    });

    it('does not create merge task when epic has no mirror', function (): void {
        // Enable mirrors in config
        $config = [
            'agents' => ['claude' => ['driver' => 'claude']],
            'complexity' => ['simple' => 'claude'],
            'primary' => 'claude',
            'epic_mirrors' => true,
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('No Mirror Path Epic');

        // Epic has no mirror_path (mirror_status is None by default)
        expect($epic->mirror_path)->toBeNull();

        Artisan::call('epic:reviewed', ['id' => $epic->short_id]);

        // Verify epic was reviewed
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();

        // Verify no merge task was created
        $mergeTasks = Task::where('title', 'LIKE', 'Merge epic/%')->get();
        expect($mergeTasks)->toBeEmpty();
    });

    it('updates mirror status to Merging when mirrors enabled and epic has mirror', function (): void {
        // Enable mirrors in config
        $config = [
            'agents' => ['claude' => ['driver' => 'claude']],
            'complexity' => ['simple' => 'claude'],
            'primary' => 'claude',
            'epic_mirrors' => true,
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Mirror Epic');

        // Set up epic with mirror
        $epicService->updateMirrorStatus($epic, MirrorStatus::Ready);
        $epic->mirror_path = '/tmp/test-mirror';
        $epic->mirror_branch = 'epic/'.$epic->short_id;
        $epic->mirror_base_commit = 'abc123';
        $epic->save();

        Artisan::call('epic:reviewed', ['id' => $epic->short_id]);

        // Verify epic was reviewed
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->reviewed_at)->not->toBeNull();

        // Verify mirror status was updated to Merging
        // This is the key behavior - the command should update the status to trigger merge
        expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Merging);
    });
});
