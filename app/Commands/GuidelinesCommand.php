<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class GuidelinesCommand extends Command
{
    protected $signature = 'guidelines
        {--add : Inject guidelines into AGENTS.md}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Output task management guidelines for CLAUDE.md';

    public function handle(): int
    {
        $path = base_path('agent-instructions.md');

        if (! file_exists($path)) {
            $this->error('agent-instructions.md not found');

            return self::FAILURE;
        }

        $content = file_get_contents($path);

        if ($this->option('add')) {
            return $this->injectIntoAgentsMd($content);
        }

        $this->line($content);

        return self::SUCCESS;
    }

    protected function injectIntoAgentsMd(string $content): int
    {
        $cwd = $this->option('cwd') ?: getcwd();
        $agentsMdPath = $cwd.'/AGENTS.md';

        $fuelSection = "<fuel>\n{$content}</fuel>\n";

        if (file_exists($agentsMdPath)) {
            $existing = file_get_contents($agentsMdPath);

            // Replace existing <fuel>...</fuel> section or append
            if (preg_match('/<fuel>.*?<\/fuel>/s', $existing)) {
                $updated = preg_replace('/<fuel>.*?<\/fuel>\n?/s', $fuelSection, $existing);
            } else {
                $updated = rtrim($existing)."\n\n".$fuelSection;
            }

            file_put_contents($agentsMdPath, $updated);
            $this->info('Updated AGENTS.md with Fuel guidelines');
        } else {
            file_put_contents($agentsMdPath, "# Agent Instructions\n\n".$fuelSection);
            $this->info('Created AGENTS.md with Fuel guidelines');
        }

        return self::SUCCESS;
    }
}
