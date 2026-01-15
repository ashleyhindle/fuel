<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;

final class BrowserTextCommand extends BaseCommand
{
    public function __construct(
        public readonly string $pageId,
        public readonly ?string $selector = null,
        public readonly ?string $ref = null,
    ) {
        parent::__construct();
    }

    public function getCommandType(): ConsumeCommandType
    {
        return ConsumeCommandType::BrowserText;
    }

    public function toArray(): array
    {
        $params = ['pageId' => $this->pageId];

        if ($this->selector !== null) {
            $params['selector'] = $this->selector;
        }

        if ($this->ref !== null) {
            $params['ref'] = $this->ref;
        }

        return [
            'type' => $this->getCommandType()->value,
            'params' => $params,
            'id' => $this->id,
        ];
    }
}
