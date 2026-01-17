<?php

declare(strict_types=1);

namespace App\Models;

class User
{
    public int $id;

    public string $name;

    public string $email;

    public string $password_hash;

    public ?string $created_at;

    public ?string $updated_at;

    public static function find(int $id): ?self
    {
        // Simulated database lookup
        return null;
    }

    public static function where(string $column, mixed $value): self
    {
        return new self;
    }

    public function first(): ?self
    {
        return null;
    }

    public static function create(array $data): self
    {
        $user = new self;
        $user->id = rand(1, 10000);
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password_hash = $data['password_hash'];
        $user->created_at = date('Y-m-d H:i:s');

        return $user;
    }

    public static function all(): array
    {
        return [];
    }

    public function fill(array $data): self
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    public function save(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
