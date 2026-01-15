<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\FuelContext;
use App\Services\PromptService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class SelfUpdateCommand extends Command
{
    protected $signature = 'self-update|upgrade';

    protected $description = 'Update Fuel to the latest version from GitHub releases';

    private const GITHUB_REPO = 'ashleyhindle/fuel';

    private const GITHUB_API_BASE = 'https://api.github.com/repos';

    private const GITHUB_RELEASES_BASE = 'https://github.com';

    public function handle(): int
    {
        try {
            return $this->doUpdate();
        } catch (Throwable $throwable) {
            $this->error('Self-update failed: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function doUpdate(): int
    {
        // Detect OS
        $os = $this->detectOs();
        if ($os === null) {
            $this->error('Windows is not supported. Please update manually.');

            return self::FAILURE;
        }

        // Detect architecture
        $arch = $this->detectArch();
        if ($arch === null) {
            $this->error('Unsupported architecture: '.$this->getUnameArch());

            return self::FAILURE;
        }

        // Query GitHub API for latest release
        $this->info('Checking for latest version...');
        $version = $this->getLatestVersion();
        if ($version === null) {
            return self::FAILURE;
        }

        // Determine target path
        $homeDir = $this->getHomeDirectory();
        $targetDir = $homeDir.'/.fuel';
        $targetPath = $targetDir.'/fuel';

        // Check if already on latest version
        $currentVersion = config('app.version');
        $alreadyLatest = $currentVersion === $version;

        if ($alreadyLatest) {
            $this->info(sprintf('Already on latest version (%s)', $version));
        }

        // Determine init settings BEFORE binary replacement to avoid zlib errors
        // after the phar file changes on disk
        $fuelContext = app(FuelContext::class);
        $projectPath = $fuelContext->getProjectPath();
        $shouldRunInit = $this->shouldRunInit($projectPath);

        // Check if consume daemon is running and stop it before update
        $daemonWasRunning = false;
        $pidFilePath = $fuelContext->getPidFilePath();
        if (file_exists($pidFilePath)) {
            $pidData = json_decode(file_get_contents($pidFilePath) ?: '', true);
            if (is_array($pidData) && isset($pidData['pid'])) {
                $pid = (int) $pidData['pid'];
                if (function_exists('posix_kill') && posix_kill($pid, 0)) {
                    $daemonWasRunning = true;
                    $this->info('Stopping consume daemon before update...');
                    posix_kill($pid, SIGTERM);
                    // Wait up to 5 seconds for graceful shutdown
                    for ($i = 0; $i < 50 && posix_kill($pid, 0); $i++) {
                        usleep(100000);
                    }
                }
            }
        }

        // Download and install new binary if not already on latest
        if (! $alreadyLatest) {
            // Create target directory if it doesn't exist
            if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true)) {
                $this->error('Failed to create directory: '.$targetDir);

                return self::FAILURE;
            }

            // Download binary
            $binaryUrl = $this->getBinaryUrl($os, $arch, $version);
            $this->info('Downloading from: '.$binaryUrl);

            $tempPath = $targetPath.'.tmp';
            try {
                $this->downloadBinary($binaryUrl, $tempPath);
            } catch (RuntimeException $runtimeException) {
                $this->error('Download failed: '.$runtimeException->getMessage());

                return self::FAILURE;
            }

            // Make executable
            if (! chmod($tempPath, 0755)) {
                @unlink($tempPath);
                $this->error('Failed to set executable permissions on downloaded binary');

                return self::FAILURE;
            }

            // Atomic replace
            if (! rename($tempPath, $targetPath)) {
                @unlink($tempPath);
                $this->error('Failed to replace binary at: '.$targetPath);

                return self::FAILURE;
            }

            $this->info('Updated to '.$version);
            $this->line('Release notes: '.self::GITHUB_RELEASES_BASE.'/'.self::GITHUB_REPO.'/releases/tag/'.$version);
        }

        // Run init to update guidelines and skills in current project
        if ($shouldRunInit) {

            // If we just replaced the binary, execute the new binary for init
            // to avoid zlib errors from the old process reading the changed phar
            // We exit() directly to avoid any Laravel cleanup that could trigger zlib errors
            if (! $alreadyLatest) {
                $initResult = 0;
                passthru($targetPath.' init --cwd='.escapeshellarg($projectPath), $initResult);
                exit($initResult);
            }

            $initResult = $this->call('init');
            if ($initResult !== self::SUCCESS) {
                $this->error('Init failed with exit code: '.$initResult);

                return self::FAILURE;
            }

            // Check for outdated prompt templates
            $this->checkOutdatedPrompts();
        } else {
            $this->line('No .fuel directory found in current path. Run `fuel init` in your project to update.');
        }

        // Restart daemon if it was running before update
        if ($daemonWasRunning && ! $alreadyLatest) {
            $this->info('Restarting consume daemon with new version...');
            // Start the new daemon in background
            $cmd = escapeshellarg($targetPath).' consume:runner > /dev/null 2>&1 &';
            exec($cmd);
            $this->info('Daemon restarted.');
        }

        return self::SUCCESS;
    }

    /**
     * Detect OS and return normalized name.
     *
     * @return string|null Returns 'darwin', 'linux', or null if unsupported
     */
    private function detectOs(): ?string
    {
        $os = php_uname('s');

        return match ($os) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => null,
        };
    }

    /**
     * Detect architecture and return normalized name.
     *
     * @return string|null Returns 'x64', 'arm64', or null if unsupported
     */
    private function detectArch(): ?string
    {
        $arch = $this->getUnameArch();

        return match ($arch) {
            'x86_64' => 'x64',
            'arm64', 'aarch64' => 'arm64',
            default => null,
        };
    }

    /**
     * Get uname architecture.
     */
    private function getUnameArch(): string
    {
        return php_uname('m');
    }

    /**
     * Get home directory.
     */
    private function getHomeDirectory(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if ($home === false || $home === '') {
            throw new RuntimeException('Could not determine home directory');
        }

        return $home;
    }

    private function shouldRunInit(string $startDir): bool
    {
        $currentDir = $startDir;
        $maxLevels = 5;

        for ($i = 0; $i < $maxLevels; $i++) {
            if (is_dir($currentDir.'/.fuel')) {
                return true;
            }

            if (is_dir($currentDir.'/.git')) {
                break;
            }

            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break;
            }

            $currentDir = $parentDir;
        }

        return false;
    }

    /**
     * Create a stream context for HTTP requests.
     *
     * @return resource
     */
    private function createHttpContext(int $timeout = 10): mixed
    {
        return stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/vnd.github.v3+json',
                    'User-Agent: fuel-cli',
                ],
                'timeout' => $timeout,
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
    }

    /**
     * Query GitHub API for latest release version tag.
     *
     * @return string|null Returns version tag (e.g., 'v1.0.0') or null on failure
     */
    private function getLatestVersion(): ?string
    {
        $url = self::GITHUB_API_BASE.'/'.self::GITHUB_REPO.'/releases/latest';
        $context = $this->createHttpContext();

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->error('Failed to fetch latest version from GitHub API');

            return null;
        }

        $data = json_decode($response, true);
        if ($data === null) {
            $this->error('GitHub API returned invalid JSON response');

            return null;
        }

        if (! isset($data['tag_name'])) {
            $this->error('GitHub API response missing tag_name');

            return null;
        }

        return $data['tag_name'];
    }

    /**
     * Get binary download URL for the given OS, arch, and version.
     */
    private function getBinaryUrl(string $os, string $arch, string $version): string
    {
        return self::GITHUB_RELEASES_BASE.'/'.self::GITHUB_REPO.'/releases/download/'.$version.'/fuel-'.$os.'-'.$arch;
    }

    /**
     * Check for outdated prompt templates and write .new files if needed.
     */
    private function checkOutdatedPrompts(): void
    {
        $promptService = app(PromptService::class);
        $outdated = $promptService->checkVersions();

        if ($outdated === []) {
            return;
        }

        $written = $promptService->writeUpgradeFiles();

        foreach ($written as $name) {
            $this->warn(sprintf(
                'Prompt %s.md is outdated (v%d < v%d). Compare with %s.md.new',
                $name,
                $outdated[$name]['user'],
                $outdated[$name]['current'],
                $name
            ));
        }

        if ($written !== []) {
            $this->line('Run: diff .fuel/prompts/<name>.md .fuel/prompts/<name>.md.new');
        }
    }

    /**
     * Download binary from URL to target path.
     *
     * @throws RuntimeException
     */
    private function downloadBinary(string $url, string $targetPath): void
    {
        $context = $this->createHttpContext(300);

        $source = @fopen($url, 'rb', false, $context);
        if ($source === false) {
            throw new RuntimeException('Failed to open download URL');
        }

        $dest = @fopen($targetPath, 'wb');
        if ($dest === false) {
            fclose($source);
            throw new RuntimeException('Failed to open target file: '.$targetPath);
        }

        $bytes = stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);

        if ($bytes === false || $bytes === 0) {
            @unlink($targetPath);
            throw new RuntimeException('Downloaded file is empty or transfer failed');
        }
    }
}
