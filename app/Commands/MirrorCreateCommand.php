<?php

declare(strict_types=1);

namespace App\Commands;

use App\Enums\MirrorStatus;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\FuelContext;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class MirrorCreateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'mirror:create
        {epic : The epic ID to create mirror for}
        {--cwd= : Working directory (defaults to current directory)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create an isolated mirror directory for an epic';

    /**
     * Hidden from command list (internal background process).
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(EpicService $epicService, FuelContext $fuelContext): int
    {
        $epicId = $this->argument('epic');

        // Step 1: Find epic by short_id
        $epic = Epic::findByPartialId($epicId);
        if (! $epic instanceof Epic) {
            $this->error(sprintf("Epic '%s' not found", $epicId));

            return self::FAILURE;
        }

        try {
            // Step 2: Update mirror_status to Creating
            $epicService->updateMirrorStatus($epic, MirrorStatus::Creating);

            // Step 3: Build mirror path
            $projectPath = realpath($fuelContext->getProjectPath());
            if ($projectPath === false) {
                throw new RuntimeException('Unable to determine project path');
            }

            $projectSlug = Str::slug(basename($projectPath));
            $mirrorBasePath = $_SERVER['HOME'].'/.fuel/mirrors/'.$projectSlug;
            $mirrorPath = $mirrorBasePath.'/'.$epic->short_id;

            // Step 4: Create parent dirs
            if (!is_dir($mirrorBasePath) && ! mkdir($mirrorBasePath, 0755, true)) {
                throw new RuntimeException('Failed to create mirror base directory: ' . $mirrorBasePath);
            }

            // Ensure mirror doesn't already exist
            if (is_dir($mirrorPath)) {
                throw new RuntimeException('Mirror directory already exists: ' . $mirrorPath);
            }

            // Step 5: Copy project using platform-specific command
            $this->info(sprintf('Creating mirror for epic %s...', $epic->short_id));
            $copyCommand = $this->buildCopyCommand($projectPath, $mirrorPath);

            $output = [];
            $returnCode = 0;
            exec($copyCommand.' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new RuntimeException(
                    sprintf('Failed to copy project to mirror. Command: %s%s', $copyCommand, PHP_EOL).
                    'Output: '.implode("\n", $output)
                );
            }

            // Step 6: Remove .fuel/ from mirror and create symlink to original
            $mirrorFuelPath = $mirrorPath.'/.fuel';
            $originalFuelPath = $projectPath.'/.fuel';

            if (is_dir($mirrorFuelPath)) {
                // Remove the copied .fuel directory
                $rmCommand = sprintf('rm -rf %s', escapeshellarg($mirrorFuelPath));
                exec($rmCommand.' 2>&1', $output, $returnCode);

                if ($returnCode !== 0) {
                    throw new RuntimeException(
                        'Failed to remove .fuel from mirror: '.implode("\n", $output)
                    );
                }
            }

            // Create symlink to original .fuel
            if (! symlink($originalFuelPath, $mirrorFuelPath)) {
                throw new RuntimeException('Failed to create symlink to original .fuel directory');
            }

            // Step 7: In mirror, create git branch
            $branchName = 'epic/'.$epic->short_id;

            // Get current HEAD commit before creating branch
            $getCurrentCommitCommand = sprintf(
                'cd %s && git rev-parse HEAD',
                escapeshellarg($mirrorPath)
            );
            exec($getCurrentCommitCommand.' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new RuntimeException(
                    'Failed to get current HEAD commit: '.implode("\n", $output)
                );
            }

            $baseCommit = trim($output[count($output) - 1]);

            // Create and checkout new branch
            $createBranchCommand = sprintf(
                'cd %s && git checkout -b %s',
                escapeshellarg($mirrorPath),
                escapeshellarg($branchName)
            );

            $output = [];
            exec($createBranchCommand.' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new RuntimeException(
                    sprintf("Failed to create git branch '%s': ", $branchName).implode("\n", $output)
                );
            }

            // Step 8: Call epicService->setMirrorReady with path, branch, current HEAD commit
            $epicService->setMirrorReady($epic, $mirrorPath, $branchName, $baseCommit);

            $this->info('Mirror created successfully at: ' . $mirrorPath);
            $this->info('Branch: ' . $branchName);
            $this->info('Base commit: ' . $baseCommit);

            return self::SUCCESS;

        } catch (Throwable $throwable) {
            // On failure: set mirror_status to Failed (if exists) and log error
            $this->error('Mirror creation failed: '.$throwable->getMessage());

            // Check if Failed status exists in enum, otherwise use None
            try {
                $failureStatus = MirrorStatus::tryFrom('failed');
                if ($failureStatus === null) {
                    // Failed case doesn't exist, use None
                    $failureStatus = MirrorStatus::None;
                }
            } catch (Throwable) {
                // Use None if Failed doesn't exist
                $failureStatus = MirrorStatus::None;
            }

            $epicService->updateMirrorStatus($epic, $failureStatus);

            // Log the full error for debugging
            $this->error('Stack trace:');
            $this->error($throwable->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Build the copy command based on the operating system.
     */
    private function buildCopyCommand(string $source, string $destination): string
    {
        $escapedSource = escapeshellarg($source);
        $escapedDestination = escapeshellarg($destination);

        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS: use cp with clonefile
            return sprintf('cp -cR %s %s', $escapedSource, $escapedDestination);
        }
        // Linux: use cp with reflink for copy-on-write
        return sprintf('cp --reflink=auto -R %s %s', $escapedSource, $escapedDestination);
    }
}
