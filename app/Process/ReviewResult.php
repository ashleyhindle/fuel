<?php

declare(strict_types=1);

namespace App\Process;

/**
 * Value object representing the result of a task review.
 */
readonly class ReviewResult
{
    /**
     * @param  string  $taskId  The ID of the reviewed task
     * @param  bool  $passed  Whether the review passed
     * @param  array<string>  $issues  List of issues found (e.g., 'Modified files not committed: src/Service.php')
     * @param  string  $completedAt  ISO 8601 timestamp when review completed
     */
    public function __construct(
        public string $taskId,
        public bool $passed,
        public array $issues,
        public string $completedAt,
    ) {}
}
