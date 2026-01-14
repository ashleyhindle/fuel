<?php

declare(strict_types=1);

namespace App\Daemon;

use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Immutable state container for the daemon runner.
 *
 * This value object encapsulates all runtime state that was previously scattered
 * across ConsumeRunner properties. All mutation methods return new instances,
 * ensuring immutability and making state changes explicit.
 */
final readonly class DaemonState
{
    /**
     * @param  bool  $paused  Flag indicating if runner is paused (stops accepting new tasks)
     * @param  bool  $shuttingDown  Flag indicating if runner is shutting down
     * @param  bool  $gracefulShutdown  Whether to perform graceful shutdown (vs force)
     * @param  string  $instanceId  Unique instance identifier (UUID v4)
     * @param  DateTimeImmutable  $startedAt  When the runner was started
     * @param  bool  $taskReviewEnabled  Whether automatic task reviews are enabled
     * @param  array{tasks: Collection|null, ready: Collection|null, failed: Collection|null, timestamp: int}  $taskCache  Cache TTL for task data
     * @param  array<string, int>  $taskRetryAttempts  Track retry attempts per task
     * @param  array<string, string>  $preReviewTaskStatus  Track original task status before review (to handle already-done tasks)
     * @param  array<string, string>  $outputRingBuffers  Ring buffer for last 4KB of output per active process (taskId => output)
     */
    public function __construct(
        public bool $paused = true,
        public bool $shuttingDown = false,
        public bool $gracefulShutdown = true,
        public string $instanceId = '',
        public DateTimeImmutable $startedAt = new DateTimeImmutable,
        public bool $taskReviewEnabled = false,
        public array $taskCache = ['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0],
        public array $taskRetryAttempts = [],
        public array $preReviewTaskStatus = [],
        public array $outputRingBuffers = [],
    ) {}

    /**
     * Create DaemonState from ConsumeRunner instance.
     *
     * This factory method extracts state from the runner using reflection,
     * allowing gradual migration without modifying ConsumeRunner initially.
     *
     * @param  object  $runner  ConsumeRunner instance
     */
    public static function fromRunner(object $runner): self
    {
        // Use reflection to extract private properties from ConsumeRunner
        $reflection = new \ReflectionClass($runner);

        $paused = self::getPrivateProperty($reflection, $runner, 'paused');
        $shuttingDown = self::getPrivateProperty($reflection, $runner, 'shuttingDown');
        $gracefulShutdown = self::getPrivateProperty($reflection, $runner, 'gracefulShutdown');
        $instanceId = self::getPrivateProperty($reflection, $runner, 'instanceId');
        $startedAt = self::getPrivateProperty($reflection, $runner, 'startedAt');
        $taskReviewEnabled = self::getPrivateProperty($reflection, $runner, 'taskReviewEnabled');
        $taskCache = self::getPrivateProperty($reflection, $runner, 'taskCache');
        $taskRetryAttempts = self::getPrivateProperty($reflection, $runner, 'taskRetryAttempts');
        $preReviewTaskStatus = self::getPrivateProperty($reflection, $runner, 'preReviewTaskStatus');
        $outputRingBuffers = self::getPrivateProperty($reflection, $runner, 'outputRingBuffers');

        return new self(
            paused: $paused ?? true,
            shuttingDown: $shuttingDown ?? false,
            gracefulShutdown: $gracefulShutdown ?? true,
            instanceId: $instanceId ?? '',
            startedAt: $startedAt ?? new DateTimeImmutable,
            taskReviewEnabled: $taskReviewEnabled ?? false,
            taskCache: $taskCache ?? ['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0],
            taskRetryAttempts: $taskRetryAttempts ?? [],
            preReviewTaskStatus: $preReviewTaskStatus ?? [],
            outputRingBuffers: $outputRingBuffers ?? [],
        );
    }

    private static function getPrivateProperty(\ReflectionClass $reflection, object $instance, string $propertyName): mixed
    {
        try {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            return $property->getValue($instance);
        } catch (\ReflectionException) {
            return null;
        }
    }

    private function with(array $values): self
    {
        return new self(
            paused: $values['paused'] ?? $this->paused,
            shuttingDown: $values['shuttingDown'] ?? $this->shuttingDown,
            gracefulShutdown: $values['gracefulShutdown'] ?? $this->gracefulShutdown,
            instanceId: $values['instanceId'] ?? $this->instanceId,
            startedAt: $values['startedAt'] ?? $this->startedAt,
            taskReviewEnabled: $values['taskReviewEnabled'] ?? $this->taskReviewEnabled,
            taskCache: $values['taskCache'] ?? $this->taskCache,
            taskRetryAttempts: $values['taskRetryAttempts'] ?? $this->taskRetryAttempts,
            preReviewTaskStatus: $values['preReviewTaskStatus'] ?? $this->preReviewTaskStatus,
            outputRingBuffers: $values['outputRingBuffers'] ?? $this->outputRingBuffers,
        );
    }

    public function withPaused(bool $paused): self
    {
        return $this->with(['paused' => $paused]);
    }

    public function withShuttingDown(bool $shuttingDown): self
    {
        return $this->with(['shuttingDown' => $shuttingDown]);
    }

    public function withGracefulShutdown(bool $gracefulShutdown): self
    {
        return $this->with(['gracefulShutdown' => $gracefulShutdown]);
    }

    public function withTaskReviewEnabled(bool $taskReviewEnabled): self
    {
        return $this->with(['taskReviewEnabled' => $taskReviewEnabled]);
    }

    public function withTaskCache(array $taskCache): self
    {
        return $this->with(['taskCache' => $taskCache]);
    }

    public function withInvalidatedCache(): self
    {
        return $this->withTaskCache(['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0]);
    }

    public function withTaskRetryAttempts(array $taskRetryAttempts): self
    {
        return $this->with(['taskRetryAttempts' => $taskRetryAttempts]);
    }

    public function withIncrementedRetry(string $taskId): self
    {
        $attempts = $this->taskRetryAttempts;
        $attempts[$taskId] = ($attempts[$taskId] ?? 0) + 1;
        return $this->withTaskRetryAttempts($attempts);
    }

    public function withClearedRetry(string $taskId): self
    {
        $attempts = $this->taskRetryAttempts;
        unset($attempts[$taskId]);
        return $this->withTaskRetryAttempts($attempts);
    }

    public function withPreReviewTaskStatus(array $preReviewTaskStatus): self
    {
        return $this->with(['preReviewTaskStatus' => $preReviewTaskStatus]);
    }

    public function withPreReviewStatus(string $taskId, string $status): self
    {
        $statuses = $this->preReviewTaskStatus;
        $statuses[$taskId] = $status;
        return $this->withPreReviewTaskStatus($statuses);
    }

    public function withOutputRingBuffers(array $outputRingBuffers): self
    {
        return $this->with(['outputRingBuffers' => $outputRingBuffers]);
    }

    public function withOutputChunk(string $taskId, string $chunk, int $maxSize = 4096): self
    {
        $buffers = $this->outputRingBuffers;
        if (! isset($buffers[$taskId])) {
            $buffers[$taskId] = '';
        }
        $buffers[$taskId] .= $chunk;
        if (strlen($buffers[$taskId]) > $maxSize) {
            $buffers[$taskId] = substr($buffers[$taskId], -$maxSize);
        }
        return $this->withOutputRingBuffers($buffers);
    }

    public function withClearedOutputBuffer(string $taskId): self
    {
        $buffers = $this->outputRingBuffers;
        unset($buffers[$taskId]);
        return $this->withOutputRingBuffers($buffers);
    }

    public function getRetryAttempts(string $taskId): int { return $this->taskRetryAttempts[$taskId] ?? 0; }
    public function getPreReviewStatus(string $taskId): ?string { return $this->preReviewTaskStatus[$taskId] ?? null; }
    public function getOutputBuffer(string $taskId): string { return $this->outputRingBuffers[$taskId] ?? ''; }
}
