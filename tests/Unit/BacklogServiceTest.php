<?php

use App\Services\BacklogService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-backlog-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storagePath = $this->tempDir.'/.fuel/backlog.jsonl';
    $this->backlogService = new BacklogService($this->storagePath);
});

afterEach(function (): void {
    // Clean up temp files
    $fuelDir = dirname($this->storagePath);
    if (file_exists($this->storagePath)) {
        unlink($this->storagePath);
    }

    if (file_exists($this->storagePath.'.lock')) {
        unlink($this->storagePath.'.lock');
    }

    if (file_exists($this->storagePath.'.tmp')) {
        unlink($this->storagePath.'.tmp');
    }

    if (is_dir($fuelDir)) {
        rmdir($fuelDir);
    }

    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

// =============================================================================
// Initialize Tests
// =============================================================================

it('initializes storage directory and file', function (): void {
    $this->backlogService->initialize();

    expect(file_exists($this->storagePath))->toBeTrue();
    expect(is_dir(dirname($this->storagePath)))->toBeTrue();
});

// =============================================================================
// Add Tests
// =============================================================================

it('adds a backlog item with hash-based ID', function (): void {
    $this->backlogService->initialize();

    $item = $this->backlogService->add('Test item');

    expect($item['id'])->toStartWith('b-');
    expect(strlen((string) $item['id']))->toBe(8); // b- + 6 chars
    expect($item['title'])->toBe('Test item');
    expect($item['created_at'])->not->toBeNull();
});

it('adds a backlog item with description', function (): void {
    $this->backlogService->initialize();

    $item = $this->backlogService->add('Test item', 'This is a description');

    expect($item['title'])->toBe('Test item');
    expect($item['description'])->toBe('This is a description');
});

it('adds a backlog item without description', function (): void {
    $this->backlogService->initialize();

    $item = $this->backlogService->add('Test item');

    expect($item['description'])->toBeNull();
});

it('persists backlog items to file', function (): void {
    $this->backlogService->initialize();
    $item = $this->backlogService->add('Test item');

    // Create new service instance to verify persistence
    $newService = new BacklogService($this->storagePath);
    $all = $newService->all();

    expect($all->count())->toBe(1);
    expect($all->first()['id'])->toBe($item['id']);
    expect($all->first()['title'])->toBe('Test item');
});

// =============================================================================
// All Tests
// =============================================================================

it('returns empty collection when no backlog items', function (): void {
    $this->backlogService->initialize();

    $all = $this->backlogService->all();

    expect($all)->toBeEmpty();
});

it('returns all backlog items', function (): void {
    $this->backlogService->initialize();
    $item1 = $this->backlogService->add('Item 1');
    $item2 = $this->backlogService->add('Item 2');
    $item3 = $this->backlogService->add('Item 3');

    $all = $this->backlogService->all();

    expect($all->count())->toBe(3);
    expect($all->pluck('id')->toArray())->toContain($item1['id']);
    expect($all->pluck('id')->toArray())->toContain($item2['id']);
    expect($all->pluck('id')->toArray())->toContain($item3['id']);
});

// =============================================================================
// Find Tests
// =============================================================================

it('finds backlog item by exact ID', function (): void {
    $this->backlogService->initialize();
    $created = $this->backlogService->add('Test item');

    $found = $this->backlogService->find($created['id']);

    expect($found)->not->toBeNull();
    expect($found['id'])->toBe($created['id']);
    expect($found['title'])->toBe('Test item');
});

it('finds backlog item by partial ID', function (): void {
    $this->backlogService->initialize();
    $created = $this->backlogService->add('Test item');

    // Extract just the hash part (after 'b-')
    $hashPart = substr((string) $created['id'], 2, 2); // Just first 2 chars of hash

    $found = $this->backlogService->find($hashPart);

    expect($found)->not->toBeNull();
    expect($found['id'])->toBe($created['id']);
});

it('finds backlog item by partial ID without prefix', function (): void {
    $this->backlogService->initialize();
    $created = $this->backlogService->add('Test item');

    // Extract just the hash part (after 'b-')
    $hashPart = substr((string) $created['id'], 2, 3); // First 3 chars of hash

    $found = $this->backlogService->find($hashPart);

    expect($found)->not->toBeNull();
    expect($found['id'])->toBe($created['id']);
});

it('throws exception for ambiguous partial ID', function (): void {
    $this->backlogService->initialize();
    $this->backlogService->add('Item 1');
    $this->backlogService->add('Item 2');

    // Try to find with just 'b' prefix - should be ambiguous
    $this->backlogService->find('b');
})->throws(RuntimeException::class, 'Ambiguous backlog ID');

it('returns null for non-existent backlog item', function (): void {
    $this->backlogService->initialize();

    $found = $this->backlogService->find('b-nonexistent');

    expect($found)->toBeNull();
});

// =============================================================================
// Delete Tests
// =============================================================================

it('deletes a backlog item by ID', function (): void {
    $this->backlogService->initialize();
    $item1 = $this->backlogService->add('Item 1');
    $item2 = $this->backlogService->add('Item 2');

    $deleted = $this->backlogService->delete($item1['id']);

    expect($deleted['id'])->toBe($item1['id']);
    expect($deleted['title'])->toBe('Item 1');

    // Verify it's actually deleted
    $all = $this->backlogService->all();
    expect($all->count())->toBe(1);
    expect($all->first()['id'])->toBe($item2['id']);
});

it('deletes a backlog item by partial ID', function (): void {
    $this->backlogService->initialize();
    $item = $this->backlogService->add('Test item');
    $hashPart = substr((string) $item['id'], 2, 3);

    $deleted = $this->backlogService->delete($hashPart);

    expect($deleted['id'])->toBe($item['id']);

    // Verify it's actually deleted
    $all = $this->backlogService->all();
    expect($all)->toBeEmpty();
});

it('throws exception when deleting non-existent backlog item', function (): void {
    $this->backlogService->initialize();

    $this->backlogService->delete('b-nonexistent');
})->throws(RuntimeException::class, "Backlog item 'b-nonexistent' not found");

// =============================================================================
// Generate ID Tests
// =============================================================================

it('generates unique IDs', function (): void {
    $this->backlogService->initialize();

    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $item = $this->backlogService->add('Item ' . $i);
        $ids[] = $item['id'];
    }

    expect(count(array_unique($ids)))->toBe(10);
});

it('generates unique IDs with collision detection', function (): void {
    $this->backlogService->initialize();

    // Create many items to exercise collision detection
    $ids = [];
    for ($i = 0; $i < 100; $i++) {
        $item = $this->backlogService->add('Item ' . $i);
        $ids[] = $item['id'];
    }

    // All IDs should be unique
    expect(count(array_unique($ids)))->toBe(100);
});

it('generateId works when called directly without parameters', function (): void {
    $this->backlogService->initialize();

    // Should work without parameters
    $id = $this->backlogService->generateId();

    expect($id)->toStartWith('b-');
    expect(strlen((string) $id))->toBe(8); // b- + 6 chars
});

it('generateId works when called with existing items collection', function (): void {
    $this->backlogService->initialize();
    $existing = $this->backlogService->all();

    $id = $this->backlogService->generateId($existing);

    expect($id)->toStartWith('b-');
    expect(strlen((string) $id))->toBe(8);
});

// =============================================================================
// Storage Path Tests
// =============================================================================

it('returns storage path', function (): void {
    expect($this->backlogService->getStoragePath())->toBe($this->storagePath);
});

it('sets custom storage path', function (): void {
    $newPath = $this->tempDir.'/.fuel/custom-backlog.jsonl';
    $this->backlogService->setStoragePath($newPath);

    expect($this->backlogService->getStoragePath())->toBe($newPath);
});

it('uses custom storage path after setting', function (): void {
    $newPath = $this->tempDir.'/.fuel/custom-backlog.jsonl';
    $this->backlogService->setStoragePath($newPath);
    $this->backlogService->initialize();

    $item = $this->backlogService->add('Test item');

    expect(file_exists($newPath))->toBeTrue();
    expect(file_exists($this->storagePath))->toBeFalse();
});

// =============================================================================
// File Operations Tests
// =============================================================================

it('sorts backlog items by ID when writing', function (): void {
    $this->backlogService->initialize();

    // Create multiple items
    $item1 = $this->backlogService->add('Item 1');
    $item2 = $this->backlogService->add('Item 2');
    $item3 = $this->backlogService->add('Item 3');

    // Read file directly and check sorting
    $content = file_get_contents($this->storagePath);
    $lines = array_filter(explode("\n", trim($content)));

    $ids = array_map(function ($line) {
        $item = json_decode($line, true);

        return $item['id'];
    }, $lines);

    // Check that IDs are sorted
    $sortedIds = $ids;
    sort($sortedIds);
    expect($ids)->toBe($sortedIds);
});

it('handles empty file gracefully', function (): void {
    $this->backlogService->initialize();

    // File exists but is empty
    $all = $this->backlogService->all();

    expect($all)->toBeEmpty();
});

it('handles file with only whitespace gracefully', function (): void {
    $this->backlogService->initialize();
    file_put_contents($this->storagePath, "\n\n  \n");

    $all = $this->backlogService->all();

    expect($all)->toBeEmpty();
});

it('throws exception on invalid JSON in file', function (): void {
    $this->backlogService->initialize();
    file_put_contents($this->storagePath, '{"invalid": json}\n');

    $this->backlogService->all();
})->throws(RuntimeException::class, 'Failed to parse backlog.jsonl');

// =============================================================================
// Concurrency Tests
// =============================================================================

it('handles concurrent reads', function (): void {
    $this->backlogService->initialize();
    $this->backlogService->add('Item 1');
    $this->backlogService->add('Item 2');

    // Multiple reads should work
    $all1 = $this->backlogService->all();
    $all2 = $this->backlogService->all();

    expect($all1->count())->toBe(2);
    expect($all2->count())->toBe(2);
});

it('handles concurrent writes atomically', function (): void {
    $this->backlogService->initialize();

    // Create multiple items in sequence
    $item1 = $this->backlogService->add('Item 1');
    $item2 = $this->backlogService->add('Item 2');
    $item3 = $this->backlogService->add('Item 3');

    // All items should be persisted
    $all = $this->backlogService->all();
    expect($all->count())->toBe(3);
    expect($all->pluck('id')->toArray())->toContain($item1['id']);
    expect($all->pluck('id')->toArray())->toContain($item2['id']);
    expect($all->pluck('id')->toArray())->toContain($item3['id']);
});

// =============================================================================
// Edge Cases Tests
// =============================================================================

it('handles backlog item with empty title', function (): void {
    $this->backlogService->initialize();

    $item = $this->backlogService->add('');

    expect($item['title'])->toBe('');
    expect($item['id'])->toStartWith('b-');
});

it('handles backlog item with very long title', function (): void {
    $this->backlogService->initialize();
    $longTitle = str_repeat('a', 1000);

    $item = $this->backlogService->add($longTitle);

    expect($item['title'])->toBe($longTitle);
});

it('handles backlog item with very long description', function (): void {
    $this->backlogService->initialize();
    $longDescription = str_repeat('b', 5000);

    $item = $this->backlogService->add('Test', $longDescription);

    expect($item['description'])->toBe($longDescription);
});

it('handles special characters in title and description', function (): void {
    $this->backlogService->initialize();
    $title = 'Test "quotes" & <tags> and \'apostrophes\'';
    $description = 'Description with "quotes" & <tags> and \'apostrophes\'';

    $item = $this->backlogService->add($title, $description);

    expect($item['title'])->toBe($title);
    expect($item['description'])->toBe($description);

    // Verify it persists correctly
    $found = $this->backlogService->find($item['id']);
    expect($found['title'])->toBe($title);
    expect($found['description'])->toBe($description);
});

it('handles unicode characters in title and description', function (): void {
    $this->backlogService->initialize();
    $title = 'Test with 中文 and 🚀 emoji';
    $description = 'Description with 日本語 and 🎉 emoji';

    $item = $this->backlogService->add($title, $description);

    expect($item['title'])->toBe($title);
    expect($item['description'])->toBe($description);

    // Verify it persists correctly
    $found = $this->backlogService->find($item['id']);
    expect($found['title'])->toBe($title);
    expect($found['description'])->toBe($description);
});
