<?php

declare(strict_types=1);

namespace App\Process;

readonly class ParsedEvent
{
    public function __construct(
        public string $type,
        public ?string $subtype = null,
        public ?string $text = null,
        public ?string $toolName = null,
        public ?array $raw = null,
    ) {}
}
