<?php

/**
 * One-time migration script to convert old dependency schema to new schema.
 *
 * Old schema: {dependencies: [{depends_on: "fuel-xxx", type: "blocks"}]}
 * New schema: {blocked_by: ["fuel-xxx"]}
 *
 * Usage: php migrate-dependencies.php
 */
$file = '.fuel/tasks.jsonl';

if (! file_exists($file)) {
    echo "Error: {$file} not found\n";
    exit(1);
}

$content = file_get_contents($file);
if ($content === false) {
    echo "Error: Failed to read {$file}\n";
    exit(1);
}

$lines = explode("\n", trim($content));
$tasks = [];
$migratedCount = 0;

foreach ($lines as $line) {
    if (trim($line) === '') {
        continue;
    }

    $task = json_decode($line, true);
    if ($task === null || json_last_error() !== JSON_ERROR_NONE) {
        echo 'Warning: Failed to parse line: '.substr($line, 0, 50)."...\n";

        continue;
    }

    $needsMigration = false;

    // Migrate dependencies to blocked_by
    if (isset($task['dependencies']) && is_array($task['dependencies'])) {
        $blockedBy = [];
        foreach ($task['dependencies'] as $dep) {
            if (isset($dep['depends_on']) && ($dep['type'] ?? '') === 'blocks') {
                $blockedBy[] = $dep['depends_on'];
            }
        }
        $task['blocked_by'] = $blockedBy;
        unset($task['dependencies']);
        $needsMigration = true;
    } else {
        // Ensure blocked_by exists even if no dependencies
        if (! isset($task['blocked_by'])) {
            $task['blocked_by'] = [];
        }
    }

    if ($needsMigration) {
        $migratedCount++;
    }

    $tasks[] = $task;
}

// Sort by ID for merge-friendly git diffs (same as TaskService)
usort($tasks, fn ($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));

// Write back with atomic write (temp file + rename, same as TaskService)
$tempPath = $file.'.tmp';
$content = implode("\n", array_map(
    fn ($task) => json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $tasks
));

if ($content !== '') {
    $content .= "\n";
}

file_put_contents($tempPath, $content);
rename($tempPath, $file);

echo "Migration complete: converted {$migratedCount} tasks from old dependency schema to blocked_by\n";
echo 'Total tasks processed: '.count($tasks)."\n";
