<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class TestSelfGuidedCommand extends Command
{
    protected $signature = 'test:selfguided';

    protected $description = 'Test command for self-guided execution mode';

    public function handle(): int
    {
        $this->info('Self-guided test command executed successfully!');
        $this->line('This command demonstrates the self-guided execution mode.');

        // Simple logic to test
        $number = random_int(1, 100);
        $this->line("Random number generated: {$number}");

        if ($number > 50) {
            $this->comment('Number is greater than 50');
        } else {
            $this->comment('Number is 50 or less');
        }

        return self::SUCCESS;
    }
}