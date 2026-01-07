<?php

declare(strict_types=1);

namespace Laravel\Boost\Console\Fuel;

use Illuminate\Console\Command;
use Laravel\Boost\Fuel\TaskService;
use RuntimeException;

class AddCommand extends Command
{
    protected $signature = 'fuel:add
        {title : The task title}
        {--type=task : Task type (bug|feature|task|epic|chore)}
        {--priority=2 : Priority 0-4 (0=critical, 4=backlog)}
        {--description= : Long description}
        {--blocked-by= : Comma-separated task IDs this is blocked by}
        {--labels= : Comma-separated labels}
        {--json : Output JSON instead of human-readable}';

    protected $description = 'Add a new fuel task';

    public function handle(TaskService $service): int
    {
        try {
            $service->initialize();

            /** @var string $title */
            $title = $this->argument('title');

            /** @var string $type */
            $type = $this->option('type');

            /** @var string|null $priority */
            $priority = $this->option('priority');

            /** @var string|null $description */
            $description = $this->option('description');

            /** @var string|null $labels */
            $labels = $this->option('labels');

            /** @var string|null $blockedBy */
            $blockedBy = $this->option('blocked-by');

            $data = [
                'title' => $title,
                'type' => $type,
                'priority' => (int) $priority,
                'description' => $description,
                'labels' => $this->parseCommaSeparated($labels),
                'dependencies' => $this->parseDependencies($blockedBy),
            ];

            $task = $service->create($data);

            if ($this->option('json')) {
                $this->line((string) json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $taskId = (string) $task['id']; // @phpstan-ignore cast.string
                $taskTitle = (string) $task['title']; // @phpstan-ignore cast.string
                $taskType = (string) $task['type']; // @phpstan-ignore cast.string
                $taskPriority = (int) $task['priority']; // @phpstan-ignore cast.int
                $this->info("Created task: {$taskId}");
                $this->line("  Title: {$taskTitle}");
                $this->line("  Type: {$taskType}");
                $this->line("  Priority: P{$taskPriority}");

                if (! empty($task['dependencies'])) {
                    /** @var array<int, array{depends_on: string, type: string}> $deps */
                    $deps = $task['dependencies'];
                    $depIds = collect($deps)->pluck('depends_on')->implode(', ');
                    $this->line("  Blocked by: {$depIds}");
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $runtimeException->getMessage()]));
            } else {
                $this->error($runtimeException->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseCommaSeparated(?string $value): array
    {
        if (empty($value)) {
            return [];
        }

        return array_map(trim(...), explode(',', $value));
    }

    /**
     * @return array<int, array{depends_on: string, type: string}>
     */
    private function parseDependencies(?string $value): array
    {
        if (empty($value)) {
            return [];
        }

        $ids = $this->parseCommaSeparated($value);

        return array_map(fn (string $id): array => ['depends_on' => $id, 'type' => 'blocks'], $ids);
    }
}
