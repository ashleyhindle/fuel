<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicAddCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:add
        {title : The epic title}
        {--description= : Epic description}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Add a new epic';

    public function handle(EpicService $epicService): int
    {
        $title = $this->argument('title');
        $description = $this->option('description');

        try {
            $epic = $epicService->createEpic($title, $description);
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        if ($this->option('json')) {
            $this->outputJson($epic->toArray());
        } else {
            $this->info('Created epic: '.$epic->short_id);
            $this->line('  Title: '.$epic->title);
            if ($epic->description) {
                $this->line('  Description: '.$epic->description);
            }
        }

        return self::SUCCESS;
    }
}
