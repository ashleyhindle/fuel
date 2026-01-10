<?php

declare(strict_types=1);

namespace App\Process;

enum SpawnFailureReason: string
{
    case None = 'none';
    case AtCapacity = 'at_capacity';
    case AgentNotFound = 'agent_not_found';
    case SpawnFailed = 'spawn_failed';
    case ConfigError = 'config_error';
}
