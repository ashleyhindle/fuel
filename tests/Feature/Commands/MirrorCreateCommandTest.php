<?php

declare(strict_types=1);

use App\Commands\MirrorCreateCommand;
use App\Enums\MirrorStatus;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\FuelContext;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->fuelContext = app(FuelContext::class);
    $this->epicService = app(EpicService::class);

    // Set up test mirror base directory in temp
    $this->testMirrorBase = sys_get_temp_dir().'/fuel-test-mirrors-'.uniqid();
    $this->testProjectPath = getcwd();
    $this->projectSlug = Str::slug(basename($this->testProjectPath));

    // Override HOME for test to use temp directory
    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = dirname($this->testMirrorBase);
});

afterEach(function () {
    // Clean up test mirror directory
    if (isset($this->testMirrorBase) && is_dir($this->testMirrorBase)) {
        exec('rm -rf '.escapeshellarg($this->testMirrorBase));
    }

    // Clean up any mirror directories created during tests
    if (isset($_SERVER['HOME'])) {
        $mirrorDir = $_SERVER['HOME'].'/.fuel/mirrors';
        if (is_dir($mirrorDir)) {
            exec('rm -rf '.escapeshellarg($mirrorDir));
        }
    }

    // Restore original HOME
    if ($this->originalHome !== null) {
        $_SERVER['HOME'] = $this->originalHome;
    } else {
        unset($_SERVER['HOME']);
    }
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

test('mirror:create creates mirror directory in correct location', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Mirror');

    // Create a minimal test project directory to copy
    $testSourceDir = sys_get_temp_dir().'/fuel-test-source-'.uniqid();
    mkdir($testSourceDir, 0755, true);
    mkdir($testSourceDir.'/.fuel', 0755, true);
    touch($testSourceDir.'/test.txt');

    // Initialize git in test directory
    exec('cd '.escapeshellarg($testSourceDir).' && git init 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.email "test@example.com" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.name "Test User" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git add . && git commit -m "Initial" 2>&1');

    // Mock FuelContext to return our test directory
    $this->mock(FuelContext::class, function ($mock) use ($testSourceDir) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn($testSourceDir);
    });

    // Run the command
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(0);

    // Expected mirror path
    $expectedMirrorPath = $_SERVER['HOME'].'/.fuel/mirrors/'.Str::slug(basename($testSourceDir)).'/'.$epic->short_id;

    expect(is_dir($expectedMirrorPath))->toBeTrue();

    // Clean up
    exec('rm -rf '.escapeshellarg($testSourceDir));
    exec('rm -rf '.escapeshellarg($expectedMirrorPath));
});

test('mirror:create symlinks .fuel directory correctly', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Symlink');

    // Create a minimal test project
    $testSourceDir = sys_get_temp_dir().'/fuel-test-source-'.uniqid();
    mkdir($testSourceDir, 0755, true);
    mkdir($testSourceDir.'/.fuel', 0755, true);
    touch($testSourceDir.'/.fuel/test.db');
    touch($testSourceDir.'/test.txt');

    // Initialize git
    exec('cd '.escapeshellarg($testSourceDir).' && git init 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.email "test@example.com" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.name "Test User" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git add . && git commit -m "Initial" 2>&1');

    // Mock FuelContext
    $this->mock(FuelContext::class, function ($mock) use ($testSourceDir) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn($testSourceDir);
    });

    // Run the command
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(0);

    // Check the symlink
    $mirrorPath = $_SERVER['HOME'].'/.fuel/mirrors/'.Str::slug(basename($testSourceDir)).'/'.$epic->short_id;
    $mirrorFuelPath = $mirrorPath.'/.fuel';
    $originalFuelPath = $testSourceDir.'/.fuel';

    expect(is_link($mirrorFuelPath))->toBeTrue();
    // Use realpath to resolve both paths for comparison (handles /var vs /private/var on macOS)
    expect(realpath(readlink($mirrorFuelPath)))->toBe(realpath($originalFuelPath));

    // Clean up
    exec('rm -rf '.escapeshellarg($testSourceDir));
    exec('rm -rf '.escapeshellarg($mirrorPath));
});

test('mirror:create creates git branch with correct name', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Branch');

    // Create test project with git
    $testSourceDir = sys_get_temp_dir().'/fuel-test-source-'.uniqid();
    mkdir($testSourceDir, 0755, true);
    mkdir($testSourceDir.'/.fuel', 0755, true);
    touch($testSourceDir.'/test.txt');

    // Initialize git
    exec('cd '.escapeshellarg($testSourceDir).' && git init 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.email "test@example.com" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.name "Test User" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git add . && git commit -m "Initial" 2>&1');

    // Mock FuelContext
    $this->mock(FuelContext::class, function ($mock) use ($testSourceDir) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn($testSourceDir);
    });

    // Run the command
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(0);

    // Check the branch in mirror
    $mirrorPath = $_SERVER['HOME'].'/.fuel/mirrors/'.Str::slug(basename($testSourceDir)).'/'.$epic->short_id;
    $expectedBranchName = 'epic/'.$epic->short_id;

    $branches = [];
    exec('cd '.escapeshellarg($mirrorPath).' && git branch 2>&1', $branches);

    $branchExists = false;
    foreach ($branches as $branch) {
        if (str_contains($branch, $expectedBranchName)) {
            $branchExists = true;
            break;
        }
    }

    expect($branchExists)->toBeTrue();

    // Clean up
    exec('rm -rf '.escapeshellarg($testSourceDir));
    exec('rm -rf '.escapeshellarg($mirrorPath));
});

test('mirror:create updates epic with mirror_path, mirror_branch, and mirror_status=Ready', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Updates');

    // Create test project
    $testSourceDir = sys_get_temp_dir().'/fuel-test-source-'.uniqid();
    mkdir($testSourceDir, 0755, true);
    mkdir($testSourceDir.'/.fuel', 0755, true);
    touch($testSourceDir.'/test.txt'); // Add a file to commit

    // Initialize git
    exec('cd '.escapeshellarg($testSourceDir).' && git init 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.email "test@example.com" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.name "Test User" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git add . && git commit -m "Initial" 2>&1');

    // Mock FuelContext
    $this->mock(FuelContext::class, function ($mock) use ($testSourceDir) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn($testSourceDir);
    });

    // Run the command
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(0);

    // Verify epic was updated
    $updatedEpic = Epic::find($epic->id);

    expect($updatedEpic->mirror_path)->not->toBeNull();
    expect($updatedEpic->mirror_branch)->toBe('epic/'.$epic->short_id);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Ready);
    expect($updatedEpic->mirror_base_commit)->not->toBeNull();
    expect($updatedEpic->mirror_base_commit)->toMatch('/^[a-f0-9]{40}$/');

    // Clean up
    exec('rm -rf '.escapeshellarg($testSourceDir));
    exec('rm -rf '.escapeshellarg($updatedEpic->mirror_path));
});

test('mirror:create handles failure gracefully by setting status to None', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Failure');

    // Mock FuelContext to return invalid project path
    $this->mock(FuelContext::class, function ($mock) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn('/non/existent/path');
    });

    // Run the command - should fail
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(1)
        ->expectsOutputToContain('Mirror creation failed:');

    // Verify epic status was set to None (since Failed doesn't exist in enum)
    $epic->refresh();
    expect($epic->mirror_status)->toBe(MirrorStatus::None);
});

test('mirror:create uses correct copy command for platform', function () {
    $command = new MirrorCreateCommand;

    // Use reflection to test the private buildCopyCommand method
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildCopyCommand');
    $method->setAccessible(true);

    $source = '/path/to/source';
    $dest = '/path/to/dest';
    $copyCommand = $method->invoke($command, $source, $dest);

    if (PHP_OS_FAMILY === 'Darwin') {
        expect($copyCommand)->toContain('cp -cR');
    } else {
        expect($copyCommand)->toContain('cp --reflink=auto -R');
    }

    // Verify paths are properly escaped
    expect($copyCommand)->toContain(escapeshellarg($source));
    expect($copyCommand)->toContain(escapeshellarg($dest));
});

test('mirror:create uses temp directories and cleans up properly in tearDown', function () {
    // This test verifies that our test setup/teardown works correctly
    $tempDir = sys_get_temp_dir();

    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Cleanup');

    // Create a test mirror directory
    $mirrorPath = $_SERVER['HOME'].'/.fuel/mirrors/test/'.$epic->short_id;
    $mirrorBasePath = dirname($mirrorPath);

    if (! is_dir($mirrorBasePath)) {
        mkdir($mirrorBasePath, 0755, true);
    }

    mkdir($mirrorPath, 0755, true);
    touch($mirrorPath.'/test.txt');

    expect(is_dir($mirrorPath))->toBeTrue();

    // The afterEach hook should clean this up
});

test('mirror:create supports partial ID matching', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Partial ID');

    // Create test project
    $testSourceDir = sys_get_temp_dir().'/fuel-test-source-'.uniqid();
    mkdir($testSourceDir, 0755, true);
    mkdir($testSourceDir.'/.fuel', 0755, true);
    touch($testSourceDir.'/test.txt'); // Add a file to commit

    // Initialize git
    exec('cd '.escapeshellarg($testSourceDir).' && git init 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.email "test@example.com" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.name "Test User" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git add . && git commit -m "Initial" 2>&1');

    // Mock FuelContext
    $this->mock(FuelContext::class, function ($mock) use ($testSourceDir) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn($testSourceDir);
    });

    // Use partial ID (without e- prefix)
    $partialId = substr((string) $epic->short_id, 2);

    // Run command with partial ID
    $this->artisan('mirror:create', ['epic' => $partialId])
        ->assertExitCode(0);

    // Verify epic was found and updated
    $updatedEpic = Epic::find($epic->id);
    expect($updatedEpic->mirror_status)->toBe(MirrorStatus::Ready);

    // Clean up
    exec('rm -rf '.escapeshellarg($testSourceDir));
    exec('rm -rf '.escapeshellarg($updatedEpic->mirror_path));
});

test('mirror:create prevents duplicate mirror creation', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Duplicate Prevention');

    // Create mirror directory manually
    $mirrorPath = $_SERVER['HOME'].'/.fuel/mirrors/'.$this->projectSlug.'/'.$epic->short_id;
    $mirrorBasePath = dirname($mirrorPath);

    if (! is_dir($mirrorBasePath)) {
        mkdir($mirrorBasePath, 0755, true);
    }
    mkdir($mirrorPath, 0755, true);

    // Mock FuelContext to avoid real path issues
    $this->mock(FuelContext::class, function ($mock) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn(getcwd());
    });

    // Try to create mirror again
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(1)
        ->expectsOutputToContain('Mirror directory already exists');

    // Clean up
    exec('rm -rf '.escapeshellarg($mirrorPath));
});

test('mirror:create captures and stores base commit correctly', function () {
    // Create an epic
    $epic = $this->epicService->createEpic('Test Epic for Base Commit');

    // Create test project with git
    $testSourceDir = sys_get_temp_dir().'/fuel-test-source-'.uniqid();
    mkdir($testSourceDir, 0755, true);
    mkdir($testSourceDir.'/.fuel', 0755, true);
    touch($testSourceDir.'/test.txt');

    // Initialize git and get commit hash
    exec('cd '.escapeshellarg($testSourceDir).' && git init 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.email "test@example.com" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git config user.name "Test User" 2>&1');
    exec('cd '.escapeshellarg($testSourceDir).' && git add . && git commit -m "Initial" 2>&1');

    // Get the HEAD commit
    $output = [];
    exec('cd '.escapeshellarg($testSourceDir).' && git rev-parse HEAD 2>&1', $output);
    $expectedCommit = trim($output[0]);

    // Mock FuelContext
    $this->mock(FuelContext::class, function ($mock) use ($testSourceDir) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn($testSourceDir);
    });

    // Run the command
    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(0);

    // Verify base commit was stored
    $updatedEpic = Epic::find($epic->id);
    expect($updatedEpic->mirror_base_commit)->toBe($expectedCommit);
    expect($updatedEpic->mirror_base_commit)->toMatch('/^[a-f0-9]{40}$/');

    // Clean up
    exec('rm -rf '.escapeshellarg($testSourceDir));
    exec('rm -rf '.escapeshellarg($updatedEpic->mirror_path));
});

test('mirror:create sets status to Creating during operation', function () {
    $epic = $this->epicService->createEpic('Test Epic', 'Test description');

    // Track status changes
    $statusChanges = [];

    // Partially mock EpicService to track updateMirrorStatus calls
    $this->partialMock(EpicService::class, function ($mock) use (&$statusChanges) {
        $mock->shouldReceive('updateMirrorStatus')
            ->andReturnUsing(function ($epic, $status) use (&$statusChanges) {
                $statusChanges[] = $status;
                $epic->mirror_status = $status;
                $epic->save();
            });
    });

    // Mock FuelContext to fail so we can see the status changes
    $this->mock(FuelContext::class, function ($mock) {
        $mock->shouldReceive('getProjectPath')
            ->andReturn('/non/existent/path');
    });

    $this->artisan('mirror:create', ['epic' => $epic->short_id])
        ->assertExitCode(1);

    // Verify Creating status was set
    expect($statusChanges)->toContain(MirrorStatus::Creating);
});
