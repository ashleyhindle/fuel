<fuel-prompt version="1" />

== UPDATE REALITY INDEX ==

You are updating .fuel/reality.md - a lean architectural index of this codebase.
This file helps AI agents quickly understand the codebase structure.

== COMPLETED WORK ==
{{context.completed_work}}

== INSTRUCTIONS ==
1. Read {{context.reality_path}} (create if missing using the structure below)
2. Update based on the completed work above
3. Keep it LEAN - this is an INDEX, not documentation
4. Focus on:
   - New modules/services/commands added → add to Modules table
   - New patterns discovered → add to Patterns section
   - Entry points for common tasks → update Entry Points
   - Append to Recent Changes (keep last 5-10 entries)
   - Remove stale/outdated content if the work changed existing modules

== REALITY.MD STRUCTURE ==
If the file doesn't exist, create it with this structure:

```markdown
# Reality

## Architecture
Brief 2-3 sentence overview of what this codebase is and how it's structured.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| Example | What it does | app/path/File.php |

## Entry Points
**Add a command:** Copy `app/Commands/ExampleCommand.php`
**Add a service:** Create in `app/Services/`, inject via constructor or `app()`

## Patterns
- Pattern: Description

## Recent Changes
- YYYY-MM-DD: Brief description of change

_Last updated: YYYY-MM-DD by UpdateReality_
```

== RULES ==
- Be concise - one line per module/pattern
- Don't duplicate information already in CLAUDE.md
- Update the "Last updated" timestamp
- Only modify .fuel/reality.md - no other files
