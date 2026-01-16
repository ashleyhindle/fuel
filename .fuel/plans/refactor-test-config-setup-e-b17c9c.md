# Epic: Refactor test config setup (e-b17c9c)

## Plan

Consolidate duplicate temp dir and config setup across 51 test files. Add setConfig() helper to TestCase, then migrate tests to use $this->testDir instead of creating their own $this->tempDir.

<!-- Add implementation plan here -->

## Implementation Notes

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

## Interfaces Created
None - added helper methods to existing TestCase class.