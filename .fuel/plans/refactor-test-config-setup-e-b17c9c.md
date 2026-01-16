# Epic: Refactor test config setup (e-b17c9c)

## Plan

Consolidate duplicate temp dir and config setup across 51 test files. Add setConfig() helper to TestCase, then migrate tests to use $this->testDir instead of creating their own $this->tempDir.

<!-- Add implementation plan here -->

## Implementation Notes

### Review: Epic completion check (f-ec4f5a)
Status: not complete yet.

Checks run:
- `./vendor/bin/pest --parallel --compact` -> pass (6 skipped, 1342 passed).
- `rg "tempDir.*=.*sys_get_temp_dir" tests/Feature` -> **still matches**:
  - `tests/Feature/Commands/DbCommandTest.php`
  - `tests/Feature/Commands/InitCommandTest.php`
  - `tests/Feature/Commands/SelfUpdateCommandTest.php`

Remaining work:
- Migrate the three files above to `$this->testDir` (or document exceptions if needed).
- Re-run the grep to confirm zero matches.
- CI status still unverified locally.

### Helper Methods Added (f-8b872d)
Added two protected helper methods to `tests/TestCase.php`:

1. **`getDefaultConfigYaml(): string`** - Returns the default minimal test config YAML
   - Extracted from existing setUp() code
   - Contains test-agent configuration with echo command
   - Can be called by tests that need the default config

2. **`setConfig(string $yaml): void`** - Allows tests to customize config
   - Writes YAML to `$this->testDir/.fuel/config.yaml`
   - Resets ConfigService in container to pick up new config
   - Ensures ConfigService uses the test FuelContext
   
**Pattern for tests to follow:**
```php
// To use custom config:
$customYaml = <<<'YAML'
primary: custom-agent
agents:
  custom-agent:
    driver: claude
    command: custom-command
YAML;
$this->setConfig($customYaml);

// ConfigService will now use the custom config
```

**Key Decision:** setConfig() resets ConfigService using the same pattern as setUp() - it forgets the instance, creates a new ConfigService with testContext, and rebinds it. This ensures consistency with the test isolation pattern.

### Batch 3: Epics-Init Tests Migrated (f-8fc3db)
Migrated 9 test files to use TestCase's `$this->testDir`:

1. **EpicsCommandTest.php** - Removed file-level and describe-level beforeEach/afterEach
2. **EpicShowCommandTest.php** - Removed file-level and describe-level beforeEach/afterEach
3. **EpicUpdateCommandTest.php** - Kept beforeEach for `$this->epicService` assignment only
4. **GuidelinesCommandTest.php** - Kept beforeEach for AGENTS.md cleanup, changed tempDir→testDir
5. **HealthCommandTest.php** - Simplified to just get tracker from container
6. **HealthResetCommandTest.php** - Simplified to just get tracker from container
7. **HumanCommandTest.php** - Kept beforeEach for taskService assignment
8. **InitCommandTest.php (Commands/)** - Kept beforeEach for taskService/dbPath
9. **InitCommandTest.php (Feature/)** - Removed all beforeEach/afterEach

**Pattern established:** Tests that need specific service instances can use `app(ServiceClass::class)` in a minimal beforeEach. The TestCase handles all temp directory creation, database setup, and cleanup.

### Batch 1: Add-Db Tests Migrated (f-409cdf)
Migrated 8 test files to use TestCase's `$this->testDir`:

1. **AddCommandTest.php** - Kept minimal beforeEach for dbPath/taskService/epicService. Changed tempDir→testDir. Removed unused DatabaseService imports and variables.
2. **AvailableCommandTest.php** - Kept minimal beforeEach for taskService only
3. **BacklogCommandTest.php** - Removed all beforeEach/afterEach entirely
4. **BlockedCommandTest.php** - Kept minimal beforeEach for taskService only
5. **CloseCommandTest.php** - Kept minimal beforeEach for taskService only
6. **CompletedCommandTest.php** - Kept minimal beforeEach for taskService only
7. **ConsumeRunnerCommandTest.php** - Removed all beforeEach/afterEach (tests only check command registration)
8. **DbCommandTest.php** - Special case: "database exists" tests use testDir; "database not found" tests create their own temp dir without database

**Key patterns:**
- Most tests just need `$this->taskService = app(TaskService::class)` in beforeEach
- Tests that need no database (DbCommandTest error cases) must create separate temp dir
- Unused DatabaseService variables from old boilerplate were removed

### Batch 4: Promote-ReviewShow Tests Migrated (f-20621c)
Migrated 10 test files to use TestCase's `$this->testDir`:

1. **PromoteCommandTest.php** - Kept minimal beforeEach for taskService only
2. **QCommandTest.php** - Kept minimal beforeEach for taskService only
3. **ReadyCommandTest.php** - Two describe blocks, each with minimal beforeEach for taskService
4. **RemoveCommandTest.php** - Kept minimal beforeEach for taskService only
5. **ReopenCommandTest.php** - Kept minimal beforeEach for taskService only
6. **ResumeCommandTest.php** - Kept minimal beforeEach for taskService; one test uses `$this->setConfig()` for custom config
7. **RetryCommandTest.php** - Kept minimal beforeEach for taskService and runService
8. **ReviewCommandTest.php** - Kept minimal beforeEach for taskService only (file-level)
9. **ReviewsCommandTest.php** - Kept minimal beforeEach for databaseService and reviewRepo (file-level)
10. **ReviewShowCommandTest.php** - Kept minimal beforeEach for databaseService, reviewRepo, taskService, runService; changed tempDir→testDir for process directory

**Key patterns continued:**
- Services obtained via `app(ServiceClass::class)` instead of `$this->app->make()`
- Custom config uses `$this->setConfig()` helper instead of manual file writes
- `$this->tempDir` references in tests changed to `$this->testDir`

### Batch 6: Stuck-Update & EpicCommands Tests Migrated (f-3c814a)
Migrated 7 test files to use TestCase's `$this->testDir`:

1. **StuckCommandTest.php** - Kept minimal beforeEach for taskService and runService only
2. **SummaryCommandTest.php** - Kept minimal beforeEach for taskService only
3. **TasksCommandTest.php** - Kept minimal beforeEach for taskService only
4. **TreeCommandTest.php** - Kept minimal beforeEach for taskService only
5. **TriggerReviewCommandTest.php** - Kept minimal beforeEach for taskService, runService, and mock ReviewServiceInterface; creates runs directory; one test uses `$this->setConfig()` for custom config with review agent
6. **UpdateCommandTest.php** - Kept minimal beforeEach for taskService only
7. **EpicCommandsTest.php** - Kept minimal beforeEach for taskService and epicService only; changed tempDir→testDir for plan file paths

**Key patterns continued:**
- Mockery cleanup kept in afterEach for TriggerReviewCommandTest
- TriggerReviewCommandTest creates runs directory via FuelContext in beforeEach
- Custom config uses `$this->setConfig()` with YAML heredoc instead of Yaml::dump()

### Batch 5: Runs-Status Tests Migrated (f-26ba2e)
Migrated 9 test files to use TestCase's `$this->testDir`:

1. **RunsCommandTest.php** - Kept minimal beforeEach for taskService only
2. **RunShowCommandTest.php** - Kept minimal beforeEach for taskService and runService only
3. **SelfGuidedBlockedCommandTest.php** - Kept minimal beforeEach for taskService only
4. **SelfGuidedContinueCommandTest.php** - Kept minimal beforeEach for taskService only
5. **SelfUpdateCommandTest.php** - **Special case**: Kept file-level beforeEach/afterEach for HOME environment variable manipulation needed by tests. Removed all describe-level database setup since tests only use reflection to test command methods directly - no database needed.
6. **ShowCommandTest.php** - Kept minimal beforeEach for taskService only; changed tempDir→testDir for process directory paths
7. **StartCommandTest.php** - Kept minimal beforeEach for taskService only
8. **StatsCommandTest.php** - Kept minimal beforeEach for taskService, runService, epicService (via makeEpicService), and databaseService
9. **StatusCommandTest.php** - Kept minimal beforeEach for taskService only

**Key patterns continued:**
- Services obtained via `app(ServiceClass::class)`
- `$this->tempDir` references in tests changed to `$this->testDir`
- Tests that don't need database (like SelfUpdateCommandTest's command method tests) don't need the boilerplate at all

**Special note on SelfUpdateCommandTest:**
This test file has a unique structure - it tests SelfUpdateCommand methods using reflection without actually running commands through the framework. The file-level beforeEach/afterEach creates a mock HOME directory structure for testing the `getHomeDirectory()` method. The describe-level database setup was completely unnecessary and removed (780+ lines of boilerplate eliminated across all 9 files).

### Remaining tempDir Usage - Final (f-e011f1)
Migrated SelfUpdateCommandTest.php to use `$this->testDir` instead of creating its own `$this->tempDir`.

**Remaining sys_get_temp_dir() usage (INTENTIONAL - special cases):**
1. **DbCommandTest.php** (lines 9, 27) - Two tests that verify error handling when database does NOT exist. These must create a separate temp dir without migrations/database.
2. **InitCommandTest.php** (line 78) - Test that verifies error handling for orphaned WAL files. Creates separate temp dir for isolated WAL file testing.

These 3 remaining usages are INTENTIONAL and NECESSARY for testing edge cases where the TestCase's pre-configured database would interfere with the test scenario.

**Verification:** `rg 'tempDir.*=.*sys_get_temp_dir' tests/Feature` now returns only these 3 intentional cases.

## Interfaces Created
None - added helper methods to existing TestCase class.