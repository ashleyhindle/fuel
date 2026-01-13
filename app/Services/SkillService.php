<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Phar;

class SkillService
{
    /**
     * Target directories where skills should be installed.
     * These are relative to the project root.
     */
    private const TARGET_DIRECTORIES = [
        '.claude/skills',
        '.codex/skills',
    ];

    /**
     * Prefix for skill directories to avoid collisions with user skills.
     */
    private const SKILL_PREFIX = 'fuel-';

    /**
     * Get the path to bundled skills.
     * Handles both phar and development environments.
     */
    public function getSkillsPath(): string
    {
        $pharPath = Phar::running(false);

        if ($pharPath !== '') {
            // Running from phar - skills are bundled inside
            return 'phar://'.$pharPath.'/resources/skills';
        }

        // Development mode - skills are in the project root
        return dirname(__DIR__, 2).'/resources/skills';
    }

    /**
     * Get list of available skill names.
     *
     * @return array<string>
     */
    public function getAvailableSkills(): array
    {
        $skillsPath = $this->getSkillsPath();

        if (! File::isDirectory($skillsPath)) {
            return [];
        }

        $skills = [];
        foreach (File::directories($skillsPath) as $dir) {
            $skillName = basename((string) $dir);
            // Only include directories that have a SKILL.md file
            if (File::exists($dir.'/SKILL.md')) {
                $skills[] = $skillName;
            }
        }

        return $skills;
    }

    /**
     * Install all skills to the project directory.
     *
     * @param  string  $projectPath  The project root directory
     * @return array<string, array<string>> Map of skill name to installed paths
     */
    public function installSkills(string $projectPath): array
    {
        $skills = $this->getAvailableSkills();
        $installed = [];

        foreach ($skills as $skill) {
            $paths = $this->installSkill($skill, $projectPath);
            if ($paths !== []) {
                $installed[$skill] = $paths;
            }
        }

        return $installed;
    }

    /**
     * Install a single skill to all target directories.
     *
     * @param  string  $skillName  The skill name (directory name in resources/skills)
     * @param  string  $projectPath  The project root directory
     * @return array<string> List of paths where the skill was installed
     */
    public function installSkill(string $skillName, string $projectPath): array
    {
        $sourcePath = $this->getSkillsPath().'/'.$skillName;
        $sourceFile = $sourcePath.'/SKILL.md';

        if (! File::exists($sourceFile)) {
            return [];
        }

        $content = File::get($sourceFile);
        $installedPaths = [];

        foreach (self::TARGET_DIRECTORIES as $targetDir) {
            $targetSkillDir = $projectPath.'/'.$targetDir.'/'.self::SKILL_PREFIX.$skillName;
            $targetFile = $targetSkillDir.'/SKILL.md';

            // Create directory if it doesn't exist
            if (! File::isDirectory($targetSkillDir)) {
                File::makeDirectory($targetSkillDir, 0755, true);
            }

            // Write the skill file
            File::put($targetFile, $content);
            $installedPaths[] = $targetFile;
        }

        return $installedPaths;
    }
}
