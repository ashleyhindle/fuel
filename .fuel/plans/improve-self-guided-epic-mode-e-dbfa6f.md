# Epic: Improve self-guided epic mode (e-dbfa6f)

## Problem

Self-guided mode doesn't work properly because:
1. Plan template is the same for parallel and self-guided modes - missing `## Acceptance Criteria` checkboxes and `## Progress Log` section
2. `EpicUpdateCommand --selfguided` only sets DB flag - doesn't create the selfguided task or update plan
3. Agents complete everything in one loop because there are no explicit criteria to iterate over

## Plan

Mode-aware plan templates + proper selfguided transition handling.

### 1. EpicAddCommand.php - Self-guided template

Modify `createPlanFile()` to accept `$selfGuided` parameter and use different template.

**Self-guided template includes:**
- `## Acceptance Criteria` with placeholder checkboxes
- `## Progress Log` section (required by SelfGuidedAgentTask.extractProgressLog())
- `## Implementation Notes`

### 2. EpicUpdateCommand.php - Handle selfguided transition

When `--selfguided` is passed on an epic that wasn't selfguided:
1. Check if selfguided task already exists (avoid duplicates)
2. Create selfguided task (agent='selfguided', complexity='complex')
3. Update plan file with selfguided sections if missing
4. Output plan path in response

### 3. fuel-create-plan skill - Format guidance

Update skill to show explicit templates for each mode, with CRITICAL note that selfguided needs checkbox criteria.

### 4. Tests

Test that selfguided epic creation and transition work correctly.

## Files to Modify

| File | Change |
|------|--------|
| `app/Commands/EpicAddCommand.php` | Mode-aware plan template |
| `app/Commands/EpicUpdateCommand.php` | Create task + update plan on transition |
| `resources/skills/fuel-create-plan/SKILL.md` | Add explicit format guidance |
| `tests/Feature/EpicCommandsTest.php` | Test coverage |

## Implementation Notes

### Task f-516e02: EpicAddCommand mode-aware plan template

**Completed:**
- Modified `createPlanFile()` method in `app/Commands/EpicAddCommand.php` to accept `$selfGuided` parameter (line 59)
- Added conditional template logic: when `$selfGuided=true`, includes `## Acceptance Criteria` (with 3 placeholder checkboxes) and `## Progress Log` sections
- Updated call site at line 40 to pass `$selfGuided` parameter from the command option
- Verified both modes work correctly via manual testing

**Pattern established:**
- Self-guided template must include checkboxes in Acceptance Criteria section (required by SelfGuidedAgentTask.extractProgressLog())
- Progress Log section must be present (even if empty) for self-guided mode
- Regular mode template unchanged (no Acceptance Criteria or Progress Log sections)

### Task f-a9cdeb: fuel-create-plan skill format guidance

**Completed:**
- Updated `resources/skills/fuel-create-plan/SKILL.md` section 6 "Document the Plan"
- Added two explicit templates: Parallel Mode (default) and Self-Guided Mode
- Parallel mode includes: `## Plan`, `## Implementation Notes`, `## Interfaces Created`
- Self-guided mode includes: `## Plan`, `## Acceptance Criteria` (with checkbox placeholders), `## Progress Log`, `## Implementation Notes`
- Added CRITICAL warning that without explicit `- [ ]` checkbox criteria, agents complete everything in one pass instead of iterating
- Provided guidance on what makes good acceptance criteria (specific, testable, independent, measurable)

**Pattern for future documentation:**
- Show templates side-by-side when modes differ
- Highlight critical behavior differences with **CRITICAL** prefix
- Provide concrete examples (checkbox format, not just "add criteria")

### Task f-033518: Tests for selfguided epic creation and transition

**Completed:**
- Added comprehensive tests to `tests/Feature/EpicCommandsTest.php` covering all requirements
- Test suite includes:
  1. `epic:add --selfguided` creates plan with `## Acceptance Criteria` (with checkboxes) and `## Progress Log` sections
  2. Regular `epic:add` (without --selfguided) creates plan without those sections
  3. `epic:update --selfguided` creates selfguided task when transitioning from regular to selfguided mode
  4. `epic:update --selfguided` is idempotent - doesn't create duplicate tasks on repeated calls
  5. Plan file is updated with required sections when transitioning to selfguided mode
  6. Plan file is created from scratch with selfguided template if missing during transition
  7. No changes occur when updating an already selfguided epic

**Test Patterns Established:**
- Use JSON output mode (`--json`) to reliably extract epic/task IDs from command output
- Create plans directory explicitly in tests when manually creating plan files
- Use `$this->epicService->getTasksForEpic()` to verify task creation instead of parsing output
- Each test is self-contained with its own epic/task creation for isolation

**Key Learnings:**
- EpicAddCommand does not create selfguided tasks - only marks the epic as selfguided
- Selfguided tasks are created only during transition via EpicUpdateCommand
- Plan file sections are inserted in proper order (Acceptance Criteria before Progress Log before Implementation Notes)

## Interfaces Created
<!-- Tasks add interfaces/contracts created -->
