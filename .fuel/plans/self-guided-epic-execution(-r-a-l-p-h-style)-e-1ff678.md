# Epic: Self-Guided Epic Execution (e-1ff678)

## Overview

Add a self-guided execution mode for epics where a single task iterates until all acceptance criteria are met, learning and adapting as it goes. This provides an alternative to the current parallel task approach.

## Key Design Decisions

- **Explicit commands over exit codes**: Exit 1 conflicts with existing network error/permission handling. Use `fuel selfguided:continue` and `fuel done` instead.
- **Task cycling**: Task cycles `open → in_progress → open` between iterations (natural re-queuing)
- **Iteration tracking**: New DB columns for reliable tracking across daemon restarts
- **Max iterations**: 50
- **Stuck detection**: Track consecutive failures per criterion, not just total iterations
- **Plan file as state**: Agent checks off criteria, appends progress log
- **Always complex**: Self-guided tasks use `complexity=complex` (capable model)

## Acceptance Criteria

- [ ] `fuel epic:add "X" --selfguided` creates epic with self_guided=true
- [ ] Self-guided epic auto-creates single task with agent=selfguided
- [ ] TaskSpawner routes selfguided tasks to SelfGuidedAgentTask
- [ ] `fuel selfguided:continue` increments iteration and reopens task
- [ ] `fuel selfguided:blocked` creates needs-human task and adds dependency
- [ ] Max iterations (50) triggers needs-human task
- [ ] Stuck count >= 3 triggers needs-human task
- [ ] Prompt template includes reality.md, plan, iteration info
- [ ] Skills updated to ask about execution mode
- [ ] All tests pass

## Implementation Notes

### Database Schema

```php
// tasks table
$table->string('agent')->nullable();
$table->integer('selfguided_iteration')->default(0);
$table->integer('selfguided_stuck_count')->default(0);

// epics table
$table->boolean('self_guided')->default(false);
```

### Task Routing (TaskSpawner.php ~line 106)

```php
if ($task->agent === 'selfguided') {
    $agentTask = app(SelfGuidedAgentTask::class, ['task' => $task]);
} else {
    $agentTask = app(WorkAgentTask::class, [...]);
}
```

### Prompt Template Variables

- `{{ iteration }}` - current iteration (1-based)
- `{{ max_iterations }}` - 50
- `{{ reality }}` - contents of .fuel/reality.md
- `{{ plan }}` - contents of epic plan file
- `{{ progress_log }}` - extracted from plan file
- `{{ task.short_id }}`, `{{ task.title }}`
- `{{ epic.short_id }}`
- `{{ epic_plan_filename }}`

## Progress Log
<!-- Tasks append discoveries here -->
