# Remote Relay Architecture

> Plan for enabling remote access to Fuel daemons via a WebSocket relay service.

## Problem Statement

Currently, Fuel's IPC is TCP-based on `0.0.0.0:{port}`, allowing local and LAN connections. To enable true remote access (mobile apps, web dashboards, different networks), we need a relay service that:

1. Works through NAT/firewalls (daemon connects out, not in)
2. Supports multiple simultaneous clients
3. Requires no port forwarding or static IPs
4. Maintains security - only authorized users access their own daemons

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Relay Server                                 │
│                    (Laravel + Inertia/React)                         │
│                                                                      │
│   ┌──────────────┐   ┌──────────────┐   ┌───────────────────────┐   │
│   │ GitHub OAuth │   │ Channel Auth │   │ Stats Store (future)  │   │
│   │              │   │              │   │                       │   │
│   │ - Login      │   │ - API keys   │   │ - Aggregate metrics   │   │
│   │ - Sessions   │   │ - Project    │   │ - Agent usage         │   │
│   │              │   │   ownership  │   │ - Success rates       │   │
│   └──────────────┘   └──────────────┘   └───────────────────────┘   │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
                                │
                         Channel auth only
                         (doesn't see traffic)
                                │
┌───────────────────────────────▼──────────────────────────────────────┐
│                            Reverb                                     │
│                   (WebSocket relay / pub-sub)                         │
│                                                                       │
│     Channel: private-fuel.{projectId}                                 │
│                                                                       │
│   ┌─────────┐                                      ┌─────────────┐   │
│   │ Daemon  │◄────────── encrypted ───────────────►│ Clients     │   │
│   │         │            messages                  │ (web/mobile)│   │
│   └─────────┘                                      └─────────────┘   │
│                                                                       │
└───────────────────────────────────────────────────────────────────────┘
```

## Key Design Decisions

### 1. Local-First Architecture

The relay is **optional and additive**. Local TCP IPC remains the primary transport.

```
┌─────────────────────────────────────────────────────────┐
│                      Fuel Daemon                         │
│                                                          │
│   ┌─────────────────────┐    ┌────────────────────────┐ │
│   │   TCP IPC Server    │    │  Reverb WS Client      │ │
│   │   (always works)    │    │  (optional, remote)    │ │
│   │                     │    │                        │ │
│   │ - Local attach      │    │ - Remote clients       │ │
│   │ - No internet req   │    │ - Requires fuel link   │ │
│   │ - Zero latency      │    │ - Graceful disconnect  │ │
│   └──────────┬──────────┘    └───────────┬────────────┘ │
│              │                           │              │
│              └───────────┬───────────────┘              │
│                          ▼                              │
│                 Same event stream                       │
│                 Same command handling                   │
│                 Same IPC protocol                       │
└─────────────────────────────────────────────────────────┘
```

**Rationale**:
- No internet? Local works.
- Reverb down? Local works.
- Relay server explodes? Local works.
- User never links? Everything works as before.

### 2. Dumb Pipe Relay

The relay server **cannot see IPC traffic**. It only:
- Authenticates users (GitHub OAuth)
- Authorizes channel access (API key ownership)
- Stores project metadata (name, folder)

Reverb handles encrypted pub/sub. The Laravel app never touches message content.

### 3. Account-Based Linking (not pairing codes)

**Why accounts over one-time pairing codes:**
- Long-lived: link once per project, works across all devices
- Multi-device: phone, tablet, web - all see linked projects
- Revocable: disable API key to unlink
- Future monetization path: paid features, usage tiers

### 4. One API Key Per Project

Each `fuel link` generates a unique API key for that project. Benefits:
- Revoke one project without affecting others
- Track usage per project
- Clear ownership model

## Linking Flow

```bash
$ fuel link

Opening browser for authentication...
# Browser opens: https://relay.example.com/link?nonce=abc123&folder=my-project

# User logs in with GitHub (if needed)
# Approves linking
# Server generates API key, associates with user + project

Linked successfully!
Project: my-project
API Key: fuel_xxxxxxxxxxxxxxxxxxxxx (saved to .fuel/config.yaml)

Your daemon will connect to the relay on next `fuel consume`.
```

**Config after linking:**
```yaml
# .fuel/config.yaml
port: 9981
relay:
  enabled: true
  server: wss://relay.example.com
  api_key: fuel_xxxxxxxxxxxxxxxxxxxxx
  project_name: my-project
```

## Multi-Client Support

All clients on the same channel receive the same events:

```
Event occurs in daemon
    │
    ├──► TCP write to local client        (1 syscall)
    │
    └──► WS write to Reverb               (1 syscall)
              │
              ├──► Web dashboard
              ├──► Mobile app
              └──► Desktop app (future)
```

**Performance:**
- Daemon does 2 writes max (1 local, 1 Reverb)
- Reverb handles fan-out to N remote clients
- Events are small (2-5KB snapshots, bytes for status)
- Even at 10 events/sec, negligible overhead

**Sync model:**
- New client joins → daemon detects via presence → sends snapshot
- All clients receive same event stream
- Commands from any client processed in order received
- Daemon broadcasts result → all clients update

```
Mobile: "pause"  ───►  Daemon processes  ───►  Broadcasts "paused"
                                                    │
                                         ┌──────────┼──────────┐
                                         ▼          ▼          ▼
                                       Mobile     Web      Desktop
                                       (sender)   (sees)   (sees)
```

## Protocol Mapping

The existing IPC protocol maps directly:

| Local TCP IPC | Reverb Channel |
|---------------|----------------|
| Client connects | Client joins channel |
| Client sends `HelloCommand` | Client sends hello on channel |
| Daemon sends `SnapshotEvent` | Daemon broadcasts snapshot |
| Daemon broadcasts events | Daemon publishes to channel |
| Client sends command | Client publishes command |
| Multiple clients supported | Multiple subscribers supported |

Same JSON messages, different transport.

## Security Considerations

### The Risk

If auth is compromised:
- Attacker connects to victim's channel
- Sends commands to victim's daemon
- Daemon executes with full permissions
- **This is RCE on someone's dev machine**

### Mitigations

1. **API keys are per-project, long, random**
   ```
   fuel_k8x9m2p4q7r1s5t8u3v6w9y2z4a7b0c
   ```

2. **Server-side ownership verification**
   ```php
   Broadcast::channel('fuel.{projectId}', function ($user, $projectId) {
       return $user->projects()->where('id', $projectId)->exists();
   });
   ```

3. **Rate limiting on auth attempts**
   - Max 10 failed auths per minute per IP
   - Exponential backoff on failures

4. **Audit logging**
   - Log every connection with timestamp, IP, user
   - Alert on suspicious patterns

5. **Key rotation**
   - `fuel link --rotate` generates new key
   - Old key immediately invalidated

6. **Optional: Challenge-response on connect**
   - Daemon and client both prove key possession
   - Prevents replay attacks

## Future: Stats Collection

Daemon periodically POSTs aggregate stats (opt-in):

```json
{
  "project_id": "xxx",
  "period": "hourly",
  "tasks_completed": 12,
  "agent_breakdown": {"sonnet": 8, "opus": 4},
  "avg_duration_seconds": 45,
  "failure_count": 2
}
```

Dashboard shows:
- Activity graphs over time
- Agent usage breakdown
- Success/failure rates
- Average run durations
- Epics completed

**Privacy**: No task content, no code, no file paths. Just numbers.

## Alternative: Tailscale Integration

For users who don't want/need the relay, a simpler option:

```yaml
# .fuel/config.yaml
tailscale:
  enabled: true
  # Runs: tailscale serve --bg 9981
```

Benefits:
- Zero additional infrastructure
- Tailscale handles auth, encryption, NAT traversal
- Daemon stays a simple TCP server
- Access via `https://machine-name.tailnet-name.ts.net:9981`

Tradeoffs:
- Requires Tailscale on all devices
- No web dashboard without additional work
- No centralized stats

## Implementation Phases

### Phase 0: Tailscale Option
- Add `tailscale.enabled` config option
- Daemon runs `tailscale serve` on startup
- Document setup

### Phase 1: Relay Server MVP
- Laravel app with GitHub OAuth
- Project registration + API key generation
- Reverb setup with private channels
- Basic web dashboard (list projects, connection status)

### Phase 2: Daemon Integration
- Add WebSocket client to daemon (Ratchet/Pawl or minimal custom)
- Bridge local IPC events to Reverb channel
- Handle remote commands same as local
- Presence channel for client join/leave detection

### Phase 3: Clients
- Web dashboard with real-time updates
- Mobile app (React Native?)
- Desktop app (Electron/Tauri?)

### Phase 4: Stats + Polish
- Aggregate stats collection
- Usage dashboards
- Key rotation UI
- Audit logs

## Open Questions

1. **WebSocket client library**: Ratchet/Pawl vs Amp vs minimal custom implementation?

2. **Presence vs polling**: Use Reverb presence channels, or have clients send periodic heartbeats?

3. **Command authorization**: Should remote clients have restricted permissions? (view-only mode?)

4. **Offline queue**: If daemon disconnects, should relay queue commands? Or just drop them?

5. **Multi-project view**: Web dashboard shows all projects, or one at a time?

## Appendix: Current IPC Architecture

For reference, the existing local IPC:

- **Server**: `ConsumeIpcServer` - TCP on `0.0.0.0:{port}`
- **Client**: `ConsumeIpcClient` - connects to `127.0.0.1:{port}`
- **Protocol**: `ConsumeIpcProtocol` - length-prefixed JSON messages
- **Events**: `SnapshotEvent`, `TaskCompletedEvent`, `StatusLineEvent`, etc.
- **Commands**: `StopCommand`, `TaskStartCommand`, `AttachCommand`, etc.

The relay integration adds a parallel transport, not a replacement.
