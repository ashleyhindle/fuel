<?php

declare(strict_types=1);

use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('epic:reviewed command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
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
});
