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
     * @param  array<string>  $issues  List of issue codes found (e.g., 'uncommitted_changes', 'tests_failing')
     * @param  array<string>  $followUpTaskIds  Task IDs created by reviewer for follow-up work
     * @param  string  $completedAt  ISO 8601 timestamp when review completed
     */
    public function __construct(
        public string $taskId,
        public bool $passed,
        public array $issues,
        public array $followUpTaskIds,
        public string $completedAt,
    ) {}
}
