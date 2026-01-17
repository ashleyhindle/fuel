<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class AuthService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly TokenService $tokenService
    ) {}

    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->userService->findByEmail($email);

        if ($user === null) {
            return null;
        }

        if (! $this->verifyPassword($password, $user->password_hash)) {
            return null;
        }

        return $user;
    }

    public function login(string $email, string $password): ?string
    {
        $user = $this->authenticate($email, $password);

        if ($user === null) {
            return null;
        }

        return $this->tokenService->generateToken($user);
    }

    public function logout(string $token): bool
    {
        return $this->tokenService->invalidateToken($token);
    }

    public function validateToken(string $token): ?User
    {
        $userId = $this->tokenService->getUserIdFromToken($token);

        if ($userId === null) {
            return null;
        }

        return $this->userService->findById($userId);
    }

    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
}
