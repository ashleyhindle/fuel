<?php

declare(strict_types=1);

namespace App\Services;

use App\Process\ParsedEvent;

/**
 * Parses streaming JSONL output from agents and formats for CLI display.
 */
class OutputParser
{
    /** Buffer for incomplete JSON lines */
    private string $buffer = '';

    /**
     * Parse a chunk of output that may contain multiple lines or partial lines.
     *
     * @return array<ParsedEvent>
     */
    public function parseChunk(string $chunk): array
    {
        $events = [];
        $this->buffer .= $chunk;

        // Split by newlines, keeping the last potentially incomplete line in buffer
        $lines = explode("\n", $this->buffer);
        $this->buffer = array_pop($lines) ?? '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = $this->parseLine($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Parse a single JSON line into a ParsedEvent.
     */
    public function parseLine(string $jsonLine): ?ParsedEvent
    {
        $jsonLine = trim($jsonLine);
        if ($jsonLine === '') {
            return null;
        }

        $data = json_decode($jsonLine, true);
        if (! is_array($data) || ! isset($data['type'])) {
            return null;
        }

        $type = $data['type'];
        $subtype = $data['subtype'] ?? null;

        return match ($type) {
            'assistant' => $this->parseAssistant($data),
            'tool_call' => $this->parseToolCall($data),
            default => new ParsedEvent($type, $subtype, raw: $data),
        };
    }

    /**
     * Format a ParsedEvent for CLI display.
     * Returns null if the event should be skipped.
     */
    public function format(ParsedEvent $event): ?string
    {
        return match ($event->type) {
            'assistant' => $this->formatAssistant($event),
            'tool_call' => $this->formatToolCall($event),
            default => null, // Skip system, thinking, user, result
        };
    }

    private function parseAssistant(array $data): ParsedEvent
    {
        $text = null;
        $content = $data['message']['content'] ?? [];

        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'text' && isset($item['text'])) {
                $text = $item['text'];
                break;
            }
        }

        return new ParsedEvent('assistant', text: $text, raw: $data);
    }

    private function parseToolCall(array $data): ParsedEvent
    {
        $subtype = $data['subtype'] ?? null;
        $toolName = null;

        // Extract tool name from tool_call object
        // Structure: {"tool_call": {"readToolCall": {...}}}
        $toolCall = $data['tool_call'] ?? [];
        foreach ($toolCall as $key => $value) {
            // The key is like "readToolCall", "grepToolCall", "shellToolCall"
            // Extract the base name
            $toolName = $this->extractToolName($key);
            break;
        }

        return new ParsedEvent('tool_call', $subtype, toolName: $toolName, raw: $data);
    }

    private function extractToolName(string $key): string
    {
        // Remove "ToolCall" suffix and capitalize
        $name = preg_replace('/ToolCall$/i', '', $key);

        // Handle common tool names
        return match (strtolower($name ?? '')) {
            'read' => 'Read',
            'grep' => 'Grep',
            'glob' => 'Glob',
            'edit' => 'Edit',
            'write' => 'Write',
            'shell', 'bash', 'terminal' => 'Bash',
            'webfetch' => 'WebFetch',
            'websearch' => 'WebSearch',
            'task' => 'Task',
            'todowrite' => 'Todo',
            default => ucfirst($name ?? $key),
        };
    }

    private function formatAssistant(ParsedEvent $event): ?string
    {
        if ($event->text === null || trim($event->text) === '') {
            return null;
        }

        return trim($event->text);
    }

    private function formatToolCall(ParsedEvent $event): ?string
    {
        // Only show 'started' events
        if ($event->subtype !== 'started') {
            return null;
        }

        if ($event->toolName === null) {
            return null;
        }

        return sprintf('🔧 %s', $event->toolName);
    }
}
