<?php

$file = '.fuel/tasks.jsonl';
$content = file_get_contents($file);
$lines = explode("\n", trim($content));
$updated = [];

foreach ($lines as $line) {
    if (trim($line) === '') {
        continue;
    }

    $task = json_decode($line, true);
    if ($task === null) {
        continue;
    }

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
    } else {
        // Ensure blocked_by exists even if no dependencies
        $task['blocked_by'] = $task['blocked_by'] ?? [];
    }

    $updated[] = json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

file_put_contents($file, implode("\n", $updated)."\n");
echo 'Migration complete: converted '.count($updated)." tasks\n";
