<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuthService;

class AuthMiddleware
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function handle(array $request, callable $next): mixed
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return [
                'success' => false,
                'error' => 'Authentication required',
                'code' => 401,
            ];
        }

        $user = $this->authService->validateToken($token);

        if ($user === null) {
            return [
                'success' => false,
                'error' => 'Invalid or expired token',
                'code' => 401,
            ];
        }

        $request['user'] = $user;

        return $next($request);
    }

    private function extractToken(array $request): ?string
    {
        $header = $request['headers']['authorization'] ?? '';

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request['token'] ?? null;
    }
}
