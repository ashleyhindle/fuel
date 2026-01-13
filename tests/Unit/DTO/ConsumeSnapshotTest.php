<?php

declare(strict_types=1);

use App\DTO\ConsumeSnapshot;
use App\Process\AgentHealth;
use App\Process\AgentProcess;

describe('ConsumeSnapshot', function () {
    it('can be serialized to JSON', function () {
        $boardData = [
            'ready' => collect([]),
            'in_progress' => collect([]),
            'review' => collect([]),
            'blocked' => collect([]),
            'human' => collect([]),
            'done' => collect([]),
        ];

        $snapshot = new ConsumeSnapshot(
            boardState: $boardData,
            activeProcesses: [],
            healthSummary: [],
            runnerState: [
                'paused' => false,
                'started_at' => time(),
                'instance_id' => 'test-instance',
            ],
            config: [
                'interval_seconds' => 5,
                'agents' => [
                    'test-agent' => ['max_concurrent' => 2],
                ],
            ],
        );

        $json = json_encode($snapshot);
        expect($json)->not->toBeNull();

        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded)->toHaveKeys(['board_state', 'active_processes', 'health_summary', 'runner_state', 'config']);
    });

    it('creates snapshot from board data', function () {
        $boardData = [
            'ready' => collect([]),
            'in_progress' => collect([]),
            'review' => collect([]),
            'blocked' => collect([]),
            'human' => collect([]),
            'done' => collect([]),
        ];

        // Create a real AgentProcess (final class can't be mocked)
        $symfonyProcess = new \Symfony\Component\Process\Process(['echo', 'test']);
        $agentProcess = new AgentProcess(
            process: $symfonyProcess,
            taskId: 'f-abc123',
            agentName: 'test-agent',
            startTime: time(),
            runId: 'run-xyz',
        );

        $mockHealth = AgentHealth::forNewAgent('test-agent');

        $snapshot = ConsumeSnapshot::fromBoardData(
            boardData: $boardData,
            activeProcesses: [$agentProcess],
            healthStatuses: [$mockHealth],
            paused: false,
            startedAt: time(),
            instanceId: 'test-instance',
            intervalSeconds: 5,
            agentLimits: ['test-agent' => 2],
        );

        expect($snapshot)->toBeInstanceOf(ConsumeSnapshot::class);
        expect($snapshot->activeProcesses)->toHaveCount(1);
        expect($snapshot->activeProcesses[0]['task_id'])->toBe('f-abc123');
        expect($snapshot->healthSummary)->toHaveKey('test-agent');
        expect($snapshot->runnerState['instance_id'])->toBe('test-instance');
        expect($snapshot->config['interval_seconds'])->toBe(5);
    });

    it('serializes board state with task collections', function () {
        $task1 = (object) ['short_id' => 'f-123', 'title' => 'Test Task'];
        $task2 = (object) ['short_id' => 'f-456', 'title' => 'Another Task'];

        $boardData = [
            'ready' => collect([$task1]),
            'in_progress' => collect([$task2]),
            'review' => collect([]),
            'blocked' => collect([]),
            'human' => collect([]),
            'done' => collect([]),
        ];

        $snapshot = new ConsumeSnapshot(
            boardState: $boardData,
            activeProcesses: [],
            healthSummary: [],
            runnerState: [
                'paused' => false,
                'started_at' => time(),
                'instance_id' => 'test-instance',
            ],
            config: [
                'interval_seconds' => 5,
                'agents' => [],
            ],
        );

        $json = json_encode($snapshot);
        $decoded = json_decode($json, true);

        expect($decoded['board_state']['ready'])->toHaveCount(1);
        expect($decoded['board_state']['in_progress'])->toHaveCount(1);
    });

    it('includes health summary with correct structure', function () {
        $boardData = [
            'ready' => collect([]),
            'in_progress' => collect([]),
            'review' => collect([]),
            'blocked' => collect([]),
            'human' => collect([]),
            'done' => collect([]),
        ];

        $mockHealth = AgentHealth::forNewAgent('healthy-agent');

        $snapshot = ConsumeSnapshot::fromBoardData(
            boardData: $boardData,
            activeProcesses: [],
            healthStatuses: [$mockHealth],
            paused: false,
            startedAt: time(),
            instanceId: 'test-instance',
            intervalSeconds: 5,
            agentLimits: ['healthy-agent' => 2],
        );

        expect($snapshot->healthSummary)->toHaveKey('healthy-agent');
        expect($snapshot->healthSummary['healthy-agent'])->toHaveKeys([
            'status',
            'consecutive_failures',
            'in_backoff',
            'is_dead',
            'backoff_seconds',
        ]);
    });

    it('includes runner state with correct structure', function () {
        $boardData = [
            'ready' => collect([]),
            'in_progress' => collect([]),
            'review' => collect([]),
            'blocked' => collect([]),
            'human' => collect([]),
            'done' => collect([]),
        ];

        $startedAt = time();
        $snapshot = ConsumeSnapshot::fromBoardData(
            boardData: $boardData,
            activeProcesses: [],
            healthStatuses: [],
            paused: true,
            startedAt: $startedAt,
            instanceId: 'runner-123',
            intervalSeconds: 10,
            agentLimits: [],
        );

        expect($snapshot->runnerState['paused'])->toBeTrue();
        expect($snapshot->runnerState['started_at'])->toBe($startedAt);
        expect($snapshot->runnerState['instance_id'])->toBe('runner-123');
    });

    it('includes config with interval and agents', function () {
        $boardData = [
            'ready' => collect([]),
            'in_progress' => collect([]),
            'review' => collect([]),
            'blocked' => collect([]),
            'human' => collect([]),
            'done' => collect([]),
        ];

        $snapshot = ConsumeSnapshot::fromBoardData(
            boardData: $boardData,
            activeProcesses: [],
            healthStatuses: [],
            paused: false,
            startedAt: time(),
            instanceId: 'test',
            intervalSeconds: 15,
            agentLimits: [
                'agent1' => 2,
                'agent2' => 4,
            ],
        );

        expect($snapshot->config['interval_seconds'])->toBe(15);
        expect($snapshot->config['agents'])->toHaveKeys(['agent1', 'agent2']);
        expect($snapshot->config['agents']['agent1']['max_concurrent'])->toBe(2);
        expect($snapshot->config['agents']['agent2']['max_concurrent'])->toBe(4);
    });
});
