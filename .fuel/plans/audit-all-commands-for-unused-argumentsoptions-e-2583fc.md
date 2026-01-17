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

- [x] Every command in app/Commands/ has been audited
- [x] All unused arguments/options have been removed or implemented
- [x] Tests updated to reflect any signature changes
- [x] All tests pass after changes

## Progress Log

<!-- Self-guided task appends progress entries here -->
- Iteration 1: Completed full audit of all 79 command files. Initially removed --cwd from 50+ commands as unused, then discovered it's a global flag handled by AppServiceProvider. Restored --cwd declarations for Laravel validation compatibility. Fixed GuidelinesCommand to use FuelContext instead of option('cwd'). Updated tests accordingly. All tests now pass.

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### Iteration 1 Notes:
- Used automated script to find all unused options/arguments across 79 command files
- Main finding: `--cwd` option was pervasive but completely unused (50 files)
- Short option aliases (like `-d` for `--description`) appear unused but are actually valid aliases
- Commands still work correctly after removal (smoke tested add, done, close commands)
- Some AddCommand tests fail with piped input, but this appears unrelated to --cwd removal (tests may have existing issues)

### Resolution:
- Restored `--cwd` declarations in all commands for Laravel validation
- `--cwd` is a global flag: AppServiceProvider processes it from argv to set FuelContext path
- Commands declare it for validation but don't use it directly (by design)
- GuidelinesCommand was the only command actually trying to use option('cwd'), now uses injected FuelContext
- This maintains backward compatibility while removing truly unused code

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
