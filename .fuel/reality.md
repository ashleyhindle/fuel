# Reality

## Architecture
**Fuel** is an AI agent task orchestrator built on **Laravel Zero** (PHP CLI microframework). Manages task queues via SQLite (`.fuel/agent.db`) and spawns AI agents (Claude, Cursor, OpenCode, Amp, Codex) to execute tasks. Daemon process (`fuel consume`) provides real-time Kanban board and orchestrates multi-agent workflows.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| TaskService | Task CRUD & lifecycle | `app/Services/TaskService.php` |
| EpicService | Epic grouping & status | `app/Services/EpicService.php` |
| RunService | Agent execution tracking | `app/Services/RunService.php` |
| ConfigService | YAML config loading | `app/Services/ConfigService.php` |
| ProcessManager | Agent process spawning | `app/Services/ProcessManager.php` |
| ReviewService | Post-task code review | `app/Services/ReviewService.php` |
| ConsumeRunner | Main daemon orchestrator | `app/Services/ConsumeRunner.php` |
| BrowserDaemonManager | Headless browser testing | `app/Services/BrowserDaemonManager.php` |
| AgentDriverRegistry | Agent driver routing | `app/Agents/AgentDriverRegistry.php` |
| CloseCommand | Mark task done with reason `closed` | `app/Commands/CloseCommand.php` |

## Entry Points
- `fuel` binary at root - CLI entry point
- 54 commands in `app/Commands/`: task mgmt (`add`, `start`, `done`, `ready`), epics (`epic:add`, `epic:show`), daemon (`consume`), deps (`dep:add`), browser testing (`browser-*`)

## Patterns
- **Strict types** - All files declare `strict_types=1` (enforced by Rector)
- **DI via container** - Services registered in `AppServiceProvider`, resolved via `app()`
- **Backed enums** - TaskStatus, EpicStatus, Agent, FailureType for type safety
- **Eloquent ORM** - Models with custom scopes (Task, Epic, Run, Review)
- **Contracts** - Interfaces for ProcessManager, ReviewService, AgentHealthTracker
- **Pest testing** - Modern PHP testing framework
- **JSON output** - Commands support `--json` via HandlesJsonOutput trait

## Quality Gates
| Tool | Command | Purpose |
|------|---------|---------|
| Pest | `./vendor/bin/pest --parallel --compact` | Test runner |
| Pint | `./vendor/bin/pint` | Code formatter (PSR-12) |
| Rector | `./vendor/bin/rector` | Auto-refactoring |
| Type Coverage | `composer test:type-coverage` | 100% type coverage |
| Test Coverage | `composer test:coverage` | Min 60% coverage |

## Recent Changes
- 2026-01-15: Added Agent column to `fuel epic:show` output, shows agent that worked on each task
- 2026-01-15: `fuel tasks` now shows full agent name (no truncation, increased Agent column width to 20)
- 2026-01-15: JSON outputs now consistently default `type` field to 'task' when null
- 2026-01-15: Added `fuel close` command to mark tasks closed (reason `closed`)
- 2026-01-15: Added in-progress tasks display to `fuel status` command (task ID, title, agent, runtime, complexity, epic)
- 2026-01-15: Changed `epic:update --selfguided` to explicit `--selfguided`/`--no-selfguided` flags
- 2026-01-15: Added self-guided indicator (◉) to consume TUI task cards
- 2026-01-15: Improved `fuel init` output - now directs to config.yaml + consume workflow
- 2026-01-15: Initialized reality.md with codebase architecture index

_Last updated: 2026-01-15 by UpdateReality_
