<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TaskService;

class TaskController
{
    public function __construct(
        private readonly TaskService $taskService
    ) {}

    public function index(User $user): array
    {
        $tasks = $this->taskService->getByUser($user);

        return [
            'success' => true,
            'tasks' => $tasks,
        ];
    }

    public function store(array $request, User $user): array
    {
        $title = $request['title'] ?? '';

        if (empty($title)) {
            return [
                'success' => false,
                'error' => 'Title is required',
            ];
        }

        $task = $this->taskService->create($request, $user);

        return [
            'success' => true,
            'task' => $task->toArray(),
        ];
    }

    public function show(int $id): array
    {
        $task = $this->taskService->findById($id);

        if ($task === null) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        return [
            'success' => true,
            'task' => $task->toArray(),
        ];
    }

    public function update(int $id, array $request): array
    {
        $task = $this->taskService->update($id, $request);

        if ($task === null) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        return [
            'success' => true,
            'task' => $task->toArray(),
        ];
    }

    public function complete(int $id): array
    {
        $task = $this->taskService->complete($id);

        if ($task === null) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        return [
            'success' => true,
            'task' => $task->toArray(),
        ];
    }

    public function destroy(int $id): array
    {
        $deleted = $this->taskService->delete($id);

        if (! $deleted) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        return [
            'success' => true,
            'message' => 'Task deleted',
        ];
    }
}
