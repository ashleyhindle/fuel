# Consume Runner and Client Spec

Status: draft
Owner: (tbd)
Last updated: (tbd)

## Summary

We want Fuel to keep running tasks in the background even after the user exits the
consume UI. The current consume loop owns the agent processes. When consume exits,
ProcessManager shuts down and kills child processes. This spec splits consume into
(1) a long-lived runner process that owns the agent pipes and (2) a client UI that
attaches to the runner over a local IPC socket. The client can start, stop, pause,
resume, and monitor the runner. The runner can run headless. The client can exit
without affecting in-progress tasks.

The runner preserves the current pipe-based stdout capture. We keep output files
as a fallback, but primary live output comes from the runner streaming process
output over IPC, not by polling files.

## Goals

- Tasks continue running after the consume UI exits.
- The live output pipe behavior is preserved (no polling required for live view).
- Users can stop and start consume safely, without losing in-progress tasks.
- The runner is built-in and works without systemd/launchd/tmux.
- Multiple clients can attach to the same runner (optional but supported).
- State is correct after restarts (tasks, runs, pids, health, reviews).

## Non-goals

- Cross-machine or remote consume control.
- Full web UI (but the IPC protocol will allow a future bridge).
- Reattaching to already-running agent processes after a crash (not possible with
  Symfony Process); instead we detect and reconcile.

## Constraints and current behavior

- Consume currently starts and owns all agent processes and their pipes.
- ProcessManager registers SIGINT/SIGTERM and kills all children on shutdown.
- Runs are marked failed by cleanupOrphanedRuns on every consume start, without
  PID checks.
- Tasks store consume_pid today (TaskService update).

## Architecture

### High level

- Runner: background process that owns agent pipes and does the actual consume
  loop (spawn, poll, review, health).
- Client: TUI or any UI that attaches to the runner and renders state.
- IPC: local socket server owned by runner; clients connect and exchange JSON
  line messages.

### Process separation

- The runner and the client must be different processes.
- Exiting the client must NOT call ProcessManager::shutdown().
- The runner is responsible for shutdown and cleanup.

## CLI behavior

### Default

- `fuel consume`:
  - If runner is not alive, start it in the background, then attach.
  - If runner is alive, attach immediately.

### Flags

- `fuel consume --status`:
  - Query runner state and print summary, then exit.
- `fuel consume --pause`:
  - Pause runner (stop accepting new ready tasks). Keeps polling completions.
- `fuel consume --resume`:
  - Resume runner.
- `fuel consume --stop`:
  - Graceful stop: stop accepting new tasks, optionally wait for in-flight tasks
    to finish, then exit.
- `fuel consume --force`:
  - Force stop: kill in-flight tasks immediately.

Notes:
- Existing `--once` and `--health` stay local and do not require the runner.
- `--interval`, `--agent`, `--dryrun`, `--review` are runner config.

## IPC server

### Socket location

- Unix domain socket path: `.fuel/consume.sock` (0600).
- If UDS not available, fallback to TCP 127.0.0.1 with a random free port
  stored in `.fuel/consume.port` (0600). Use only as a fallback.

### Protocol

- Transport: JSON Lines (one JSON object per line, newline-delimited).
- Do not use magic strings for event or command types. Use enums and typed
  message classes to enforce clarity and type safety.
- All messages include:
  - `type`: string
  - `timestamp`: ISO 8601
  - `instance_id`: runner instance id
  - optional `request_id` for request-response pairs

#### Client -> Runner commands

- `attach`:
  - `{type:"attach", request_id:"...", last_event_id:null|"..."}`
  - Runner responds with a `snapshot` then incremental events.
- `detach`:
  - `{type:"detach"}`
- `pause`:
  - `{type:"pause"}`
- `resume`:
  - `{type:"resume"}`
- `stop`:
  - `{type:"stop", mode:"graceful"}`
- `force_stop`:
  - `{type:"stop", mode:"force"}`
- `reload_config`:
  - `{type:"reload_config"}`
- `set_interval`:
  - `{type:"set_interval", interval_seconds:5}`
- `request_snapshot`:
  - `{type:"request_snapshot"}`

#### Runner -> Client events

- `hello`:
  - `{type:"hello", instance_id:"...", version:"..."}`
- `snapshot`:
  - Full board state + active processes + health summary.
- `status_line`:
  - `{type:"status_line", level:"info|warn|error", text:"..."}`
- `task_spawned`:
  - `{type:"task_spawned", task_id:"f-...", run_id:"run-...", agent:"..."}`
- `task_completed`:
  - `{type:"task_completed", task_id:"f-...", run_id:"run-...", exit_code:0,
     completion_type:"success|failed|network_error|permission_blocked"}`
- `health_change`:
  - `{type:"health_change", agent:"...", status:"healthy|backoff|dead"}`
- `output_chunk`:
  - `{type:"output_chunk", task_id:"f-...", run_id:"run-...",
     stream:"stdout|stderr", chunk:"..."}`
- `error`:
  - `{type:"error", message:"..."}`

Notes:
- The client is responsible for rendering. The runner does not send ANSI.
- `output_chunk` is best-effort; if clients are slow, chunks can be dropped.

### Multi-client behavior

- The runner accepts multiple client connections.
- Broadcast events to all clients.
- If a client is slow, disconnect it after a small backlog (avoid blocking the
  runner loop). Output is still available in output files.

## Type safety for IPC messages

Define enums and DTOs so all IPC messages are explicit and serializable.

- `ConsumeCommandType` enum (client -> runner).
- `ConsumeEventType` enum (runner -> client).
- A base interface like `IpcMessage` with `type()`, `timestamp()`,
  `instanceId()`, and `toArray()` or `jsonSerialize()`.
- A concrete DTO per message type (e.g., `IpcAttachCommand`,
  `IpcSnapshotEvent`, `IpcOutputChunkEvent`).
- `ConsumeIpcProtocol` is responsible for validating input and instantiating
  the correct DTO or returning a protocol error.

This ensures:
- No magic strings in code.
- Message schemas are discoverable and documented by class definitions.
- Tests can assert serialization shape per message class.

## Storage and data volume

- Do not add a new database table for IPC events or output. IPC is ephemeral.
- Agent output stays in `.fuel/processes/<run_id>/stdout.log` and stderr.log.
- The runs table already stores a truncated output (10KB) and completion data.
- Keep a small in-memory ring buffer per active process for attach-tail UX only.
- If long-term analytics are required later, add an opt-in log sink rather than
  storing high-volume event streams in SQLite.

## Runner lifecycle and PID handling

### Runner PID

- Write `.fuel/consume.pid` with JSON:
  - `{pid, started_at, instance_id, socket_path, port}`
- `fuel consume` checks PID liveness via `posix_kill($pid, 0)`.
- If PID is dead, delete pidfile and socket before starting a new runner.

### Task PID and run PID

- Store agent process PID in both:
  - tasks.consume_pid (existing)
  - runs.pid (new field)
- Store runner instance id on each run:
  - runs.runner_instance_id (new field)

### Orphan cleanup

- Replace `cleanupOrphanedRuns` behavior with PID-aware checks:
  - Only mark a run failed if its stored PID is dead.
  - If PID is alive but runner is dead, leave run as running and emit a warning.
  - If PID is alive but cannot be managed, optionally reopen task with a note.

## Output capture

- Keep output files in `.fuel/processes/<run_id>/stdout.log` and stderr.log
  as a historical record.
- Live output is streamed over the IPC socket from the Symfony Process callback
  (no polling).
- On client attach, optionally send the last N bytes of output as a tail for
  each active process (stored in memory ring buffer in runner).

## Code structure

### New files

- `app/Services/ConsumeRunner.php`
  - Contains the consume loop logic (migrated from ConsumeCommand).
  - Exposes start/stop/pause/resume methods.
- `app/Services/ConsumeIpcServer.php`
  - Owns socket server, accepts connections, broadcasts events.
- `app/Services/ConsumeIpcProtocol.php`
  - JSON encode/decode helpers, validation, message ids.
- `app/Enums/ConsumeCommandType.php`
- `app/Enums/ConsumeEventType.php`
- `app/Ipc/`:
  - One DTO per command/event (implements JsonSerializable).
- `app/DTO/ConsumeSnapshot.php`
  - Snapshot state for initial attach.

### Modified files

- `app/Commands/ConsumeCommand.php`
  - Becomes client: attach to runner, render TUI, send commands.
  - Adds flags for start/stop/pause/resume/status.
- `app/Services/ProcessManager.php`
  - When called by client, must not register shutdown handlers.
  - Provide a hook to stream `output_chunk` to IPC.
- `app/Services/RunService.php`
  - Add pid + runner_instance_id and update orphan cleanup.
- `database/migrations/*_add_runner_fields_to_runs.php`
  - Add `pid` (int, nullable), `runner_instance_id` (string, nullable).

## Tests

Use Pest. All tests must run in an isolated temp directory and must not touch
real workspace paths. Avoid long-running sleeps.

### Unit tests

- `tests/Unit/ConsumeIpcProtocolTest.php`
  - Encodes/decodes JSON lines.
  - Rejects invalid payloads.
- `tests/Unit/ConsumeIpcMessageTest.php`
  - Each DTO serializes to expected JSON shape.
  - Enums map to expected `type` values.
- `tests/Unit/ConsumeRunnerStateTest.php`
  - Pause/resume toggles.
  - Snapshot contains required keys.

### Feature tests

- `tests/Feature/ConsumeRunnerAttachTest.php`
  - Start runner in a temp .fuel dir and attach a client.
  - Assert `hello` and `snapshot` are received.
- `tests/Feature/ConsumeRunnerMultiClientTest.php`
  - Two clients attach and both receive a broadcast event.
- `tests/Feature/ConsumeRunnerPidfileTest.php`
  - Stale pidfile is cleaned and new runner starts.
- `tests/Feature/RunServicePidCleanupTest.php`
  - Orphan cleanup uses PID checks and does not mark live runs as failed.

Testing notes:
- Use `stream_socket_pair` for IPC tests where possible.
- If using real UDS sockets, create paths inside the temp test directory.
- Mock ProcessManager with a fake that emits controlled events.

## Documentation updates

- `README.md`:
  - Explain new consume behavior (background runner).
  - Add commands: pause/resume/stop/force/status.
- `agent-resources/`:
  - If needed, add a short doc on consume runner architecture.
- `CLAUDE.md` (AGENTS instructions):
  - Mention the runner/client architecture and IPC sockets.

## Fuel task breakdown (for parallel work)

Epic:
- `fuel epic:add "Consume runner background mode" --description="Split consume into a runner + client with IPC, persistent pid handling, and safe restart behavior."`

Tasks (suggested):

1) Runner service extraction
- `fuel add "Extract consume loop into ConsumeRunner" --epic=e-xxxx --complexity=complex --priority=1`
- Description: Move the main loop from `app/Commands/ConsumeCommand.php` into
  `app/Services/ConsumeRunner.php`. Expose start/stop/pause/resume and event hooks.

2) IPC server and protocol
- `fuel add "Add ConsumeIpcServer + protocol" --epic=e-xxxx --complexity=complex --priority=1 --blocked-by=f-extract-runner`
- Description: Implement UDS server, JSON lines protocol, multi-client broadcast.

3) ConsumeCommand client behavior
- `fuel add "Make consume a client that attaches to runner" --epic=e-xxxx --complexity=moderate --priority=1 --blocked-by=f-ipc-server`
- Description: Add attach/start/stop/pause/resume/status flags and render UI from
  IPC events.

4) PID and run state changes
- `fuel add "Store runner and process PIDs" --epic=e-xxxx --complexity=moderate --priority=1 --blocked-by=f-extract-runner`
- Description: Add fields to runs table, update RunService cleanup logic.

5) Tests
- `fuel add "Add consume runner IPC tests" --epic=e-xxxx --complexity=complex --priority=1 --blocked-by=f-ipc-server,f-consume-client,f-pid-changes`

6) Docs
- `fuel add "Document consume runner architecture" --epic=e-xxxx --complexity=simple --priority=2 --blocked-by=f-consume-client`

Review task:
- `fuel add "Review: Consume runner background mode" --epic=e-xxxx --complexity=complex --blocked-by=f-extract-runner,f-ipc-server,f-consume-client,f-pid-changes,f-tests,f-docs --description="Acceptance: 1) consume UI can exit while tasks continue, 2) pause/resume works, 3) runner start/stop works, 4) no live output regression, 5) tests pass: vendor/bin/pest --compact --filter=ConsumeRunner"`
