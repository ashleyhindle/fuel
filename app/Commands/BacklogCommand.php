<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\BacklogService;
use LaravelZero\Framework\Commands\Command;

class BacklogCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'backlog
        {--json : Output as JSON}';

    protected $description = 'List all backlog items';

    public function handle(BacklogService $backlogService): int
    {
        $items = $backlogService->all();

        if ($this->option('json')) {
            $this->outputJson($items->values()->toArray());
        } else {
            if ($items->isEmpty()) {
                $this->info('No backlog items.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Backlog items (%d):', $items->count()));
            $this->newLine();

            $this->table(
                ['ID', 'Title', 'Created'],
                $items->map(fn (array $item): array => [
                    $item['id'],
                    $item['title'],
                    $item['created_at'],
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
