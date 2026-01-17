<?php

declare(strict_types=1);

namespace App\Models;

class Task
{
    public int $id;

    public string $title;

    public ?string $description;

    public string $status;

    public string $priority;

    public int $user_id;

    public ?string $due_date;

    public ?string $completed_at;

    public ?string $created_at;

    public ?string $updated_at;

    public static function find(int $id): ?self
    {
        return null;
    }

    public static function where(string $column, mixed $value): self
    {
        return new self;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        return $this;
    }

    public function get(): array
    {
        return [];
    }

    public static function create(array $data): self
    {
        $task = new self;
        $task->id = rand(1, 10000);
        $task->title = $data['title'];
        $task->description = $data['description'] ?? null;
        $task->status = $data['status'] ?? 'pending';
        $task->priority = $data['priority'] ?? 'medium';
        $task->user_id = $data['user_id'];
        $task->due_date = $data['due_date'] ?? null;
        $task->created_at = date('Y-m-d H:i:s');

        return $task;
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'user_id' => $this->user_id,
            'due_date' => $this->due_date,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
