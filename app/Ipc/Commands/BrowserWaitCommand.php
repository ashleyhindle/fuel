<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;

class BrowserWaitCommand extends IpcCommand
{
    public function __construct(
        public readonly string $pageId,
        public readonly ?string $selector = null,
        public readonly ?string $url = null,
        public readonly ?string $text = null,
        public readonly string $state = 'visible',
        public readonly int $timeout = 30000,
    ) {
        parent::__construct(ConsumeCommandType::BrowserWait);
    }
}