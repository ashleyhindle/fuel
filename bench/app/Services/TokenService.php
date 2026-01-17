<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class TokenService
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function generateToken(User $user): string
    {
        $payload = [
            'user_id' => $user->id,
            'exp' => time() + (self::TOKEN_EXPIRY_HOURS * 3600),
            'iat' => time(),
        ];

        return base64_encode(json_encode($payload));
    }

    public function getUserIdFromToken(string $token): ?int
    {
        $payload = $this->decodeToken($token);

        if ($payload === null) {
            return null;
        }

        if ($payload['exp'] < time()) {
            return null;
        }

        return $payload['user_id'];
    }

    public function invalidateToken(string $token): bool
    {
        // In a real implementation, this would add to a blacklist
        return true;
    }

    public function isValid(string $token): bool
    {
        $payload = $this->decodeToken($token);

        if ($payload === null) {
            return false;
        }

        return $payload['exp'] >= time();
    }

    private function decodeToken(string $token): ?array
    {
        $decoded = base64_decode($token, true);

        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }
}
