# Epic: Audit all commands for unused arguments/options (e-2583fc)

## Plan

Audit every command in `app/Commands/` to find dead code where arguments or options are defined in the signature but never used (or used but discarded).

### Methodology

For each command file:

1. Extract the `$signature` - note all `{arguments}` and `{--options}`
2. Search the file for usage of each:
   - Arguments: `$this->argument('name')`
   - Options: `$this->option('name')`
3. Check if the return value is actually used:
   - **Dead**: `$this->option('foo');` (no assignment)
   - **Dead**: `$var = $this->option('foo');` where `$var` is never referenced
   - **Live**: `if ($this->option('foo')) { ... }`
   - **Live**: `$x = $this->option('foo'); doSomething($x);`
4. If dead, either:
   - Remove from signature and delete the dead code
   - Implement the intended functionality if it was meant to work
   - Update tests that check for the removed option

### Commands to Audit

```bash
ls app/Commands/*.php | wc -l  # Get count
```

Focus on non-browser commands first (browser commands were recently audited):
- `AddCommand.php`
- `BoardCommand.php`
- `CloseCommand.php`
- `ConfigCommand.php`
- `ConsumeCommand.php` (already cleaned: --interval, --agent removed)
- `ConsumeRunnerCommand.php` (already cleaned: --interval removed)
- `ContextCommand.php`
- `DependencyCommand.php`
- `DoneCommand.php`
- `EditCommand.php`
- `EpicCommand.php`
- `HealthCommand.php`
- `InitCommand.php`
- `LogCommand.php`
- `NextCommand.php`
- `OpenCommand.php`
- `OutputCommand.php`
- `PlanCommand.php`
- `PromoteCommand.php`
- `RerunCommand.php`
- `ReviewCommand.php`
- `RunsCommand.php`
- `ShowCommand.php`
- `SmokeTestCommand.php`
- `StartCommand.php`
- `StatusCommand.php`
- `StopCommand.php`
- `TreeCommand.php`
- `UnblockCommand.php`
- `UpdateCommand.php`

### Example Dead Code Patterns Found Previously

```php
// Pattern 1: Read and discard
$this->option('interval');  // Dead - no assignment

// Pattern 2: Read and assign but never use
max(1, (int) $this->option('interval'));  // Dead - result discarded
```

## Acceptance Criteria

- [ ] Every command in app/Commands/ has been audited
- [ ] All unused arguments/options have been removed or implemented
- [ ] Tests updated to reflect any signature changes
- [ ] All tests pass after changes

## Progress Log

<!-- Self-guided task appends progress entries here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
