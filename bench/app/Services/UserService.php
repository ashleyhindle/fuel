<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class UserService
{
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_ARGON2ID),
        ]);
    }

    public function update(int $id, array $data): ?User
    {
        $user = $this->findById($id);

        if ($user === null) {
            return null;
        }

        $user->fill($data);
        $user->save();

        return $user;
    }

    public function delete(int $id): bool
    {
        $user = $this->findById($id);

        if ($user === null) {
            return false;
        }

        return $user->delete();
    }

    public function getAll(): array
    {
        return User::all()->toArray();
    }
}
