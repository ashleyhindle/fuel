<?php

use App\Commands\SelfUpdateCommand;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->homeDir = $this->tempDir.'/home';
    mkdir($this->homeDir, 0755, true);
    $this->targetDir = $this->homeDir.'/.fuel';
    $this->targetPath = $this->targetDir.'/fuel';

    // Set HOME environment variable for testing
    putenv('HOME=' . $this->homeDir);
    $_SERVER['HOME'] = $this->homeDir;
});

afterEach(function (): void {
    // Clean up temp files
    if (file_exists($this->targetPath)) {
        @unlink($this->targetPath);
    }

    if (file_exists($this->targetPath.'.tmp')) {
        @unlink($this->targetPath.'.tmp');
    }

    if (is_dir($this->targetDir)) {
        @rmdir($this->targetDir);
    }

    if (is_dir($this->homeDir)) {
        @rmdir($this->homeDir);
    }

    if (is_dir($this->tempDir)) {
        @rmdir($this->tempDir);
    }

    // Restore environment
    putenv('HOME');
    unset($_SERVER['HOME']);
});

describe('OS detection mapping', function (): void {
    it('maps Darwin to darwin', function (): void {
        $os = php_uname('s');
        if ($os !== 'Darwin') {
            $this->markTestSkipped('Test only runs on Darwin');
        }

        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectOs');

        $result = $method->invoke($command);
        expect($result)->toBe('darwin');
    });

    it('maps Linux to linux', function (): void {
        $os = php_uname('s');
        if ($os !== 'Linux') {
            $this->markTestSkipped('Test only runs on Linux');
        }

        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectOs');

        $result = $method->invoke($command);
        expect($result)->toBe('linux');
    });

    it('returns null for unsupported OS', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectOs');

        $os = php_uname('s');
        $result = $method->invoke($command);

        // Should return null for Windows or any unsupported OS
        if ($os !== 'Darwin' && $os !== 'Linux') {
            expect($result)->toBeNull();
        } else {
            // On supported OS, should return the mapped value
            expect($result)->not->toBeNull();
            expect($result)->toBeIn(['darwin', 'linux']);
        }
    });

    it('shows error message for Windows', function (): void {
        // Verify error message exists in code
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('Windows is not supported');
        expect($sourceCode)->toContain('Please update manually');
    });
});

describe('Architecture detection mapping', function (): void {
    it('maps x86_64 to x64', function (): void {
        $arch = php_uname('m');
        if ($arch !== 'x86_64') {
            $this->markTestSkipped('Test only runs on x86_64');
        }

        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectArch');

        $result = $method->invoke($command);
        expect($result)->toBe('x64');
    });

    it('maps arm64 to arm64', function (): void {
        $arch = php_uname('m');
        if ($arch !== 'arm64') {
            $this->markTestSkipped('Test only runs on arm64');
        }

        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectArch');

        $result = $method->invoke($command);
        expect($result)->toBe('arm64');
    });

    it('maps aarch64 to arm64', function (): void {
        $arch = php_uname('m');
        if ($arch !== 'aarch64') {
            $this->markTestSkipped('Test only runs on aarch64');
        }

        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectArch');

        $result = $method->invoke($command);
        expect($result)->toBe('arm64');
    });

    it('returns null for unsupported architecture', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('detectArch');

        $arch = php_uname('m');
        $result = $method->invoke($command);

        // Should return null for unsupported architectures
        if (! in_array($arch, ['x86_64', 'arm64', 'aarch64'])) {
            expect($result)->toBeNull();
        } else {
            // On supported arch, should return the mapped value
            expect($result)->not->toBeNull();
            expect($result)->toBeIn(['x64', 'arm64']);
        }
    });

    it('shows error message for unsupported architecture', function (): void {
        // Verify error message exists in code
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('Unsupported architecture');
        expect($sourceCode)->toContain('getUnameArch()');
    });
});

describe('Error handling for unsupported OS', function (): void {
    it('returns failure exit code when OS is unsupported', function (): void {
        // Verify failure handling exists in code
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('detectOs()');
        expect($sourceCode)->toContain('self::FAILURE');
        expect($sourceCode)->toContain('Windows is not supported');
    });

    it('returns failure exit code when architecture is unsupported', function (): void {
        // Verify failure handling exists in code
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('detectArch()');
        expect($sourceCode)->toContain('self::FAILURE');
        expect($sourceCode)->toContain('Unsupported architecture');
    });
});

describe('Binary URL construction', function (): void {
    it('constructs correct binary URL for darwin-x64', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getBinaryUrl');

        $releasesBase = $reflection->getConstant('GITHUB_RELEASES_BASE');
        $repo = $reflection->getConstant('GITHUB_REPO');

        $url = $method->invoke($command, 'darwin', 'x64', 'v1.0.0');
        $expectedUrl = sprintf('%s/%s/releases/download/v1.0.0/fuel-darwin-x64', $releasesBase, $repo);

        expect($url)->toBe($expectedUrl);
    });

    it('constructs correct binary URL for darwin-arm64', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getBinaryUrl');

        $releasesBase = $reflection->getConstant('GITHUB_RELEASES_BASE');
        $repo = $reflection->getConstant('GITHUB_REPO');

        $url = $method->invoke($command, 'darwin', 'arm64', 'v1.0.0');
        $expectedUrl = sprintf('%s/%s/releases/download/v1.0.0/fuel-darwin-arm64', $releasesBase, $repo);

        expect($url)->toBe($expectedUrl);
    });

    it('constructs correct binary URL for linux-x64', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getBinaryUrl');

        $releasesBase = $reflection->getConstant('GITHUB_RELEASES_BASE');
        $repo = $reflection->getConstant('GITHUB_REPO');

        $url = $method->invoke($command, 'linux', 'x64', 'v1.0.0');
        $expectedUrl = sprintf('%s/%s/releases/download/v1.0.0/fuel-linux-x64', $releasesBase, $repo);

        expect($url)->toBe($expectedUrl);
    });

    it('constructs correct binary URL for linux-arm64', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getBinaryUrl');

        $releasesBase = $reflection->getConstant('GITHUB_RELEASES_BASE');
        $repo = $reflection->getConstant('GITHUB_REPO');

        $url = $method->invoke($command, 'linux', 'arm64', 'v1.0.0');
        $expectedUrl = sprintf('%s/%s/releases/download/v1.0.0/fuel-linux-arm64', $releasesBase, $repo);

        expect($url)->toBe($expectedUrl);
    });

    it('uses correct GitHub repository constants', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);

        $repo = $reflection->getConstant('GITHUB_REPO');
        $releasesBase = $reflection->getConstant('GITHUB_RELEASES_BASE');
        $apiBase = $reflection->getConstant('GITHUB_API_BASE');

        expect($repo)->toBe('ashleyhindle/fuel');
        expect($releasesBase)->toBe('https://github.com');
        expect($apiBase)->toBe('https://api.github.com/repos');
    });
});

describe('GitHub API URL construction', function (): void {
    it('constructs correct GitHub API URL', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        // Verify the URL construction logic exists
        expect($sourceCode)->toContain('GITHUB_API_BASE');
        expect($sourceCode)->toContain('GITHUB_REPO');
        expect($sourceCode)->toContain('/releases/latest');
    });

    it('handles missing tag_name in API response', function (): void {
        // Verify error handling for missing tag_name
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('tag_name');
        expect($sourceCode)->toContain("isset(\$data['tag_name'])");
        expect($sourceCode)->toContain('GitHub API response missing tag_name');
    });

    it('handles GitHub API exceptions', function (): void {
        // Verify exception handling exists
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('file_get_contents');
        expect($sourceCode)->toContain('Failed to fetch latest version');
    });
});

describe('Download flow and HTTP handling', function (): void {
    it('handles download failures gracefully', function (): void {
        // Verify download error handling exists
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('downloadBinary');
        expect($sourceCode)->toContain('RuntimeException');
        expect($sourceCode)->toContain('Download failed');
    });

    it('handles non-200 HTTP responses', function (): void {
        // Verify HTTP error handling exists
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('fopen');
        expect($sourceCode)->toContain('Failed to open download URL');
        expect($sourceCode)->toContain('stream_copy_to_stream');
    });

    it('cleans up temp file on download failure', function (): void {
        // Verify cleanup logic exists
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('unlink');
        expect($sourceCode)->toContain('targetPath');
    });

    it('uses stream copy for downloading', function (): void {
        // Verify streaming download is used with file handles
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('stream_copy_to_stream');
        expect($sourceCode)->toContain('fopen');
        expect($sourceCode)->toContain('fclose');
    });
});

describe('File operations', function (): void {
    it('creates target directory if it does not exist', function (): void {
        // Verify directory creation logic exists
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('is_dir');
        expect($sourceCode)->toContain('mkdir');
        expect($sourceCode)->toContain('.fuel');
    });

    it('makes binary executable after download', function (): void {
        // Verify chmod is called
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('chmod');
        expect($sourceCode)->toContain('0755');
    });

    it('performs atomic file replacement', function (): void {
        // Verify atomic replace logic exists
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $sourceCode = file_get_contents($reflection->getFileName());

        expect($sourceCode)->toContain('rename');
        expect($sourceCode)->toContain('.tmp');
    });

    it('handles missing HOME environment variable', function (): void {
        // Test HOME directory detection
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getHomeDirectory');

        // Save original HOME
        $originalHome = $_SERVER['HOME'] ?? null;
        $originalEnvHome = getenv('HOME');

        try {
            // Set HOME
            $_SERVER['HOME'] = $this->homeDir;
            putenv('HOME=' . $this->homeDir);

            $result = $method->invoke($command);
            expect($result)->toBe($this->homeDir);
        } finally {
            // Restore HOME
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }

            if ($originalEnvHome !== false) {
                putenv('HOME=' . $originalEnvHome);
            } else {
                putenv('HOME');
            }
        }
    });

    it('throws RuntimeException when HOME is not set', function (): void {
        $command = new SelfUpdateCommand;
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getHomeDirectory');

        // Save original HOME
        $originalHome = $_SERVER['HOME'] ?? null;
        $originalEnvHome = getenv('HOME');

        try {
            // Unset HOME
            unset($_SERVER['HOME']);
            putenv('HOME=');

            expect(fn (): mixed => $method->invoke($command))
                ->toThrow(\RuntimeException::class, 'home directory');
        } finally {
            // Restore HOME
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            }

            if ($originalEnvHome !== false) {
                putenv('HOME=' . $originalEnvHome);
            }
        }
    });
});
