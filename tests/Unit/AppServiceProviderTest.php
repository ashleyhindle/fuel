<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use App\Services\FuelContext;

it('resolves --cwd flag with equals syntax', function () {
    $_SERVER['argv'] = ['fuel', '--cwd=/custom/path'];
    $provider = new AppServiceProvider(app());

    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('resolveBasePath');
    $method->setAccessible(true);

    $result = $method->invoke($provider);

    expect($result)->toBe('/custom/path/.fuel');
});

it('resolves --cwd flag with space syntax', function () {
    $_SERVER['argv'] = ['fuel', '--cwd', '/custom/path'];
    $provider = new AppServiceProvider(app());

    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('resolveBasePath');
    $method->setAccessible(true);

    $result = $method->invoke($provider);

    expect($result)->toBe('/custom/path/.fuel');
});

it('finds .fuel directory in current directory', function () {
    $_SERVER['argv'] = ['fuel'];
    $testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($testDir.'/.fuel', 0755, true);
    chdir($testDir);

    $provider = new AppServiceProvider(app());

    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('resolveBasePath');
    $method->setAccessible(true);

    $result = $method->invoke($provider);

    expect($result)->toBe(realpath($testDir).'/.fuel');

    chdir(getcwd().'/../../..');
});

it('finds .fuel directory in parent directory', function () {
    $_SERVER['argv'] = ['fuel'];
    $testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($testDir.'/.fuel', 0755, true);
    $subDir = $testDir.'/subdir';
    mkdir($subDir, 0755, true);
    chdir($subDir);

    $provider = new AppServiceProvider(app());

    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('resolveBasePath');
    $method->setAccessible(true);

    $result = $method->invoke($provider);

    expect($result)->toBe(realpath($testDir).'/.fuel');

    chdir(getcwd().'/../../../..');
});

it('stops searching when .git directory is found', function () {
    $_SERVER['argv'] = ['fuel'];
    $testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($testDir.'/.git', 0755, true);
    $subDir = $testDir.'/subdir';
    mkdir($subDir, 0755, true);
    mkdir($subDir.'/.fuel', 0755, true);
    chdir($subDir);

    $provider = new AppServiceProvider(app());

    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('resolveBasePath');
    $method->setAccessible(true);

    $result = $method->invoke($provider);

    expect($result)->toBe(getcwd().'/.fuel');

    chdir(getcwd().'/../../../..');
});

it('falls back to getcwd/.fuel when no .fuel or .git found', function () {
    $_SERVER['argv'] = ['fuel'];
    $testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($testDir, 0755, true);
    chdir($testDir);

    $provider = new AppServiceProvider(app());

    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('resolveBasePath');
    $method->setAccessible(true);

    $result = $method->invoke($provider);

    expect($result)->toBe(getcwd().'/.fuel');

    chdir(getcwd().'/../..');
});

it('binds FuelContext with resolved base path', function () {
    $_SERVER['argv'] = ['fuel'];
    $testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($testDir.'/.fuel', 0755, true);
    chdir($testDir);

    $provider = new AppServiceProvider(app());
    $provider->register();

    $context = app(FuelContext::class);

    expect($context->basePath)->toBe(realpath($testDir).'/.fuel');

    chdir(getcwd().'/../../..');
});

it('binds FuelContext with --cwd path when provided', function () {
    $_SERVER['argv'] = ['fuel', '--cwd=/custom/path'];
    $provider = new AppServiceProvider(app());
    $provider->register();

    $context = app(FuelContext::class);

    expect($context->basePath)->toBe('/custom/path/.fuel');
});
