<?php

use App\Models\Task;
use App\Prompts\ReviewPrompt;

beforeEach(function (): void {
    $this->reviewPrompt = $this->app->make(ReviewPrompt::class);
});

it('contains the task ID in the prompt', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'A test description',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('f-abc123');
    // Task ID should appear multiple times (in header and fuel done command)
    expect(substr_count((string) $prompt, 'f-abc123'))->toBeGreaterThanOrEqual(3);
});

it('contains the task title in the prompt', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Implement user authentication',
        'description' => 'Add login flow',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('Implement user authentication');
});

it('contains the task description in the prompt', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'This is a detailed description of the task requirements.',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('This is a detailed description of the task requirements.');
});

it('contains the git diff in the prompt', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $gitDiff = <<<'DIFF'
diff --git a/src/file.php b/src/file.php
index 1234567..abcdefg 100644
--- a/src/file.php
+++ b/src/file.php
@@ -10,6 +10,8 @@ class Example
     public function test()
     {
+        return true;
     }
 }
DIFF;

    $prompt = $this->reviewPrompt->generate($task, $gitDiff, '');

    expect($prompt)->toContain('diff --git a/src/file.php b/src/file.php');
    expect($prompt)->toContain('return true;');
});

it('contains the git status in the prompt', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $gitStatus = <<<'STATUS'
On branch main
Changes not staged for commit:
  modified:   src/file.php
  modified:   tests/FileTest.php

Untracked files:
  src/NewClass.php
STATUS;

    $prompt = $this->reviewPrompt->generate($task, '', $gitStatus);

    expect($prompt)->toContain('On branch main');
    expect($prompt)->toContain('modified:   src/file.php');
    expect($prompt)->toContain('Untracked files:');
});

it('contains fuel commands in the prompt', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    // Check for fuel done command
    expect($prompt)->toContain('fuel done f-abc123');
});

it('contains review checklist sections', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('CHECK UNCOMMITTED CHANGES');
    expect($prompt)->toContain('VERIFY RELEVANT TESTS');
    expect($prompt)->toContain('CHECK TASK COMPLETION');
    expect($prompt)->toContain('Output Your Review Result');
});

it('truncates large diffs', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    // Create a diff larger than 5000 characters
    $largeDiff = str_repeat("+ Added line content here\n", 300); // ~7500 chars

    $prompt = $this->reviewPrompt->generate($task, $largeDiff, '');

    // Should contain truncation notice
    expect($prompt)->toContain('TRUNCATED');
    expect($prompt)->toContain('more characters');

    // The prompt should be shorter than if the full diff was included
    $promptWithShortDiff = $this->reviewPrompt->generate($task, 'short diff', '');
    // Large diff prompt should still be reasonably sized (not include full 7500 chars)
    expect(strlen((string) $prompt))->toBeLessThan(strlen((string) $promptWithShortDiff) + 6000);
});

it('does not truncate small diffs', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $smallDiff = <<<'DIFF'
diff --git a/src/file.php b/src/file.php
--- a/src/file.php
+++ b/src/file.php
@@ -1,3 +1,4 @@
 <?php
+// New comment
 class Example {}
DIFF;

    $prompt = $this->reviewPrompt->generate($task, $smallDiff, '');

    expect($prompt)->not->toContain('TRUNCATED');
    expect($prompt)->toContain($smallDiff);
});

it('handles empty task description gracefully', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => null,
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('No description provided');
});

it('handles missing task fields gracefully', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('f-abc123');
    expect($prompt)->toContain('Untitled task');
    expect($prompt)->toContain('No description provided');
});

it('includes guidance for running tests', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    // Should include guidance about running relevant tests
    expect($prompt)->toContain('only tests related to the files that were changed');
});

it('includes instruction to not run fuel done when issues found', function (): void {
    $task = new Task([
        'short_id' => 'f-abc123',
        'title' => 'Test task',
        'description' => 'Description',
    ]);

    $prompt = $this->reviewPrompt->generate($task, '', '');

    expect($prompt)->toContain('do NOT run fuel done');
});
