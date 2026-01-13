<?php

declare(strict_types=1);

use App\Support\BoxRenderer;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->bufferedOutput = new BufferedOutput;
    $this->output = new OutputStyle(new ArrayInput([]), $this->bufferedOutput);
    $this->renderer = new BoxRenderer($this->output);
});

it('renders a basic box with title and content', function (): void {
    $this->renderer->box('TEST', ['Line 1', 'Line 2']);

    $output = $this->bufferedOutput->fetch();

    expect($output)->toContain('╭')
        ->and($output)->toContain('╮')
        ->and($output)->toContain('╰')
        ->and($output)->toContain('╯')
        ->and($output)->toContain('│')
        ->and($output)->toContain('TEST')
        ->and($output)->toContain('Line 1')
        ->and($output)->toContain('Line 2');
});

it('renders a box with emoji', function (): void {
    $this->renderer->box('TASK STATISTICS', ['Total: 47'], '📋');

    $output = $this->bufferedOutput->fetch();

    expect($output)->toContain('📋 TASK STATISTICS')
        ->and($output)->toContain('Total: 47');
});

it('handles empty lines in content', function (): void {
    $this->renderer->box('TEST', ['Line 1', '', 'Line 2']);

    $output = $this->bufferedOutput->fetch();

    // Should have an empty content line (│ with spaces │)
    expect($output)->toContain('Line 1')
        ->and($output)->toContain('Line 2');
});

it('renders a horizontal rule', function (): void {
    $rule = $this->renderer->horizontalRule();

    expect($rule)->toStartWith('├')
        ->and($rule)->toEndWith('┤')
        ->and($rule)->toContain('─');
});

it('renders horizontal rule with custom width', function (): void {
    $rule = $this->renderer->horizontalRule(30);

    expect($rule)->toHaveLength(30);
});

it('colorizes text with hex color', function (): void {
    $colored = $this->renderer->colorize('test', 'FF0000');

    expect($colored)->toContain("\e[38;2;255;0;0m")
        ->and($colored)->toContain('test')
        ->and($colored)->toContain("\e[0m");
});

it('handles hex colors with hash prefix', function (): void {
    $colored = $this->renderer->colorize('test', '#00FF00');

    expect($colored)->toContain("\e[38;2;0;255;0m")
        ->and($colored)->toContain('test');
});

it('renders box with correct width', function (): void {
    $this->renderer->box('TEST', ['Content'], null, 40);

    $output = $this->bufferedOutput->fetch();
    $lines = explode("\n", trim((string) $output));

    // Each line should be 40 characters (including ANSI codes, but the visible part should be 40)
    // We'll check the top border which is simplest
    $topBorder = $lines[0];
    $stripped = preg_replace('/\e\[[0-9;]*m/', '', $topBorder);

    expect(mb_strlen((string) $stripped))->toBe(40);
});

it('handles text with ANSI codes correctly', function (): void {
    $coloredText = $this->renderer->colorize('Red text', 'FF0000');

    $this->renderer->box('COLOR TEST', [$coloredText]);

    $output = $this->bufferedOutput->fetch();

    // Should contain the colored text
    expect($output)->toContain('Red text')
        ->and($output)->toContain("\e[38;2;255;0;0m");
});

it('renders multiple boxes consecutively', function (): void {
    $this->renderer->box('BOX 1', ['Content 1'], '📦');
    $this->renderer->box('BOX 2', ['Content 2'], '🎁');

    $output = $this->bufferedOutput->fetch();

    expect($output)->toContain('📦 BOX 1')
        ->and($output)->toContain('Content 1')
        ->and($output)->toContain('🎁 BOX 2')
        ->and($output)->toContain('Content 2');
});
