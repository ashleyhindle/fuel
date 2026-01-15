<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;

final class BrowserHtmlCommand extends BaseCommand
{
    public function __construct(
        public readonly string $pageId,
        public readonly ?string $selector = null,
        public readonly ?string $ref = null,
        public readonly bool $inner = false,
    ) {
        parent::__construct();
    }

    public function getCommandType(): ConsumeCommandType
    {
        return ConsumeCommandType::BrowserHtml;
    }

    public function toArray(): array
    {
        $params = [
            'pageId' => $this->pageId,
            'inner' => $this->inner,
        ];

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
