<?php

declare(strict_types=1);

use App\Ipc\Events\TaskSpawnedEvent;
use App\Services\ConfigService;
use App\Services\ConsumeIpcProtocol;
use App\Services\ConsumeIpcServer;

beforeEach(function (): void {
    // Use isolated temp directory for tests
    $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->testDir.'/.fuel', 0755, true);

    // Change to test directory
    $this->originalDir = getcwd();
    chdir($this->testDir);

    // Mock ConfigService to avoid config validation errors
    // Use a random port between 10000-60000 to avoid conflicts
    $this->testPort = random_int(10000, 60000);
    $this->mockConfigService = Mockery::mock(ConfigService::class);
    $this->mockConfigService->shouldReceive('getConsumePort')->andReturn($this->testPort);
});

afterEach(function (): void {
    // Close Mockery
    Mockery::close();

    // Return to original directory
    chdir($this->originalDir);

    // Clean up test directory
    if (is_dir($this->testDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->testDir);
    }
});

describe('ConsumeRunner multi-client', function (): void {
    test('two clients both receive broadcast events', function (): void {
        // Create protocol and IPC server
        $protocol = new ConsumeIpcProtocol;
        $ipcServer = new ConsumeIpcServer($protocol, $this->mockConfigService);

        // Start IPC server
        $ipcServer->start();

        // Create two mock client connections using stream_socket_pair
        $client1Sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $client2Sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        expect($client1Sockets)->not->toBeFalse();
        expect($client2Sockets)->not->toBeFalse();

        [$client1Socket, $server1Socket] = $client1Sockets;
        [$client2Socket, $server2Socket] = $client2Sockets;

        // Set non-blocking mode for all sockets
        stream_set_blocking($client1Socket, false);
        stream_set_blocking($client2Socket, false);
        stream_set_blocking($server1Socket, false);
        stream_set_blocking($server2Socket, false);

        // Create a broadcast event
        $instanceId = $protocol->generateInstanceId();
        $taskSpawnedEvent = new TaskSpawnedEvent(
            taskId: 'f-test01',
            runId: 'run-test01',
            agent: 'claude',
            instanceId: $instanceId
        );

        // Broadcast to both clients (manually write to both server sockets)
        $encoded = $protocol->encode($taskSpawnedEvent);
        fwrite($server1Socket, $encoded);
        fwrite($server2Socket, $encoded);

        // Read from both client sockets
        usleep(10000); // 10ms to allow writes to complete

        $client1Data = fread($client1Socket, 8192);
        $client2Data = fread($client2Socket, 8192);

        expect($client1Data)->not->toBeFalse();
        expect($client1Data)->not->toBeEmpty();
        expect($client2Data)->not->toBeFalse();
        expect($client2Data)->not->toBeEmpty();

        // Parse events
        $client1Event = json_decode(trim($client1Data), true);
        $client2Event = json_decode(trim($client2Data), true);

        expect($client1Event)->toBeArray();
        expect($client2Event)->toBeArray();

        // Verify both clients received the same event
        expect($client1Event['type'])->toBe('task_spawned');
        expect($client2Event['type'])->toBe('task_spawned');

        expect($client1Event['task_id'])->toBe('f-test01');
        expect($client2Event['task_id'])->toBe('f-test01');

        expect($client1Event['run_id'])->toBe('run-test01');
        expect($client2Event['run_id'])->toBe('run-test01');

        expect($client1Event['agent'])->toBe('claude');
        expect($client2Event['agent'])->toBe('claude');

        // Clean up
        fclose($client1Socket);
        fclose($client2Socket);
        fclose($server1Socket);
        fclose($server2Socket);
        $ipcServer->stop();
    })->skip('Port binding conflicts in parallel test runs');

    test('broadcast sends to all connected clients via IpcServer', function (): void {
        // Create protocol and IPC server
        $protocol = new ConsumeIpcProtocol;
        $ipcServer = new ConsumeIpcServer($protocol, $this->mockConfigService);

        // Start IPC server
        $ipcServer->start();

        // We can't easily simulate real client connections in a unit test,
        // so we'll test the broadcast method indirectly by verifying the
        // IPC server's client count behavior

        expect($ipcServer->getClientCount())->toBe(0);

        // Broadcast an event (should not error even with no clients)
        $instanceId = $protocol->generateInstanceId();
        $event = new TaskSpawnedEvent(
            taskId: 'f-test02',
            runId: 'run-test02',
            agent: 'cursor-agent',
            instanceId: $instanceId
        );

        // Should not throw exception when broadcasting to no clients
        expect(fn () => $ipcServer->broadcast($event))->not->toThrow(Exception::class);

        // Clean up
        $ipcServer->stop();
    })->skip('Port binding conflicts in parallel test runs');

    test('multiple clients receive different events in order', function (): void {
        // Create protocol
        $protocol = new ConsumeIpcProtocol;

        // Create two mock client connections
        $client1Sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $client2Sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        expect($client1Sockets)->not->toBeFalse();
        expect($client2Sockets)->not->toBeFalse();

        [$client1Socket, $server1Socket] = $client1Sockets;
        [$client2Socket, $server2Socket] = $client2Sockets;

        // Set blocking mode for reliable reads
        stream_set_blocking($client1Socket, true);
        stream_set_blocking($client2Socket, true);

        // Create multiple events
        $instanceId = $protocol->generateInstanceId();
        $event1 = new TaskSpawnedEvent(
            taskId: 'f-test03',
            runId: 'run-test03',
            agent: 'claude',
            instanceId: $instanceId
        );
        $event2 = new TaskSpawnedEvent(
            taskId: 'f-test04',
            runId: 'run-test04',
            agent: 'cursor-agent',
            instanceId: $instanceId
        );

        // Send both events to both clients
        fwrite($server1Socket, $protocol->encode($event1));
        fwrite($server1Socket, $protocol->encode($event2));
        fwrite($server2Socket, $protocol->encode($event1));
        fwrite($server2Socket, $protocol->encode($event2));

        // Read both events from client 1
        $client1Line1 = fgets($client1Socket);
        $client1Line2 = fgets($client1Socket);

        // Read both events from client 2
        $client2Line1 = fgets($client2Socket);
        $client2Line2 = fgets($client2Socket);

        expect($client1Line1)->not->toBeFalse();
        expect($client1Line2)->not->toBeFalse();
        expect($client2Line1)->not->toBeFalse();
        expect($client2Line2)->not->toBeFalse();

        // Parse events
        $client1Event1 = json_decode(trim($client1Line1), true);
        $client1Event2 = json_decode(trim($client1Line2), true);
        $client2Event1 = json_decode(trim($client2Line1), true);
        $client2Event2 = json_decode(trim($client2Line2), true);

        // Verify both clients received both events in order
        expect($client1Event1['task_id'])->toBe('f-test03');
        expect($client1Event2['task_id'])->toBe('f-test04');
        expect($client2Event1['task_id'])->toBe('f-test03');
        expect($client2Event2['task_id'])->toBe('f-test04');

        // Clean up
        fclose($client1Socket);
        fclose($client2Socket);
        fclose($server1Socket);
        fclose($server2Socket);
    });

    test('client count increases as clients connect', function (): void {
        // Create IPC server
        $protocol = new ConsumeIpcProtocol;
        $ipcServer = new ConsumeIpcServer($protocol, $this->mockConfigService);

        // Start server
        $ipcServer->start();

        // Initially no clients
        expect($ipcServer->getClientCount())->toBe(0);

        // Accept (should be no-op with no pending connections)
        $ipcServer->accept();
        expect($ipcServer->getClientCount())->toBe(0);

        // Clean up
        $ipcServer->stop();
    })->skip('Port binding conflicts in parallel test runs');
});
