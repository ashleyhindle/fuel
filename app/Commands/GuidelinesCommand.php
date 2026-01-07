<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class GuidelinesCommand extends Command
{
    protected $signature = 'guidelines';

    protected $description = 'Output task management guidelines for CLAUDE.md';

    public function handle(): int
    {
        $path = base_path('agent-instructions.md');

        if (! file_exists($path)) {
            $this->error('agent-instructions.md not found');

            return self::FAILURE;
        }

        $this->line(file_get_contents($path));

        return self::SUCCESS;
    }
}
