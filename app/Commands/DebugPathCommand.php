<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;

class DebugPathCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'debug:path
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Show diagnostic information about fuel binary path detection';

    public function handle(FuelContext $context): int
    {
        $argv0 = $_SERVER['argv'][0] ?? null;
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $underscore = $_SERVER['_'] ?? null;
        $phpSelf = $_SERVER['PHP_SELF'] ?? null;
        $pathTranslated = $_SERVER['PATH_TRANSLATED'] ?? null;
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;

        $argv0Realpath = $argv0 !== null ? realpath($argv0) : false;
        $whichResult = null;

        if ($argv0 !== null && ! str_contains((string) $argv0, '/')) {
            $whichResult = trim((string) shell_exec('which '.escapeshellarg((string) $argv0).' 2>/dev/null'));
        }

        $resolvedPath = null;
        $resolvedError = null;
        try {
            $resolvedPath = $context->getFuelBinaryPath();
        } catch (\RuntimeException $runtimeException) {
            $resolvedError = $runtimeException->getMessage();
        }

        $data = [
            'argv[0]' => $argv0,
            'argv[0] realpath' => $argv0Realpath !== false ? $argv0Realpath : '(failed)',
            'which result' => $whichResult ?: '(n/a - argv[0] contains /)',
            'SCRIPT_FILENAME' => $scriptFilename,
            '$_SERVER[_]' => $underscore,
            'PHP_SELF' => $phpSelf,
            'PATH_TRANSLATED' => $pathTranslated,
            'SCRIPT_NAME' => $scriptName,
            '__FILE__' => __FILE__,
            'PHP_BINARY' => PHP_BINARY,
            'cwd' => getcwd(),
            'resolved binary' => $resolvedPath ?? '(failed: '.$resolvedError.')',
            '.fuel path' => $context->basePath,
        ];

        if ($this->option('json')) {
            $this->outputJson($data);

            return self::SUCCESS;
        }

        $this->info('Fuel Binary Path Debug Info');
        $this->line('');
        foreach ($data as $key => $value) {
            $this->line(sprintf('  <comment>%s:</comment> %s', str_pad($key, 20), $value));
        }

        return self::SUCCESS;
    }
}
