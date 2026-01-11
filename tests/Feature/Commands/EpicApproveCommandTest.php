<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    // Bind our test service instances
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));
    $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService(
        $this->app->make(DatabaseService::class),
        $this->app->make(TaskService::class)
    ));

    $this->databaseService = $this->app->make(DatabaseService::class);
    $this->databaseService->initialize();
});

afterEach(function (): void {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('epic:approve command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService($databaseService));

        $this->taskService = $this->app->make(TaskService::class);
    });

    afterEach(function (): void {
        $deleteDir = function (string $dir) use (&$deleteDir): void {
            if (! is_dir($dir)) {
                return;
            }

            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.') {
                    continue;
                }

                if ($item === '..') {
                    continue;
                }

                $path = $dir.'/'.$item;
                if (is_dir($path)) {
                    $deleteDir($path);
                } elseif (file_exists($path)) {
                    unlink($path);
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

    it('approves a single epic', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:approve', ['ids' => [$epic->id], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic->id));

        // Verify the epic was actually approved
        $updatedEpic = $epicService->getEpic($epic->id);
        expect($updatedEpic->approved_at)->not->toBeNull();
        expect($updatedEpic->approved_by)->toBe('human');
    });

    it('approves multiple epics', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('Epic 1', 'Description 1');
        $epic2 = $epicService->createEpic('Epic 2', 'Description 2');
        $epic3 = $epicService->createEpic('Epic 3', 'Description 3');

        Artisan::call('epic:approve', ['ids' => [$epic1->id, $epic2->id, $epic3->id], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic1->id));
        expect($output)->toContain(sprintf('Epic %s approved', $epic2->id));
        expect($output)->toContain(sprintf('Epic %s approved', $epic3->id));

        // Verify all epics were approved
        $updatedEpic1 = $epicService->getEpic($epic1->id);
        $updatedEpic2 = $epicService->getEpic($epic2->id);
        $updatedEpic3 = $epicService->getEpic($epic3->id);

        expect($updatedEpic1->approved_at)->not->toBeNull();
        expect($updatedEpic2->approved_at)->not->toBeNull();
        expect($updatedEpic3->approved_at)->not->toBeNull();
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:approve', ['ids' => ['e-nonexistent'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON for single epic when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epic:approve', ['ids' => [$epic->id], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['id'])->toBe($epic->id);
        expect($data['title'])->toBe('JSON Epic');
        expect($data['approved_at'])->not->toBeNull();
        expect($data['approved_at'])->toBeString();
        expect($data['approved_by'])->toBe('human');
    });

    it('outputs JSON array for multiple epics when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('Epic 1', 'Description 1');
        $epic2 = $epicService->createEpic('Epic 2', 'Description 2');

        Artisan::call('epic:approve', ['ids' => [$epic1->id, $epic2->id], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(2);
        expect($data[0]['id'])->toBe($epic1->id);
        expect($data[1]['id'])->toBe($epic2->id);
        expect($data[0]['approved_at'])->not->toBeNull();
        expect($data[1]['approved_at'])->not->toBeNull();
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        // Use partial ID (without e- prefix)
        $partialId = substr((string) $epic->id, 2);

        Artisan::call('epic:approve', ['ids' => [$partialId], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic->id));

        // Verify the epic was actually approved
        $updatedEpic = $epicService->getEpic($epic->id);
        expect($updatedEpic->approved_at)->not->toBeNull();
    });

    it('uses custom approver when --by option is provided', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('epic:approve', ['ids' => [$epic->id], '--cwd' => $this->tempDir, '--by' => 'admin']);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic->id));
        expect($output)->toContain('Approved by: admin');

        // Verify the epic was approved by the specified user
        $updatedEpic = $epicService->getEpic($epic->id);
        expect($updatedEpic->approved_by)->toBe('admin');
    });

    it('handles mix of valid and invalid epic IDs', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('Epic 1');

        Artisan::call('epic:approve', ['ids' => [$epic1->id, 'e-invalid'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should approve the valid one
        expect($output)->toContain(sprintf('Epic %s approved', $epic1->id));
        // Should show error for the invalid one
        expect($output)->toContain("Epic 'e-invalid'");

        // Verify the valid epic was approved
        $updatedEpic1 = $epicService->getEpic($epic1->id);
        expect($updatedEpic1->approved_at)->not->toBeNull();
    });

    it('clears changes_requested_at when approving', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        // First reject to set changes_requested_at
        $epicService->rejectEpic($epic->id, 'Needs work');

        // Verify changes were requested
        $rejectedEpic = $epicService->getEpic($epic->id);
        expect($rejectedEpic->changes_requested_at)->not->toBeNull();

        // Now approve
        Artisan::call('epic:approve', ['ids' => [$epic->id], '--cwd' => $this->tempDir]);

        // Verify changes_requested_at is cleared and approved_at is set
        $approvedEpic = $epicService->getEpic($epic->id);
        expect($approvedEpic->approved_at)->not->toBeNull();
        expect($approvedEpic->changes_requested_at)->toBeNull();
    });
});
