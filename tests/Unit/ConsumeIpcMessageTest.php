<?php

declare(strict_types=1);

use App\Enums\ConsumeCommandType;
use App\Enums\ConsumeEventType;
use App\Ipc\Commands\AttachCommand;
use App\Ipc\Commands\DetachCommand;
use App\Ipc\Commands\PauseCommand;
use App\Ipc\Commands\ReloadConfigCommand;
use App\Ipc\Commands\RequestSnapshotCommand;
use App\Ipc\Commands\ResumeCommand;
use App\Ipc\Commands\SetIntervalCommand;
use App\Ipc\Commands\StopCommand;
use App\Ipc\Events\ErrorEvent;
use App\Ipc\Events\HealthChangeEvent;
use App\Ipc\Events\HelloEvent;
use App\Ipc\Events\OutputChunkEvent;
use App\Ipc\Events\StatusLineEvent;
use App\Ipc\Events\TaskCompletedEvent;
use App\Ipc\Events\TaskSpawnedEvent;

beforeEach(function () {
    $this->instanceId = 'test-instance-123';
    $this->timestamp = new DateTimeImmutable('2024-01-01T00:00:00+00:00');
});

describe('Command DTOs serialize to expected JSON shape', function () {
    test('AttachCommand toArray includes all properties', function () {
        $command = new AttachCommand(
            last_event_id: 12345,
            timestamp: $this->timestamp,
            instanceId: $this->instanceId,
            requestId: 'req-123'
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::Attach->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', 'req-123')
            ->toHaveKey('last_event_id', 12345);
    });

    test('DetachCommand toArray includes all properties', function () {
        $command = new DetachCommand(
            timestamp: $this->timestamp,
            instanceId: $this->instanceId
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::Detach->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null);
    });

    test('PauseCommand toArray includes all properties', function () {
        $command = new PauseCommand(
            timestamp: $this->timestamp,
            instanceId: $this->instanceId
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::Pause->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null);
    });

    test('ResumeCommand toArray includes all properties', function () {
        $command = new ResumeCommand(
            timestamp: $this->timestamp,
            instanceId: $this->instanceId
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::Resume->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null);
    });

    test('StopCommand toArray includes all properties', function () {
        $command = new StopCommand(
            mode: 'graceful',
            timestamp: $this->timestamp,
            instanceId: $this->instanceId,
            requestId: 'req-456'
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::Stop->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', 'req-456')
            ->toHaveKey('mode', 'graceful');
    });

    test('ReloadConfigCommand toArray includes all properties', function () {
        $command = new ReloadConfigCommand(
            timestamp: $this->timestamp,
            instanceId: $this->instanceId
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::ReloadConfig->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null);
    });

    test('SetIntervalCommand toArray includes all properties', function () {
        $command = new SetIntervalCommand(
            interval_seconds: 10,
            timestamp: $this->timestamp,
            instanceId: $this->instanceId
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::SetInterval->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('interval_seconds', 10);
    });

    test('RequestSnapshotCommand toArray includes all properties', function () {
        $command = new RequestSnapshotCommand(
            timestamp: $this->timestamp,
            instanceId: $this->instanceId
        );

        $array = $command->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeCommandType::RequestSnapshot->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null);
    });
});

describe('Event DTOs serialize to expected JSON shape', function () {
    test('HelloEvent toArray includes all properties', function () {
        $event = new HelloEvent(
            version: '1.0.0',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp,
            requestId: 'req-789'
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::Hello->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', 'req-789')
            ->toHaveKey('version', '1.0.0');
    });

    test('StatusLineEvent toArray includes all properties', function () {
        $event = new StatusLineEvent(
            level: 'info',
            text: 'Test message',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::StatusLine->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('level', 'info')
            ->toHaveKey('text', 'Test message');
    });

    test('TaskSpawnedEvent toArray includes all properties', function () {
        $event = new TaskSpawnedEvent(
            taskId: 'f-abc123',
            runId: 'run-1',
            agent: 'claude',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::TaskSpawned->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('task_id', 'f-abc123')
            ->toHaveKey('run_id', 'run-1')
            ->toHaveKey('agent', 'claude');
    });

    test('TaskCompletedEvent toArray includes all properties', function () {
        $event = new TaskCompletedEvent(
            taskId: 'f-abc123',
            runId: 'run-1',
            exitCode: 0,
            completionType: 'success',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::TaskCompleted->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('task_id', 'f-abc123')
            ->toHaveKey('run_id', 'run-1')
            ->toHaveKey('exit_code', 0)
            ->toHaveKey('completion_type', 'success');
    });

    test('HealthChangeEvent toArray includes all properties', function () {
        $event = new HealthChangeEvent(
            agent: 'claude',
            status: 'healthy',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::HealthChange->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('agent', 'claude')
            ->toHaveKey('status', 'healthy');
    });

    test('OutputChunkEvent toArray includes all properties', function () {
        $event = new OutputChunkEvent(
            taskId: 'f-abc123',
            runId: 'run-1',
            stream: 'stdout',
            chunk: 'test output',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::OutputChunk->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('task_id', 'f-abc123')
            ->toHaveKey('run_id', 'run-1')
            ->toHaveKey('stream', 'stdout')
            ->toHaveKey('chunk', 'test output');
    });

    test('ErrorEvent toArray includes all properties', function () {
        $event = new ErrorEvent(
            message: 'Test error',
            instanceId: $this->instanceId,
            timestamp: $this->timestamp
        );

        $array = $event->toArray();

        expect($array)
            ->toBeArray()
            ->toHaveKey('type', ConsumeEventType::Error->value)
            ->toHaveKey('timestamp')
            ->toHaveKey('instance_id', $this->instanceId)
            ->toHaveKey('request_id', null)
            ->toHaveKey('message', 'Test error');
    });
});

describe('fromArray factory methods', function () {
    test('AttachCommand fromArray creates instance', function () {
        $data = [
            'last_event_id' => 12345,
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
            'request_id' => 'req-123',
        ];

        $command = AttachCommand::fromArray($data);

        expect($command)
            ->toBeInstanceOf(AttachCommand::class)
            ->last_event_id->toBe(12345);
        expect($command->instanceId())->toBe($this->instanceId);
        expect($command->requestId())->toBe('req-123');
    });

    test('DetachCommand fromArray creates instance', function () {
        $data = [
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
        ];

        $command = DetachCommand::fromArray($data);

        expect($command)->toBeInstanceOf(DetachCommand::class);
        expect($command->instanceId())->toBe($this->instanceId);
    });

    test('PauseCommand fromArray creates instance', function () {
        $data = [
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
        ];

        $command = PauseCommand::fromArray($data);

        expect($command)->toBeInstanceOf(PauseCommand::class);
        expect($command->instanceId())->toBe($this->instanceId);
    });

    test('ResumeCommand fromArray creates instance', function () {
        $data = [
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
        ];

        $command = ResumeCommand::fromArray($data);

        expect($command)->toBeInstanceOf(ResumeCommand::class);
        expect($command->instanceId())->toBe($this->instanceId);
    });

    test('StopCommand fromArray creates instance', function () {
        $data = [
            'mode' => 'graceful',
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
            'request_id' => 'req-456',
        ];

        $command = StopCommand::fromArray($data);

        expect($command)
            ->toBeInstanceOf(StopCommand::class)
            ->mode->toBe('graceful');
        expect($command->instanceId())->toBe($this->instanceId);
    });

    test('ReloadConfigCommand fromArray creates instance', function () {
        $data = [
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
        ];

        $command = ReloadConfigCommand::fromArray($data);

        expect($command)->toBeInstanceOf(ReloadConfigCommand::class);
        expect($command->instanceId())->toBe($this->instanceId);
    });

    test('SetIntervalCommand fromArray creates instance', function () {
        $data = [
            'interval_seconds' => 10,
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
        ];

        $command = SetIntervalCommand::fromArray($data);

        expect($command)
            ->toBeInstanceOf(SetIntervalCommand::class)
            ->interval_seconds->toBe(10);
        expect($command->instanceId())->toBe($this->instanceId);
    });

    test('RequestSnapshotCommand fromArray creates instance', function () {
        $data = [
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'instance_id' => $this->instanceId,
        ];

        $command = RequestSnapshotCommand::fromArray($data);

        expect($command)->toBeInstanceOf(RequestSnapshotCommand::class);
        expect($command->instanceId())->toBe($this->instanceId);
    });
});

describe('timestamp is ISO 8601 format', function () {
    it('formats timestamp in ISO 8601 format for commands', function (string $commandClass) {
        $reflection = new ReflectionClass($commandClass);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // Build minimal constructor args
        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if ($name === 'timestamp') {
                $args[$name] = $this->timestamp;
            } elseif ($name === 'instanceId') {
                $args[$name] = $this->instanceId;
            } elseif ($param->hasType() && $param->getType()->getName() === 'string') {
                $args[$name] = 'test-value';
            } elseif ($param->hasType() && $param->getType()->getName() === 'int') {
                $args[$name] = 123;
            } else {
                $args[$name] = null;
            }
        }

        $instance = new $commandClass(...$args);
        $array = $instance->toArray();

        expect($array['timestamp'])
            ->toBeString()
            ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    })->with([
        AttachCommand::class,
        DetachCommand::class,
        PauseCommand::class,
        ResumeCommand::class,
        StopCommand::class,
        ReloadConfigCommand::class,
        SetIntervalCommand::class,
        RequestSnapshotCommand::class,
    ]);

    it('formats timestamp in ISO 8601 format for events', function (string $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // Build minimal constructor args
        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if ($name === 'timestamp') {
                $args[$name] = $this->timestamp;
            } elseif ($name === 'instanceId') {
                $args[$name] = $this->instanceId;
            } elseif ($param->hasType() && $param->getType()->getName() === 'string') {
                $args[$name] = 'test-value';
            } elseif ($param->hasType() && $param->getType()->getName() === 'int') {
                $args[$name] = 123;
            } else {
                $args[$name] = null;
            }
        }

        $instance = new $eventClass(...$args);
        $array = $instance->toArray();

        expect($array['timestamp'])
            ->toBeString()
            ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    })->with([
        HelloEvent::class,
        StatusLineEvent::class,
        TaskSpawnedEvent::class,
        TaskCompletedEvent::class,
        HealthChangeEvent::class,
        OutputChunkEvent::class,
        ErrorEvent::class,
    ]);
});

describe('type field matches enum value', function () {
    it('command type matches enum value', function (string $commandClass, ConsumeCommandType $expectedType) {
        $reflection = new ReflectionClass($commandClass);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // Build minimal constructor args
        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if ($name === 'timestamp') {
                $args[$name] = $this->timestamp;
            } elseif ($name === 'instanceId') {
                $args[$name] = $this->instanceId;
            } elseif ($param->hasType() && $param->getType()->getName() === 'string') {
                $args[$name] = 'test-value';
            } elseif ($param->hasType() && $param->getType()->getName() === 'int') {
                $args[$name] = 123;
            } else {
                $args[$name] = null;
            }
        }

        $instance = new $commandClass(...$args);

        expect($instance->type())->toBe($expectedType->value);
    })->with([
        [AttachCommand::class, ConsumeCommandType::Attach],
        [DetachCommand::class, ConsumeCommandType::Detach],
        [PauseCommand::class, ConsumeCommandType::Pause],
        [ResumeCommand::class, ConsumeCommandType::Resume],
        [StopCommand::class, ConsumeCommandType::Stop],
        [ReloadConfigCommand::class, ConsumeCommandType::ReloadConfig],
        [SetIntervalCommand::class, ConsumeCommandType::SetInterval],
        [RequestSnapshotCommand::class, ConsumeCommandType::RequestSnapshot],
    ]);

    it('event type matches enum value', function (string $eventClass, ConsumeEventType $expectedType) {
        $reflection = new ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // Build minimal constructor args
        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if ($name === 'timestamp') {
                $args[$name] = $this->timestamp;
            } elseif ($name === 'instanceId') {
                $args[$name] = $this->instanceId;
            } elseif ($param->hasType() && $param->getType()->getName() === 'string') {
                $args[$name] = 'test-value';
            } elseif ($param->hasType() && $param->getType()->getName() === 'int') {
                $args[$name] = 123;
            } else {
                $args[$name] = null;
            }
        }

        $instance = new $eventClass(...$args);

        expect($instance->type())->toBe($expectedType->value);
    })->with([
        [HelloEvent::class, ConsumeEventType::Hello],
        [StatusLineEvent::class, ConsumeEventType::StatusLine],
        [TaskSpawnedEvent::class, ConsumeEventType::TaskSpawned],
        [TaskCompletedEvent::class, ConsumeEventType::TaskCompleted],
        [HealthChangeEvent::class, ConsumeEventType::HealthChange],
        [OutputChunkEvent::class, ConsumeEventType::OutputChunk],
        [ErrorEvent::class, ConsumeEventType::Error],
    ]);
});
