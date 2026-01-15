<?php

declare(strict_types=1);

use App\Process\ParsedEvent;
use App\Services\OutputParser;

beforeEach(function (): void {
    $this->parser = new OutputParser;
});

describe('parseLine', function (): void {
    it('parses assistant text event', function (): void {
        $json = '{"type":"assistant","message":{"content":[{"type":"text","text":"Hello world"}]}}';

        $event = $this->parser->parseLine($json);

        expect($event)->toBeInstanceOf(ParsedEvent::class);
        expect($event->type)->toBe('assistant');
        expect($event->text)->toBe('Hello world');
    });

    it('parses tool_call started event', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"readToolCall":{"args":{"path":"foo.php"}}}}';

        $event = $this->parser->parseLine($json);

        expect($event)->toBeInstanceOf(ParsedEvent::class);
        expect($event->type)->toBe('tool_call');
        expect($event->subtype)->toBe('started');
        expect($event->toolName)->toBe('Read');
    });

    it('parses tool_call completed event', function (): void {
        $json = '{"type":"tool_call","subtype":"completed","tool_call":{"grepToolCall":{}}}';

        $event = $this->parser->parseLine($json);

        expect($event->type)->toBe('tool_call');
        expect($event->subtype)->toBe('completed');
        expect($event->toolName)->toBe('Grep');
    });

    it('parses system event', function (): void {
        $json = '{"type":"system","subtype":"init"}';

        $event = $this->parser->parseLine($json);

        expect($event->type)->toBe('system');
        expect($event->subtype)->toBe('init');
    });

    it('parses thinking event', function (): void {
        $json = '{"type":"thinking","subtype":"delta"}';

        $event = $this->parser->parseLine($json);

        expect($event->type)->toBe('thinking');
        expect($event->subtype)->toBe('delta');
    });

    it('returns null for empty line', function (): void {
        expect($this->parser->parseLine(''))->toBeNull();
        expect($this->parser->parseLine('   '))->toBeNull();
    });

    it('returns null for invalid JSON', function (): void {
        expect($this->parser->parseLine('not json'))->toBeNull();
        expect($this->parser->parseLine('{incomplete'))->toBeNull();
    });

    it('returns null for JSON without type', function (): void {
        expect($this->parser->parseLine('{"foo":"bar"}'))->toBeNull();
    });
});

describe('format', function (): void {
    it('formats assistant text', function (): void {
        $event = new ParsedEvent('assistant', text: 'Hello world');

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('Hello world');
    });

    it('returns null for assistant with no text', function (): void {
        $event = new ParsedEvent('assistant', text: null);
        expect($this->parser->format($event))->toBeNull();

        $event = new ParsedEvent('assistant', text: '');
        expect($this->parser->format($event))->toBeNull();

        $event = new ParsedEvent('assistant', text: '   ');
        expect($this->parser->format($event))->toBeNull();
    });

    it('formats tool_call started with emoji', function (): void {
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Read');

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Read');
    });

    it('formats Read with file path', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'readToolCall' => [
                    'args' => ['path' => 'config/app.php'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Read', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Read config/app.php');
    });

    it('formats Edit with truncated path', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'editToolCall' => [
                    'args' => ['path' => '/Users/ashleyhindle/Code/fuel/app/Services/TaskService.php'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Edit', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Edit Services/TaskService.php');
    });

    it('formats Write with file path', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'writeToolCall' => [
                    'args' => ['path' => 'tests/Feature/NewTest.php'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Write', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Write Feature/NewTest.php');
    });

    it('formats Bash with command', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'shellToolCall' => [
                    'args' => ['command' => './vendor/bin/pest tests/Unit/TaskServiceTest.php'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Bash', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Bash ./vendor/bin/pest tests/Unit/TaskService...');
    });

    it('formats Bash with short command', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'shellToolCall' => [
                    'args' => ['command' => 'git status'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Bash', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Bash git status');
    });

    it('formats Grep with pattern', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'grepToolCall' => [
                    'args' => ['pattern' => 'reality-'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Grep', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe("🔧 Grep 'reality-'");
    });

    it('falls back to basic format when args missing', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'readToolCall' => [],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Read', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Read');
    });

    it('falls back to basic format for unknown tool', function (): void {
        $raw = [
            'type' => 'tool_call',
            'subtype' => 'started',
            'tool_call' => [
                'customToolCall' => [
                    'args' => ['foo' => 'bar'],
                ],
            ],
        ];
        $event = new ParsedEvent('tool_call', 'started', toolName: 'Custom', raw: $raw);

        $formatted = $this->parser->format($event);

        expect($formatted)->toBe('🔧 Custom');
    });

    it('returns null for tool_call completed', function (): void {
        $event = new ParsedEvent('tool_call', 'completed', toolName: 'Read');

        expect($this->parser->format($event))->toBeNull();
    });

    it('returns null for tool_call without name', function (): void {
        $event = new ParsedEvent('tool_call', 'started', toolName: null);

        expect($this->parser->format($event))->toBeNull();
    });

    it('returns null for system events', function (): void {
        $event = new ParsedEvent('system', 'init');

        expect($this->parser->format($event))->toBeNull();
    });

    it('returns null for thinking events', function (): void {
        $event = new ParsedEvent('thinking', 'delta');

        expect($this->parser->format($event))->toBeNull();
    });

    it('returns null for user events', function (): void {
        $event = new ParsedEvent('user');

        expect($this->parser->format($event))->toBeNull();
    });
});

describe('parseChunk', function (): void {
    it('parses multiple lines in a chunk', function (): void {
        $chunk = <<<'JSONL'
{"type":"assistant","message":{"content":[{"type":"text","text":"First"}]}}
{"type":"tool_call","subtype":"started","tool_call":{"readToolCall":{}}}
{"type":"assistant","message":{"content":[{"type":"text","text":"Second"}]}}

JSONL;

        $events = $this->parser->parseChunk($chunk);

        expect($events)->toHaveCount(3);
        expect($events[0]->type)->toBe('assistant');
        expect($events[0]->text)->toBe('First');
        expect($events[1]->type)->toBe('tool_call');
        expect($events[2]->text)->toBe('Second');
    });

    it('buffers incomplete lines across chunks', function (): void {
        // First chunk ends mid-JSON
        $chunk1 = '{"type":"assistant","message":{"content":[{"type":"text","text":"He';
        $events1 = $this->parser->parseChunk($chunk1);
        expect($events1)->toHaveCount(0);

        // Second chunk completes it
        $chunk2 = 'llo"}]}}';
        $events2 = $this->parser->parseChunk($chunk2."\n");
        expect($events2)->toHaveCount(1);
        expect($events2[0]->text)->toBe('Hello');
    });

    it('handles empty lines', function (): void {
        $chunk = "\n\n{\"type\":\"system\"}\n\n";

        $events = $this->parser->parseChunk($chunk);

        expect($events)->toHaveCount(1);
        expect($events[0]->type)->toBe('system');
    });
});

describe('tool name extraction', function (): void {
    it('extracts Read from readToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"readToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Read');
    });

    it('extracts Grep from grepToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"grepToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Grep');
    });

    it('extracts Bash from shellToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"shellToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Bash');
    });

    it('extracts Bash from terminalToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"terminalToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Bash');
    });

    it('extracts Edit from editToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"editToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Edit');
    });

    it('extracts Write from writeToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"writeToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Write');
    });

    it('extracts Glob from globToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"globToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Glob');
    });

    it('extracts Task from taskToolCall', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"taskToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Task');
    });

    it('capitalizes unknown tool names', function (): void {
        $json = '{"type":"tool_call","subtype":"started","tool_call":{"customToolCall":{}}}';
        $event = $this->parser->parseLine($json);
        expect($event->toolName)->toBe('Custom');
    });
});
