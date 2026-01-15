<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicUpdateCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:update
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--title= : Update epic title}
        {--description= : Update epic description}
        {--selfguided : Enable self-guided mode}
        {--no-selfguided : Disable self-guided mode}';

    protected $description = 'Update epic fields';

    public function handle(EpicService $epicService): int
    {
        $updateData = [];

        if ($title = $this->option('title')) {
            $updateData['title'] = $title;
        }

        // Handle description - can be empty string to clear it
        if ($this->option('description') !== null) {
            $updateData['description'] = $this->option('description') ?: null;
        }

        // Handle self_guided flag explicitly
        if ($this->option('selfguided') && $this->option('no-selfguided')) {
            return $this->outputError('Cannot use both --selfguided and --no-selfguided flags.');
        }
        if ($this->option('selfguided')) {
            $updateData['self_guided'] = true;
        } elseif ($this->option('no-selfguided')) {
            $updateData['self_guided'] = false;
        }

        if (empty($updateData)) {
            return $this->outputError('No update fields provided. Use --title, --description, --selfguided, or --no-selfguided.');
        }

        try {
            $epic = $epicService->updateEpic($this->argument('id'), $updateData);

            if ($this->option('json')) {
                $this->outputJson($epic->toArray());
            } else {
                $this->info('Updated epic: '.$epic->short_id);
                $this->line('  Title: '.$epic->title);
                if ($epic->description) {
                    $this->line('  Description: '.$epic->description);
                }
                if (isset($updateData['self_guided'])) {
                    $status = $epic->self_guided ? 'enabled' : 'disabled';
                    $this->line('  Self-guided: '.$status);
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
