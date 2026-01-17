<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskService
{
    public function create(array $data, User $user): Task
    {
        return Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'pending',
            'priority' => $data['priority'] ?? 'medium',
            'user_id' => $user->id,
            'due_date' => $data['due_date'] ?? null,
        ]);
    }

    public function findById(int $id): ?Task
    {
        return Task::find($id);
    }

    public function getByUser(User $user): array
    {
        return Task::where('user_id', $user->id)
            ->orderBy('priority')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function update(int $id, array $data): ?Task
    {
        $task = $this->findById($id);

        if ($task === null) {
            return null;
        }

        $task->fill($data);
        $task->save();

        return $task;
    }

    public function complete(int $id): ?Task
    {
        return $this->update($id, ['status' => 'completed', 'completed_at' => now()]);
    }

    public function delete(int $id): bool
    {
        $task = $this->findById($id);

        if ($task === null) {
            return false;
        }

        return $task->delete();
    }

    public function getPendingTasks(User $user): array
    {
        return Task::where('user_id', $user->id)
            ->where('status', 'pending')
            ->orderBy('priority')
            ->get()
            ->toArray();
    }

    public function getOverdueTasks(User $user): array
    {
        return Task::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('due_date', '<', now())
            ->get()
            ->toArray();
    }
}
