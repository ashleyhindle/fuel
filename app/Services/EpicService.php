<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;

class EpicService
{
    private const VALID_STATUSES = ['planning', 'active', 'completed', 'cancelled'];

    private DatabaseService $db;

    private TaskService $taskService;

    public function __construct(?DatabaseService $db = null, ?TaskService $taskService = null)
    {
        $this->db = $db ?? new DatabaseService;
        $this->taskService = $taskService ?? new TaskService;
    }

    public function createEpic(string $title, ?string $description = null): array
    {
        $this->db->initialize();

        $id = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        $this->db->query(
            'INSERT INTO epics (id, title, description, status, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $title, $description, 'planning', $now]
        );

        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'status' => 'planning',
            'created_at' => $now,
            'reviewed_at' => null,
            'approved_at' => null,
            'approved_by' => null,
        ];
    }

    public function getEpic(string $id): ?array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            return null;
        }

        return $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
    }

    public function getAllEpics(): array
    {
        $this->db->initialize();

        return $this->db->fetchAll('SELECT * FROM epics ORDER BY created_at DESC');
    }

    public function updateEpic(string $id, array $data): array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $updates = [];
        $params = [];

        if (isset($data['title'])) {
            $updates[] = 'title = ?';
            $params[] = $data['title'];
            $epic['title'] = $data['title'];
        }

        if (array_key_exists('description', $data)) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
            $epic['description'] = $data['description'];
        }

        if (isset($data['status'])) {
            $this->validateStatus($data['status']);
            $updates[] = 'status = ?';
            $params[] = $data['status'];
            $epic['status'] = $data['status'];
        }

        if ($updates !== []) {
            $params[] = $resolvedId;
            $this->db->query(
                'UPDATE epics SET '.implode(', ', $updates).' WHERE id = ?',
                $params
            );
        }

        return $epic;
    }

    public function deleteEpic(string $id): array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $this->db->query('DELETE FROM epics WHERE id = ?', [$resolvedId]);

        return $epic;
    }

    public function getTasksForEpic(string $epicId): array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        return $this->taskService->all()
            ->filter(fn (array $task): bool => ($task['epic_id'] ?? null) === $resolvedId)
            ->values()
            ->toArray();
    }

    private function generateId(): string
    {
        return 'e-'.bin2hex(random_bytes(3));
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new RuntimeException(
                sprintf("Invalid status '%s'. Must be one of: ", $status).implode(', ', self::VALID_STATUSES)
            );
        }
    }

    private function resolveId(string $id): ?string
    {
        if (str_starts_with($id, 'e-') && strlen($id) === 8) {
            $epic = $this->db->fetchOne('SELECT id FROM epics WHERE id = ?', [$id]);

            return $epic !== null ? $id : null;
        }

        $epics = $this->db->fetchAll(
            'SELECT id FROM epics WHERE id LIKE ? OR id LIKE ?',
            [$id.'%', 'e-'.$id.'%']
        );

        if (count($epics) === 1) {
            return $epics[0]['id'];
        }

        if (count($epics) > 1) {
            $ids = array_column($epics, 'id');
            throw new RuntimeException(
                sprintf("Ambiguous epic ID '%s'. Matches: ", $id).implode(', ', $ids)
            );
        }

        return null;
    }
}
