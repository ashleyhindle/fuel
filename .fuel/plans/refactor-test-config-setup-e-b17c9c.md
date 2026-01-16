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

## Interfaces Created
None - added helper methods to existing TestCase class.