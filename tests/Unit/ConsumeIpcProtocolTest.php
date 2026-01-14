<?php

declare(strict_types=1);

use App\DTO\ConsumeSnapshot;
use App\Enums\ConsumeCommandType;
use App\Enums\ConsumeEventType;
use App\Ipc\Commands\AttachCommand;
use App\Ipc\Commands\DetachCommand;
use App\Ipc\Commands\PauseCommand;
use App\Ipc\Commands\ReloadConfigCommand;
use App\Ipc\Commands\RequestSnapshotCommand;
use App\Ipc\Commands\ResumeCommand;
use App\Ipc\Commands\StopCommand;
use App\Ipc\Events\ErrorEvent;
use App\Ipc\Events\HealthChangeEvent;
use App\Ipc\Events\HelloEvent;
use App\Ipc\Events\OutputChunkEvent;
use App\Ipc\Events\ReviewCompletedEvent;
use App\Ipc\Events\SnapshotEvent;
use App\Ipc\Events\StatusLineEvent;
use App\Ipc\Events\TaskCompletedEvent;
use App\Ipc\Events\TaskSpawnedEvent;
use App\Services\ConsumeIpcProtocol;

beforeEach(function (): void {
    $this->protocol = new ConsumeIpcProtocol;
    $this->instanceId = 'test-instance-123';
});

describe('encode', function (): void {
    test('encodes message to JSON line with newline', function (): void {
        $event = new HelloEvent('1.0.0', $this->instanceId);
        $encoded = $this->protocol->encode($event);

        expect($encoded)->toEndWith("\n");
        expect(json_decode(trim($encoded), true))->toBeArray();
    });

    test('encodes all message fields correctly', function (): void {
        $event = new HelloEvent('1.0.0', $this->instanceId);
        $encoded = $this->protocol->encode($event);
        $decoded = json_decode(trim($encoded), true);

        expect($decoded)
            ->toHaveKey('type', ConsumeEventType::Hello->value)
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('version', '1.0.0')
            ->toHaveKey('timestamp');
    });
});

describe('decode', function (): void {
    test('decodes valid JSON to IpcMessage', function (): void {
        $line = json_encode([
            'type' => 'hello',
            'instance_id' => $this->instanceId,
            'version' => '1.0.0',
            'timestamp' => '2024-01-01T00:00:00+00:00',
        ]);

        $message = $this->protocol->decode($line, $this->instanceId);

        expect($message)->toBeInstanceOf(HelloEvent::class);
        expect($message->type())->toBe(ConsumeEventType::Hello->value);
    });

    test('returns ErrorEvent for malformed JSON', function (): void {
        $line = 'not valid json {';
        $message = $this->protocol->decode($line, $this->instanceId);

        expect($message)->toBeInstanceOf(ErrorEvent::class);
        expect($message->message())->toContain('Malformed JSON');
    });

    test('returns ErrorEvent for non-object JSON', function (): void {
        $line = json_encode('string value');
        $message = $this->protocol->decode($line, $this->instanceId);

        expect($message)->toBeInstanceOf(ErrorEvent::class);
        expect($message->message())->toContain('Expected JSON object');
    });

    test('returns ErrorEvent for missing type field', function (): void {
        $line = json_encode(['instance_id' => $this->instanceId]);
        $message = $this->protocol->decode($line, $this->instanceId);

        expect($message)->toBeInstanceOf(ErrorEvent::class);
        expect($message->message())->toContain('Missing required field: type');
    });

    test('returns ErrorEvent for unknown type', function (): void {
        $line = json_encode(['type' => 'unknown_type', 'instance_id' => $this->instanceId]);
        $message = $this->protocol->decode($line, $this->instanceId);

        expect($message)->toBeInstanceOf(ErrorEvent::class);
        expect($message->message())->toContain('Unknown message type');
    });

    test('strips trailing newlines and whitespace', function (): void {
        $line = json_encode([
            'type' => 'hello',
            'instance_id' => $this->instanceId,
            'version' => '1.0.0',
        ])." \n\r\n";

        $message = $this->protocol->decode($line, $this->instanceId);

        expect($message)->toBeInstanceOf(HelloEvent::class);
    });
});

describe('round-trip command types', function (): void {
    test('AttachCommand', function (): void {
        $original = new AttachCommand(
            last_event_id: 12345,
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId,
            requestId: 'req-123'
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(AttachCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::Attach->value);
        expect($decoded->last_event_id)->toBe(12345);
    });

    test('DetachCommand', function (): void {
        $original = new DetachCommand(
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(DetachCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::Detach->value);
    });

    test('PauseCommand', function (): void {
        $original = new PauseCommand(
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(PauseCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::Pause->value);
    });

    test('ResumeCommand', function (): void {
        $original = new ResumeCommand(
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(ResumeCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::Resume->value);
    });

    test('StopCommand', function (): void {
        $original = new StopCommand(
            mode: 'graceful',
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(StopCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::Stop->value);
        expect($decoded->mode)->toBe('graceful');
    });

    test('ReloadConfigCommand', function (): void {
        $original = new ReloadConfigCommand(
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(ReloadConfigCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::ReloadConfig->value);
    });

    test('RequestSnapshotCommand', function (): void {
        $original = new RequestSnapshotCommand(
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(RequestSnapshotCommand::class);
        expect($decoded->type())->toBe(ConsumeCommandType::RequestSnapshot->value);
    });
});

describe('round-trip event types', function (): void {
    test('HelloEvent', function (): void {
        $original = new HelloEvent(
            version: '1.0.0',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(HelloEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::Hello->value);
        expect($decoded->version())->toBe('1.0.0');
    });

    test('SnapshotEvent', function (): void {
        $snapshot = new ConsumeSnapshot(
            boardState: [
                'ready' => collect([]),
                'in_progress' => collect([]),
                'review' => collect([]),
                'blocked' => collect([]),
                'human' => collect([]),
                'done' => collect([]),
            ],
            activeProcesses: [
                ['task_id' => 'f-abc123', 'run_id' => 'run-1', 'agent' => 'claude', 'pid' => 1234, 'started_at' => time(), 'last_output_time' => null],
            ],
            healthSummary: ['claude' => ['status' => 'healthy', 'consecutive_failures' => 0, 'in_backoff' => false, 'is_dead' => false, 'backoff_seconds' => 0]],
            runnerState: ['paused' => false, 'started_at' => time(), 'instance_id' => $this->instanceId],
            config: ['interval_seconds' => 5, 'agents' => ['claude' => ['max_concurrent' => 2]]]
        );

        $original = new SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(SnapshotEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::Snapshot->value);
        expect($decoded->snapshot())->toBeInstanceOf(ConsumeSnapshot::class);
        expect($decoded->snapshot()->activeProcesses)->toHaveCount(1);
    });

    test('StatusLineEvent', function (): void {
        $original = new StatusLineEvent(
            level: 'info',
            text: 'Test message',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(StatusLineEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::StatusLine->value);
        expect($decoded->level())->toBe('info');
        expect($decoded->text())->toBe('Test message');
    });

    test('TaskSpawnedEvent', function (): void {
        $original = new TaskSpawnedEvent(
            taskId: 'f-abc123',
            runId: 'run-1',
            agent: 'claude',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(TaskSpawnedEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::TaskSpawned->value);
        expect($decoded->taskId())->toBe('f-abc123');
        expect($decoded->runId())->toBe('run-1');
        expect($decoded->agent())->toBe('claude');
    });

    test('TaskCompletedEvent', function (): void {
        $original = new TaskCompletedEvent(
            taskId: 'f-abc123',
            runId: 'run-1',
            exitCode: 0,
            completionType: 'success',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(TaskCompletedEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::TaskCompleted->value);
        expect($decoded->taskId())->toBe('f-abc123');
        expect($decoded->exitCode())->toBe(0);
        expect($decoded->completionType())->toBe('success');
    });

    test('HealthChangeEvent', function (): void {
        $original = new HealthChangeEvent(
            agent: 'claude',
            status: 'healthy',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(HealthChangeEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::HealthChange->value);
        expect($decoded->agent())->toBe('claude');
        expect($decoded->status())->toBe('healthy');
    });

    test('OutputChunkEvent', function (): void {
        $original = new OutputChunkEvent(
            taskId: 'f-abc123',
            runId: 'run-1',
            stream: 'stdout',
            chunk: 'test output',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(OutputChunkEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::OutputChunk->value);
        expect($decoded->taskId())->toBe('f-abc123');
        expect($decoded->stream())->toBe('stdout');
        expect($decoded->chunk())->toBe('test output');
    });

    test('ReviewCompletedEvent', function (): void {
        $original = new ReviewCompletedEvent(
            taskId: 'f-abc123',
            passed: true,
            issues: [],
            wasAlreadyDone: false,
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(ReviewCompletedEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::ReviewCompleted->value);
        expect($decoded->taskId())->toBe('f-abc123');
        expect($decoded->passed())->toBeTrue();
        expect($decoded->issues())->toBe([]);
        expect($decoded->wasAlreadyDone())->toBeFalse();
    });

    test('ErrorEvent', function (): void {
        $original = new ErrorEvent(
            message: 'Test error',
            instanceId: $this->instanceId
        );

        $encoded = $this->protocol->encode($original);
        $decoded = $this->protocol->decode($encoded, $this->instanceId);

        expect($decoded)->toBeInstanceOf(ErrorEvent::class);
        expect($decoded->type())->toBe(ConsumeEventType::Error->value);
        expect($decoded->message())->toBe('Test error');
    });
});

describe('generateRequestId', function (): void {
    test('generates valid UUID v4', function (): void {
        $id = $this->protocol->generateRequestId();

        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    test('generates unique IDs', function (): void {
        $id1 = $this->protocol->generateRequestId();
        $id2 = $this->protocol->generateRequestId();

        expect($id1)->not->toBe($id2);
    });
});

describe('generateInstanceId', function (): void {
    test('generates valid UUID v4', function (): void {
        $id = $this->protocol->generateInstanceId();

        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    test('generates unique IDs', function (): void {
        $id1 = $this->protocol->generateInstanceId();
        $id2 = $this->protocol->generateInstanceId();

        expect($id1)->not->toBe($id2);
    });
});
