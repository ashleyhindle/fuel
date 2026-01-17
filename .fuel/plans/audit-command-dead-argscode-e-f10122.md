# Epic: Audit command dead args/code (e-f10122)

## Plan

- Inventory `app/Commands/*.php` signatures. Prefer PHP script using `Illuminate\Console\Parser::parse` on each `$signature` (autoload via `vendor/autoload.php`); output args/options per command.
- Build usage map per command: `rg` for `$this->argument('x')`, `$this->option('x')`, `$this->input->getArgument('x')`, `$this->input->getOption('x')`; include base classes/traits (`BrowserCommand`, `HandlesJsonOutput`) and shared helpers.
- Diff signature vs usage to list candidates. Manual verify per command for implicit use (passed into services, or handled in parents). Record findings in Implementation Notes.
- Fix: remove dead args/options, or wire them into behavior; delete unused private/protected methods/props in `app/Commands/`. Update command descriptions/help if needed.
- Tests: update/add Pest tests for touched commands; run minimal tests (file or `--filter`) and log commands run.

## Acceptance Criteria

- [ ] Each command in `app/Commands/` has no unused signature args/options; any removals/usages documented in Implementation Notes.
- [ ] No unused private/protected methods/props remain in `app/Commands/` after cleanup (or explicitly justified in Implementation Notes).
- [ ] Relevant Pest tests updated/added for modified commands; minimal tests run and recorded in Progress Log.

## Progress Log

<!-- Self-guided task appends progress entries here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
