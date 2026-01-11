<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('feature');

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// =============================================================================
// guidelines Command Tests
// =============================================================================

describe('guidelines command', function (): void {
    beforeEach(function (): void {
        // Clean up AGENTS.md in tempDir before each test
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        if (file_exists($agentsMdPath)) {
            unlink($agentsMdPath);
        }
    });

    it('outputs guidelines content when --add flag is not used', function (): void {
        Artisan::call('guidelines');
        $output = Artisan::output();

        expect($output)->toContain('Fuel Task Management');
        expect($output)->toContain('Quick Reference');
    });

    it('creates AGENTS.md when it does not exist with --add flag', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';

        expect(file_exists($agentsMdPath))->toBeFalse();

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(file_exists($agentsMdPath))->toBeTrue();
        expect($output)->toContain('Created AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('# Agent Instructions');
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });

    it('replaces existing <fuel> section in AGENTS.md with --add flag', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        $oldContent = "# Agent Instructions\n\n<fuel>\nOld content here\n</fuel>\n\nSome other content";
        file_put_contents($agentsMdPath, $oldContent);

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Updated AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
        expect($content)->toContain('Some other content');
        expect($content)->not->toContain('Old content here');
        // Should contain content from agent-instructions.md
        expect($content)->toContain('Fuel Task Management');
    });

    it('appends <fuel> section when AGENTS.md exists but has no fuel section with --add flag', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        $existingContent = "# Agent Instructions\n\nSome existing content here";
        file_put_contents($agentsMdPath, $existingContent);

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Updated AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('Some existing content here');
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
        // Should have double newline before fuel section
        expect($content)->toContain("content here\n\n<fuel>");
    });

    it('uses custom --cwd option with --add flag', function (): void {
        $customDir = sys_get_temp_dir().'/fuel-test-custom-'.uniqid();
        mkdir($customDir, 0755, true);
        $agentsMdPath = $customDir.'/AGENTS.md';

        try {
            Artisan::call('guidelines', ['--add' => true, '--cwd' => $customDir]);

            expect(file_exists($agentsMdPath))->toBeTrue();
            $content = file_get_contents($agentsMdPath);
            expect($content)->toContain('<fuel>');
            expect($content)->toContain('</fuel>');
        } finally {
            // Cleanup
            if (file_exists($agentsMdPath)) {
                unlink($agentsMdPath);
            }

            if (is_dir($customDir)) {
                rmdir($customDir);
            }
        }
    });

});
