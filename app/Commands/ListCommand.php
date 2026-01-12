<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    protected $signature = 'list';

    protected $description = 'List all available commands';

    public function handle(): int
    {
        $commands = $this->getApplication()->all();
        ksort($commands);

        $this->newLine();
        $this->line('<fg=white;options=bold>Fuel</> version '.$this->getApplication()->getVersion());
        $this->newLine();

        $grouped = [];
        foreach ($commands as $name => $command) {
            if ($command->isHidden()) {
                continue;
            }

            $namespace = $name;
            if (strpos($name, ':') !== false) {
                $namespace = explode(':', $name)[0];
            } else {
                $namespace = 'global';
            }

            $grouped[$namespace][] = $command;
        }

        ksort($grouped);

        foreach ($grouped as $namespace => $cmds) {
            $this->line("<comment>$namespace</comment>");
            foreach ($cmds as $cmd) {
                $this->line(sprintf('  <info>%-30s</info> %s', $cmd->getName(), $cmd->getDescription()));
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
