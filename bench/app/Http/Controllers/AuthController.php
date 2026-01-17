<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AuthService;

class AuthController
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function login(array $request): array
    {
        $email = $request['email'] ?? '';
        $password = $request['password'] ?? '';

        if (empty($email) || empty($password)) {
            return [
                'success' => false,
                'error' => 'Email and password are required',
            ];
        }

        $token = $this->authService->login($email, $password);

        if ($token === null) {
            return [
                'success' => false,
                'error' => 'Invalid credentials',
            ];
        }

        return [
            'success' => true,
            'token' => $token,
        ];
    }

    public function logout(array $request): array
    {
        $token = $request['token'] ?? '';

        if (empty($token)) {
            return [
                'success' => false,
                'error' => 'Token is required',
            ];
        }

        $this->authService->logout($token);

        return [
            'success' => true,
            'message' => 'Logged out successfully',
        ];
    }

    public function me(array $request): array
    {
        $token = $request['token'] ?? '';

        if (empty($token)) {
            return [
                'success' => false,
                'error' => 'Token is required',
            ];
        }

        $user = $this->authService->validateToken($token);

        if ($user === null) {
            return [
                'success' => false,
                'error' => 'Invalid or expired token',
            ];
        }

        return [
            'success' => true,
            'user' => $user->toArray(),
        ];
    }
}
