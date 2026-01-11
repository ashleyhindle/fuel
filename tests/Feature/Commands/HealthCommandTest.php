<?php

use App\Enums\FailureType;
use App\Services\AgentHealthTracker;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';

    $this->database = new DatabaseService($this->dbPath);
    $this->database->initialize();

    $this->tracker = new AgentHealthTracker($this->database);

    // Bind our test instances
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $this->database);
    $this->app->singleton(AgentHealthTracker::class, fn (): AgentHealthTracker => $this->tracker);
});

afterEach(function (): void {
    // Clean up temp files - SQLite may create .db-shm and .db-wal files
    $fuelDir = $this->tempDir.'/.fuel';
    if (is_dir($fuelDir)) {
        $files = glob($fuelDir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($fuelDir);
    }

    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('displays health status table when agents exist', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('cursor-agent', FailureType::Network);

    Artisan::call('health');
    $output = Artisan::output();

    expect($output)->toContain('Agent Health Status:');
    expect($output)->toContain('claude');
    expect($output)->toContain('cursor-agent');
    expect($output)->toContain('healthy');
    expect($output)->toContain('backoff');
});

it('displays message when no agents tracked', function (): void {
    Artisan::call('health');
    $output = Artisan::output();

    expect($output)->toContain('No agent health data available');
});

it('outputs JSON when --json flag is used', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('cursor-agent', FailureType::Network);

    Artisan::call('health', ['--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect(count($data))->toBe(2);

    $claude = collect($data)->firstWhere('agent', 'claude');
    expect($claude)->not->toBeNull();
    expect($claude['status'])->toBe('healthy');
    expect($claude['consecutive_failures'])->toBe(0);
    expect($claude['success_rate'])->toBe(100);

    $cursor = collect($data)->firstWhere('agent', 'cursor-agent');
    expect($cursor)->not->toBeNull();
    expect($cursor['status'])->toBe('backoff');
    expect($cursor['consecutive_failures'])->toBe(1);
});

it('shows dead status for agents with 5+ consecutive failures', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->recordFailure('dead-agent', FailureType::Network);
    }

    Artisan::call('health');
    $output = Artisan::output();

    expect($output)->toContain('dead-agent');
    expect($output)->toContain('dead');
});

it('displays success rate correctly', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);
    $this->tracker->recordSuccess('claude');

    Artisan::call('health');
    $output = Artisan::output();

    // 3 successes out of 4 runs = 75%
    expect($output)->toContain('75.0%');
});

it('displays backoff remaining time', function (): void {
    $this->tracker->recordFailure('claude', FailureType::Network);

    Artisan::call('health');
    $output = Artisan::output();

    // Should show backoff time (e.g., "30s" or similar)
    expect($output)->toMatch('/\d+[smh]/');
});

it('displays last success and failure times', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);

    Artisan::call('health');
    $output = Artisan::output();

    // Should contain date/time format YYYY-MM-DD HH:MM:SS
    expect($output)->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
});

it('shows dash for missing data', function (): void {
    // New agent with no runs
    $this->tracker->recordSuccess('claude');

    Artisan::call('health');
    $output = Artisan::output();

    // Last failure should be "-" for agent with only successes
    expect($output)->toContain('claude');
});

it('includes all required fields in JSON output', function (): void {
    $this->tracker->recordSuccess('claude');
    $this->tracker->recordFailure('claude', FailureType::Network);

    Artisan::call('health', ['--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    $agent = $data[0];
    expect($agent)->toHaveKeys([
        'agent',
        'status',
        'consecutive_failures',
        'backoff_remaining_seconds',
        'success_rate',
        'last_success_at',
        'last_failure_at',
        'total_runs',
        'total_successes',
    ]);
});
