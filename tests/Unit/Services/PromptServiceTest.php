<?php

declare(strict_types=1);

use App\Services\FuelContext;
use App\Services\PromptService;

beforeEach(function (): void {
    $this->context = $this->app->make(FuelContext::class);
    $this->promptService = $this->app->make(PromptService::class);
});

describe('PromptService loadTemplate', function (): void {
    it('loads bundled template when no user template exists', function (): void {
        $template = $this->promptService->loadTemplate('work');

        expect($template)->toContain('<fuel-prompt version="1" />');
        expect($template)->toContain('YOUR ASSIGNMENT');
    });

    it('loads user template when it exists', function (): void {
        $promptsDir = $this->context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        $userTemplate = "<fuel-prompt version=\"1\" />\n\nCustom template for {{task.id}}";
        file_put_contents($promptsDir.'/work.md', $userTemplate);

        $template = $this->promptService->loadTemplate('work');

        expect($template)->toBe($userTemplate);

        // Cleanup
        unlink($promptsDir.'/work.md');
    });
});

describe('PromptService render', function (): void {
    it('replaces simple variables', function (): void {
        $template = 'Hello {{name}}!';
        $result = $this->promptService->render($template, ['name' => 'World']);

        expect($result)->toBe('Hello World!');
    });

    it('replaces nested variables', function (): void {
        $template = 'Task: {{task.id}}, Title: {{task.title}}';
        $result = $this->promptService->render($template, [
            'task' => [
                'id' => 'f-abc123',
                'title' => 'Test Task',
            ],
        ]);

        expect($result)->toBe('Task: f-abc123, Title: Test Task');
    });

    it('replaces missing variables with empty string', function (): void {
        $template = 'Name: {{name}}, Age: {{age}}';
        $result = $this->promptService->render($template, ['name' => 'John']);

        expect($result)->toBe('Name: John, Age: ');
    });

    it('handles deeply nested variables', function (): void {
        $template = 'Value: {{context.epic.plan_path}}';
        $result = $this->promptService->render($template, [
            'context' => [
                'epic' => [
                    'plan_path' => '.fuel/plans/my-epic.md',
                ],
            ],
        ]);

        expect($result)->toBe('Value: .fuel/plans/my-epic.md');
    });
});

describe('PromptService parseVersion', function (): void {
    it('parses version from valid tag', function (): void {
        $content = "<fuel-prompt version=\"1\" />\n\nSome content here";
        $version = $this->promptService->parseVersion($content);

        expect($version)->toBe(1);
    });

    it('parses higher version numbers', function (): void {
        $content = "<fuel-prompt version=\"42\" />\n\nContent";
        $version = $this->promptService->parseVersion($content);

        expect($version)->toBe(42);
    });

    it('returns 0 for content without version tag', function (): void {
        $content = 'Some content without version tag';
        $version = $this->promptService->parseVersion($content);

        expect($version)->toBe(0);
    });

    it('returns 0 for malformed version tag', function (): void {
        $content = "<fuel-prompt ver=\"1\" />\n\nContent";
        $version = $this->promptService->parseVersion($content);

        expect($version)->toBe(0);
    });
});

describe('PromptService checkVersions', function (): void {
    it('returns empty array when no user prompts exist', function (): void {
        $outdated = $this->promptService->checkVersions();

        expect($outdated)->toBe([]);
    });

    it('detects outdated user prompt', function (): void {
        $promptsDir = $this->context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Write user prompt with version 0 (no tag)
        file_put_contents($promptsDir.'/work.md', 'Custom prompt without version');

        $outdated = $this->promptService->checkVersions();

        expect($outdated)->toHaveKey('work');
        expect($outdated['work']['user'])->toBe(0);
        expect($outdated['work']['current'])->toBe(PromptService::CURRENT_VERSION);

        // Cleanup
        unlink($promptsDir.'/work.md');
    });

    it('does not flag current version as outdated', function (): void {
        $promptsDir = $this->context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Write user prompt with current version
        $content = sprintf("<fuel-prompt version=\"%d\" />\n\nCustom prompt", PromptService::CURRENT_VERSION);
        file_put_contents($promptsDir.'/work.md', $content);

        $outdated = $this->promptService->checkVersions();

        expect($outdated)->not->toHaveKey('work');

        // Cleanup
        unlink($promptsDir.'/work.md');
    });
});

describe('PromptService writeDefaultPrompts', function (): void {
    it('writes default prompts to prompts directory', function (): void {
        $promptsDir = $this->context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Ensure no prompts exist
        foreach (['work.md', 'review.md', 'verify.md'] as $file) {
            $path = $promptsDir.'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $this->promptService->writeDefaultPrompts();

        expect(file_exists($promptsDir.'/work.md'))->toBeTrue();
        expect(file_exists($promptsDir.'/review.md'))->toBeTrue();
        expect(file_exists($promptsDir.'/verify.md'))->toBeTrue();

        // Verify content has version tag
        $workContent = file_get_contents($promptsDir.'/work.md');
        expect($workContent)->toContain('<fuel-prompt version="1" />');

        // Cleanup
        foreach (['work.md', 'review.md', 'verify.md'] as $file) {
            unlink($promptsDir.'/'.$file);
        }
    });

    it('does not overwrite existing prompts', function (): void {
        $promptsDir = $this->context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Write custom prompt
        $customContent = 'My custom prompt';
        file_put_contents($promptsDir.'/work.md', $customContent);

        $this->promptService->writeDefaultPrompts();

        // Custom prompt should not be overwritten
        expect(file_get_contents($promptsDir.'/work.md'))->toBe($customContent);

        // Cleanup
        unlink($promptsDir.'/work.md');
        foreach (['review.md', 'verify.md'] as $file) {
            $path = $promptsDir.'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    });
});

describe('PromptService writeUpgradeFiles', function (): void {
    it('writes .new files for outdated prompts', function (): void {
        $promptsDir = $this->context->getPromptsPath();
        if (! is_dir($promptsDir)) {
            mkdir($promptsDir, 0755, true);
        }

        // Write outdated user prompt (version 0)
        file_put_contents($promptsDir.'/work.md', 'Outdated prompt without version');

        $written = $this->promptService->writeUpgradeFiles();

        expect($written)->toContain('work');
        expect(file_exists($promptsDir.'/work.md.new'))->toBeTrue();

        $newContent = file_get_contents($promptsDir.'/work.md.new');
        expect($newContent)->toContain('<fuel-prompt version="1" />');

        // Cleanup
        unlink($promptsDir.'/work.md');
        unlink($promptsDir.'/work.md.new');
    });
});

describe('PromptService getPromptNames', function (): void {
    it('returns all prompt names', function (): void {
        $names = $this->promptService->getPromptNames();

        expect($names)->toContain('work');
        expect($names)->toContain('review');
        expect($names)->toContain('verify');
    });
});
