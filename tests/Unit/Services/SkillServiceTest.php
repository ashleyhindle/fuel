<?php

use App\Services\SkillService;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-skill-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->skillService = new SkillService;
});

afterEach(function (): void {
    // Clean up temp directory
    if (is_dir($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('returns skills path in development mode', function (): void {
    $path = $this->skillService->getSkillsPath();

    // In dev mode, should point to resources/skills
    expect($path)->toEndWith('/resources/skills');
    expect(is_dir($path))->toBeTrue();
});

it('lists available skills', function (): void {
    $skills = $this->skillService->getAvailableSkills();

    expect($skills)->toBeArray();
    expect($skills)->toContain('consume-the-fuel');
    expect($skills)->toContain('make-plan-actionable');
});

it('installs skills to claude skills directory', function (): void {
    $installed = $this->skillService->installSkills($this->tempDir);

    expect($installed)->toHaveKey('consume-the-fuel');
    expect($installed)->toHaveKey('make-plan-actionable');

    // Check .claude/skills
    $claudeSkillPath = $this->tempDir.'/.claude/skills/fuel-consume-the-fuel/SKILL.md';
    expect(File::exists($claudeSkillPath))->toBeTrue();

    $content = File::get($claudeSkillPath);
    expect($content)->toContain('Consume the Fuel');
});

it('installs skills to codex skills directory', function (): void {
    $installed = $this->skillService->installSkills($this->tempDir);

    // Check .codex/skills
    $codexSkillPath = $this->tempDir.'/.codex/skills/fuel-consume-the-fuel/SKILL.md';
    expect(File::exists($codexSkillPath))->toBeTrue();

    $content = File::get($codexSkillPath);
    expect($content)->toContain('Consume the Fuel');
});

it('prefixes skill directories with fuel-', function (): void {
    $this->skillService->installSkills($this->tempDir);

    // Verify prefix is applied
    expect(is_dir($this->tempDir.'/.claude/skills/fuel-consume-the-fuel'))->toBeTrue();
    expect(is_dir($this->tempDir.'/.claude/skills/fuel-make-plan-actionable'))->toBeTrue();

    // Original name without prefix should not exist
    expect(is_dir($this->tempDir.'/.claude/skills/consume-the-fuel'))->toBeFalse();
});

it('creates target directories if they do not exist', function (): void {
    // Verify directories don't exist before
    expect(is_dir($this->tempDir.'/.claude/skills'))->toBeFalse();
    expect(is_dir($this->tempDir.'/.codex/skills'))->toBeFalse();

    $this->skillService->installSkills($this->tempDir);

    // Verify directories were created
    expect(is_dir($this->tempDir.'/.claude/skills'))->toBeTrue();
    expect(is_dir($this->tempDir.'/.codex/skills'))->toBeTrue();
});

it('installs single skill', function (): void {
    $paths = $this->skillService->installSkill('consume-the-fuel', $this->tempDir);

    expect($paths)->toHaveCount(2);
    expect($paths[0])->toContain('.claude/skills/fuel-consume-the-fuel/SKILL.md');
    expect($paths[1])->toContain('.codex/skills/fuel-consume-the-fuel/SKILL.md');
});

it('returns empty array for non-existent skill', function (): void {
    $paths = $this->skillService->installSkill('non-existent-skill', $this->tempDir);

    expect($paths)->toBe([]);
});

it('returns installed paths map from installSkills', function (): void {
    $installed = $this->skillService->installSkills($this->tempDir);

    expect($installed)->toBeArray();

    foreach ($installed as $skillName => $paths) {
        expect($paths)->toBeArray();
        expect($paths)->toHaveCount(2);

        foreach ($paths as $path) {
            expect(File::exists($path))->toBeTrue();
        }
    }
});

it('overwrites existing skill files', function (): void {
    // Install once
    $this->skillService->installSkills($this->tempDir);

    $skillPath = $this->tempDir.'/.claude/skills/fuel-consume-the-fuel/SKILL.md';
    $originalContent = File::get($skillPath);

    // Modify the file
    File::put($skillPath, 'Modified content');
    expect(File::get($skillPath))->toBe('Modified content');

    // Install again - should overwrite
    $this->skillService->installSkills($this->tempDir);

    expect(File::get($skillPath))->toBe($originalContent);
});

it('make-plan-actionable skill contains expected content', function (): void {
    $this->skillService->installSkills($this->tempDir);

    $content = File::get($this->tempDir.'/.claude/skills/fuel-make-plan-actionable/SKILL.md');

    expect($content)->toContain('Make Plan Actionable');
    expect($content)->toContain('epic:add');
    expect($content)->toContain('--complexity');
    expect($content)->toContain('--blocked-by');
});

it('consume-the-fuel skill contains expected content', function (): void {
    $this->skillService->installSkills($this->tempDir);

    $content = File::get($this->tempDir.'/.claude/skills/fuel-consume-the-fuel/SKILL.md');

    expect($content)->toContain('Consume the Fuel');
    expect($content)->toContain('fuel ready');
    expect($content)->toContain('fuel start');
    expect($content)->toContain('fuel done');
});
