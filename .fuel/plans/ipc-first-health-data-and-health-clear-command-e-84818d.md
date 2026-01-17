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

### f-bdfd92: Create HealthResetCommand IPC command
- **Files created:**
  - `app/Ipc/Commands/HealthResetCommand.php` - IPC command implementation
- **Files modified:**
  - `app/Services/ConsumeIpcProtocol.php:27` - Added import for HealthResetCommand
  - `app/Services/ConsumeIpcProtocol.php:145` - Added match case for HealthReset command type
  - `tests/Unit/ConsumeIpcMessageTest.php` - Added comprehensive tests for HealthResetCommand
- **Pattern followed:** SetTaskReviewCommand (has single property + standard IpcMessage implementation)
- **Properties:**
  - `public string $agent` - Can be specific agent name (e.g., 'claude', 'cursor') or 'all'
  - Implements IpcMessage via HasIpcMetadata trait
- **Tests added:**
  - toArray serialization test
  - fromArray deserialization test
  - ISO 8601 timestamp format test
  - Type field matches enum value test
- **All tests pass:** ConsumeIpcMessageTest (53 passed), ConsumeIpcProtocolTest (28 passed)

### f-d4c566: Enhance HealthChangeEvent with full health data
- **Files modified:**
  - `app/Ipc/Events/HealthChangeEvent.php` - Added properties: consecutiveFailures (int), inBackoff (bool), isDead (bool), backoffSeconds (int)
  - `app/Services/ConsumeIpcProtocol.php:279-292` - Updated decodeHealthChangeEvent() to parse new fields with defaults
  - `tests/Unit/ConsumeIpcMessageTest.php` - Updated test to verify new fields, added bool type handling to generic test helpers
  - `tests/Unit/ConsumeIpcProtocolTest.php` - Updated round-trip test with new properties
- **Key decisions:**
  - All new fields have sensible defaults in decoder (0 for ints, false for bools) for backward compatibility
  - Added getter methods for all new properties following existing pattern
  - Boolean fields use camelCase (inBackoff, isDead) for consistency with PHP conventions
- **Gotchas for next tasks:**
  - EventBroadcaster::broadcastHealthChange() still uses old signature (agent, status) - needs updating to pass full health data
  - SnapshotManager has access to full AgentHealth object when calling broadcaster
  - Use AgentHealth::getBackoffSeconds() and check backoffUntil/isDead for the boolean fields
  - ConsumeIpcClient::handleHealthChangeEvent() needs updating to store all new fields in healthSummary
- **All tests pass:** ConsumeIpcMessageTest (81 passed), ConsumeIpcProtocolTest (28 passed)

### f-d35292: Add HealthReset handler to IpcCommandDispatcher
- **File modified:** `app/Daemon/IpcCommandDispatcher.php`
- **Changes made:**
  1. Removed `readonly` from class declaration to allow mutable property
  2. Added `private $onHealthReset = null;` property to store callback
  3. Added `setOnHealthReset(callable $callback): void` method to set the callback
  4. Added import for `HealthResetCommand`
  5. Added `'health_reset' => $this->handleHealthResetCommand($message)` case in match statement
  6. Added `handleHealthResetCommand(IpcMessage $message): void` private method
- **Pattern followed:** Similar to `SetTaskReviewCommand` - instance check, extract property, invoke callback
- **Callback signature:** `callable(string $agent): void` - receives agent name from command
- **Implementation details:**
  - Constructor parameters now have explicit `readonly` keywords (class is no longer readonly)
  - Handler checks both instanceof and null-safety before invoking callback
  - Placed health_reset case in logical order with other control commands
- **Next task should:**
  - Wire callback in `ConsumeRunner` via `$dispatcher->setOnHealthReset(fn($agent) => ...)`
  - Callback implementation should call `$healthTracker->clearHealth($agent)` and broadcast event
- **All tests pass:** ConsumeIpcMessageTest (53 passed), ConsumeIpcProtocolTest (28 passed)

### f-c9fa81: Update ConsumeIpcClient to handle full health data
- **File modified:** `app/Services/ConsumeIpcClient.php`
- **Changes made:**
  1. Updated `handleHealthChangeEvent()` to store full health data structure:
     - `consecutive_failures` => $event->consecutiveFailures()
     - `in_backoff` => $event->inBackoff()
     - `is_dead` => $event->isDead()
     - `backoff_seconds` => $event->backoffSeconds()
  2. Added `sendHealthReset(string $agent): void` method that sends HealthResetCommand via IPC
  3. Added import for `HealthResetCommand`
- **Pattern followed:**
  - Health data structure matches plan specification (see "Health Summary Data Structure" section)
  - sendHealthReset() follows same pattern as other send methods (sendPause, sendResume, etc.)
- **Testing:** All IPC tests pass (99 passed, 1 skipped)
- **Next task should:**
  - ConsumeCommand can now call `$this->ipcClient->sendHealthReset($agent)` when executing /health-clear
  - ConsumeCommand can check `$this->ipcClient->getHealthSummary()` for full health data including consecutive_failures, is_dead, etc. for autocomplete suggestions
- **Key decision:** Placed sendHealthReset() method near sendReloadConfig() since both are control/management commands

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
