# Epic: IPC-first health data and /health-clear command (e-84818d)

## Plan

Fix architectural gap where ConsumeCommand reads health directly from DB instead of IPC. Add /health-clear command palette entry with autocomplete for unhealthy agents. Ensures remote TUI clients can see and manage agent health.

### Problem

Currently `ConsumeCommand::getHealthStatusLines()` uses `$this->healthTracker` which reads directly from the SQLite database. This breaks IPC-first architecture - a remote TUI client connecting to a runner on a different machine won't have DB access.

The good news: health data is ALREADY in the IPC snapshot (`ConsumeSnapshot::$healthSummary`) and the client has `ConsumeIpcClient::getHealthSummary()`. We just need to use it.

### Current State Analysis

**What exists:**
- `SnapshotBuilder` calls `$healthTracker->getAllHealthStatus()` and includes in snapshot
- `ConsumeSnapshot::$healthSummary` contains per-agent health data
- `ConsumeIpcClient::getHealthSummary()` returns cached health data
- `HealthChangeEvent` updates client-side health (but incompletely)

**What's broken:**
- `ConsumeCommand::getHealthStatusLines()` uses local `$this->healthTracker` not IPC data
- `HealthChangeEvent` only stores `status`, missing `consecutive_failures`, `is_dead`, `backoff_seconds`
- No IPC command to clear health
- No `/health-clear` command palette entry

### Solution

#### 1. Fix HealthChangeEvent to include full data

`app/Ipc/Events/HealthChangeEvent.php`:
- Add properties: `consecutiveFailures`, `inBackoff`, `isDead`, `backoffSeconds`
- Update constructor and serialization
- Update `ConsumeIpcClient::handleHealthChangeEvent()` to store full data

#### 2. Update ConsumeCommand to use IPC health data

`app/Commands/ConsumeCommand.php`:
- Modify `getHealthStatusLines()` to check `$this->ipcClient?->isConnected()`
- If connected: use `$this->ipcClient->getHealthSummary()`
- If not connected (--once mode): fall back to local `$this->healthTracker`
- Add helper method `getUnhealthyAgentsFromIpc(): array` for autocomplete

#### 3. Add HealthReset IPC command

**New files:**
- `app/Enums/ConsumeCommandType.php` - add `HealthReset = 'health_reset'`
- `app/Ipc/Commands/HealthResetCommand.php` - with `agent` property (or 'all')

**Modified files:**
- `app/Services/ConsumeIpcClient.php` - add `sendHealthReset(string $agent): void`
- `app/Daemon/IpcCommandDispatcher.php` - add `$onHealthReset` callback, handle command
- `app/Services/ConsumeRunner.php` - wire callback to call `$healthTracker->clearHealth()`

#### 4. Add /health-clear command palette entry

`app/Commands/ConsumeCommand.php`:
- Add to `PALETTE_COMMANDS`: `'health-clear' => 'Reset health status for an agent'`
- Add `updateHealthClearSuggestions()` method for autocomplete
- Show unhealthy agents from `$this->ipcClient->getHealthSummary()`
- Handle execution: call `$this->ipcClient->sendHealthReset($agent)`

#### 5. Broadcast health change after reset

When health is cleared via IPC:
- Runner calls `$healthTracker->clearHealth($agent)`
- Runner broadcasts `HealthChangeEvent` with cleared status
- All connected clients update their health summary

### File Summary

| File | Action |
|------|--------|
| `app/Ipc/Events/HealthChangeEvent.php` | Modify - add full health data |
| `app/Ipc/Commands/HealthResetCommand.php` | New |
| `app/Enums/ConsumeCommandType.php` | Modify - add HealthReset |
| `app/Services/ConsumeIpcClient.php` | Modify - add sendHealthReset, fix handleHealthChangeEvent |
| `app/Daemon/IpcCommandDispatcher.php` | Modify - add onHealthReset handler |
| `app/Services/ConsumeRunner.php` | Modify - wire health reset callback |
| `app/Commands/ConsumeCommand.php` | Modify - use IPC health, add /health-clear |
| `tests/Unit/Ipc/Events/HealthChangeEventTest.php` | New or modify |
| `tests/Unit/Ipc/Commands/HealthResetCommandTest.php` | New |
| `tests/Feature/Commands/ConsumeCommandHealthTest.php` | New |

### Data Flow

```
/health-clear claude
       │
       ▼
ConsumeCommand
       │ sendHealthReset('claude')
       ▼
ConsumeIpcClient ──IPC──► ConsumeRunner
                                │ $healthTracker->clearHealth('claude')
                                │ broadcast(HealthChangeEvent)
                                ▼
                         All TUI clients
                                │ handleHealthChangeEvent()
                                │ update healthSummary
                                ▼
                         UI refreshes with cleared health
```

### Health Summary Data Structure

```php
// In ConsumeIpcClient::$healthSummary
[
    'claude' => [
        'status' => 'healthy',           // 'healthy'|'degraded'|'unhealthy'
        'consecutive_failures' => 0,
        'in_backoff' => false,
        'is_dead' => false,
        'backoff_seconds' => 0,
    ],
    'cursor' => [
        'status' => 'unhealthy',
        'consecutive_failures' => 5,
        'in_backoff' => true,
        'is_dead' => true,
        'backoff_seconds' => 480,
    ],
]
```

### Autocomplete Behavior

`/health-clear ` shows:
- Only agents with `consecutive_failures > 0` OR `is_dead = true` OR `in_backoff = true`
- Format: `agent_name (status, N failures)`
- Plus "all" option to clear all agents

### Edge Cases

1. **No unhealthy agents** - Show "No agents need clearing" message
2. **Remote client** - Works via IPC, no DB access needed
3. **--once mode** - Falls back to local healthTracker (acceptable, standalone mode)
4. **Agent doesn't exist** - clearHealth is idempotent, no error needed

### Testing Strategy

1. **Unit tests:**
   - HealthChangeEvent serialization with full data
   - HealthResetCommand serialization
   - ConsumeIpcClient health methods

2. **Feature tests:**
   - /health-clear autocomplete shows unhealthy agents
   - Health reset command flows through IPC
   - Health display uses IPC data when connected

3. **Integration:**
   - Remote client can see and clear health
   - Health change broadcasts to all clients

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### f-bb47ff: Add HealthReset to ConsumeCommandType enum
- **File modified:** `app/Enums/ConsumeCommandType.php:17`
- **Change:** Added `case HealthReset = 'health_reset';` to ConsumeCommandType enum
- **Location:** Placed in the general control commands section, after SetTaskReviewEnabled
- **Tests:** Existing enum tests pass (ConsumeIpcMessageTest)

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
