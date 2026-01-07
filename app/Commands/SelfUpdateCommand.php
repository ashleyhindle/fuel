<?php

declare(strict_types=1);

namespace App\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class SelfUpdateCommand extends Command
{
    protected $signature = 'self-update';

    protected $description = 'Update Fuel to the latest version from GitHub releases';

    private const GITHUB_REPO = 'ashleyhindle/fuel';

    private const GITHUB_API_BASE = 'https://api.github.com/repos';

    private const GITHUB_RELEASES_BASE = 'https://github.com';

    public function handle(): int
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
            $this->error("Unsupported architecture: {$this->getUnameArch()}");

            return self::FAILURE;
        }

        $this->info("Detected: OS={$os}, Arch={$arch}");

        // Query GitHub API for latest release
        $this->info('Checking for latest version...');
        $version = $this->getLatestVersion();
        if ($version === null) {
            return self::FAILURE;
        }

        $this->info("Latest version: {$version}");

        // Determine target path
        $homeDir = $this->getHomeDirectory();
        $targetDir = $homeDir.'/.fuel';
        $targetPath = $targetDir.'/fuel';

        // Create target directory if it doesn't exist
        if (! is_dir($targetDir)) {
            if (! mkdir($targetDir, 0755, true)) {
                $this->error("Failed to create directory: {$targetDir}");

                return self::FAILURE;
            }
        }

        // Download binary
        $binaryUrl = $this->getBinaryUrl($os, $arch, $version);
        $this->info("Downloading from: {$binaryUrl}");

        $tempPath = $targetPath.'.tmp';
        try {
            $this->downloadBinary($binaryUrl, $tempPath);
        } catch (RuntimeException $e) {
            $this->error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Make executable
        if (! chmod($tempPath, 0755)) {
            @unlink($tempPath); // Clean up temp file on failure
            $this->error('Failed to set executable permissions on downloaded binary');

            return self::FAILURE;
        }

        // Atomic replace
        if (! rename($tempPath, $targetPath)) {
            @unlink($tempPath); // Clean up temp file on failure
            $this->error("Failed to replace binary at: {$targetPath}");

            return self::FAILURE;
        }

        $this->info("Updated to {$version}");
        $this->line("Binary installed at: {$targetPath}");

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

    /**
     * Query GitHub API for latest release version tag.
     *
     * @return string|null Returns version tag (e.g., 'v1.0.0') or null on failure
     */
    private function getLatestVersion(): ?string
    {
        $client = new Client([
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'fuel-cli',
            ],
        ]);

        try {
            $url = self::GITHUB_API_BASE.'/'.self::GITHUB_REPO.'/releases/latest';
            $response = $client->get($url);
            $data = json_decode($response->getBody()->getContents(), true);

            if ($data === null) {
                $this->error('GitHub API returned invalid JSON response');

                return null;
            }

            if (! isset($data['tag_name'])) {
                $this->error('GitHub API response missing tag_name');

                return null;
            }

            return $data['tag_name'];
        } catch (GuzzleException $e) {
            $this->error("Failed to fetch latest version: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Get binary download URL for the given OS, arch, and version.
     */
    private function getBinaryUrl(string $os, string $arch, string $version): string
    {
        return self::GITHUB_RELEASES_BASE.'/'.self::GITHUB_REPO.'/releases/download/'.$version.'/fuel-'.$os.'-'.$arch;
    }

    /**
     * Download binary from URL to target path.
     *
     * @throws RuntimeException
     */
    private function downloadBinary(string $url, string $targetPath): void
    {
        $client = new Client([
            'timeout' => 300, // 5 minutes for large downloads
            'headers' => [
                'User-Agent' => 'fuel-cli',
            ],
        ]);

        try {
            $response = $client->get($url, [
                'sink' => $targetPath, // Stream directly to file
            ]);

            // Verify we got a successful response
            if ($response->getStatusCode() !== 200) {
                @unlink($targetPath); // Clean up on failure
                throw new RuntimeException("HTTP {$response->getStatusCode()} response");
            }

            // Verify file was actually written (not empty)
            if (! file_exists($targetPath) || filesize($targetPath) === 0) {
                @unlink($targetPath); // Clean up on failure
                throw new RuntimeException('Downloaded file is empty or does not exist');
            }
        } catch (GuzzleException $e) {
            @unlink($targetPath); // Clean up on failure
            throw new RuntimeException("Download failed: {$e->getMessage()}", 0, $e);
        }
    }
}
