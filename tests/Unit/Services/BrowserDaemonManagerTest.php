<?php

declare(strict_types=1);

use App\Services\BrowserDaemonManager;

beforeEach(function (): void {
    // Skip browser tests on CI - no display/browser available
    if (getenv('CI') === 'true') {
        $this->markTestSkipped('Browser daemon tests skipped on CI (no display available)');
    }

    // Clean up any existing daemon before each test
    $manager = BrowserDaemonManager::getInstance();
    $manager->stop();
});

afterEach(function (): void {
    // Clean up after each test (only if not on CI)
    if (getenv('CI') !== 'true') {
        $manager = BrowserDaemonManager::getInstance();
        $manager->stop();
    }
});

it('returns the same singleton instance', function (): void {
    $instance1 = BrowserDaemonManager::getInstance();
    $instance2 = BrowserDaemonManager::getInstance();

    expect($instance1)->toBe($instance2);
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('can start the daemon', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    expect($manager->isRunning())->toBeFalse();

    $manager->start();

    expect($manager->isRunning())->toBeTrue();
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('can stop the daemon', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    $manager->start();

    expect($manager->isRunning())->toBeTrue();

    $manager->stop();
    expect($manager->isRunning())->toBeFalse();
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('does not error when stopping already stopped daemon', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    expect($manager->isRunning())->toBeFalse();

    // Should not throw
    $manager->stop();

    expect($manager->isRunning())->toBeFalse();
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('does not error when starting already started daemon', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    $manager->start();

    expect($manager->isRunning())->toBeTrue();

    // Should not throw or restart
    $manager->start();

    expect($manager->isRunning())->toBeTrue();
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('can get daemon status when running', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    $manager->start();

    $status = $manager->status();

    expect($status)->toHaveKeys(['browserLaunched', 'contexts', 'pages', 'daemonRunning']);
    expect($status['daemonRunning'])->toBeTrue();
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('returns appropriate status when daemon is not running', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    $status = $manager->status();

    expect($status)->toHaveKeys(['browserLaunched', 'contexts', 'pages', 'daemonRunning']);
    expect($status['daemonRunning'])->toBeFalse();
    expect($status['browserLaunched'])->toBeFalse();
    expect($status['contexts'])->toBeArray()->toBeEmpty();
    expect($status['pages'])->toBeArray()->toBeEmpty();
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('can create and close contexts', function (): void {
    $manager = BrowserDaemonManager::getInstance();
    $manager->start();

    // Create context
    $result = $manager->createContext('test-context', [
        'viewport' => ['width' => 1920, 'height' => 1080],
    ]);

    expect($result)->toHaveKey('contextId');
    expect($result['contextId'])->toBe('test-context');

    // Verify context in status
    $status = $manager->status();
    expect($status['contexts'])->toContain('test-context');

    // Close context
    $closeResult = $manager->closeContext('test-context');
    expect($closeResult)->toHaveKey('closed');
    expect($closeResult['closed'])->toBeTrue();

    // Verify context removed from status
    $status = $manager->status();
    expect($status['contexts'])->not->toContain('test-context');
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('can create pages in contexts', function (): void {
    $manager = BrowserDaemonManager::getInstance();
    $manager->start();

    // Create context first
    $manager->createContext('test-context');

    // Create page
    $result = $manager->createPage('test-context', 'test-page');
    expect($result)->toHaveKey('pageId');
    expect($result['pageId'])->toBe('test-page');

    // Verify page in status
    $status = $manager->status();
    expect($status['pages'])->toContain('test-page');

    // Clean up
    $manager->closeContext('test-context');
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');

it('automatically starts daemon when sending requests', function (): void {
    $manager = BrowserDaemonManager::getInstance();

    expect($manager->isRunning())->toBeFalse();

    // Should auto-start daemon
    $manager->createContext('auto-start-test');

    expect($manager->isRunning())->toBeTrue();

    // Clean up
    $manager->closeContext('auto-start-test');
})->skip(fn (): bool => getenv('CI') === 'true', 'Browser daemon tests skipped on CI');
