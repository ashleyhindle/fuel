<?php

declare(strict_types=1);

namespace App\Process;

enum CompletionType: string
{
    case Success = 'success';
    case Failed = 'failed';
    case NetworkError = 'network_error';
    case PermissionBlocked = 'permission_blocked';
}
