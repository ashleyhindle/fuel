<?php

use Illuminate\Support\Facades\Artisan;

it('is registered and has correct signature', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('consume:runner');
    expect($commands['consume:runner']->getDefinition()->hasOption('interval'))->toBeTrue();
    expect($commands['consume:runner']->getDefinition()->hasOption('review'))->toBeTrue();
});

it('is hidden from command list', function (): void {
    $command = Artisan::all()['consume:runner'];

    expect($command->isHidden())->toBeTrue();
});

it('has correct description', function (): void {
    $command = Artisan::all()['consume:runner'];

    expect($command->getDescription())->toBe('Headless consume runner for background execution');
});

it('accepts interval option', function (): void {
    $command = Artisan::all()['consume:runner'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('interval'))->toBeTrue();
    expect($definition->getOption('interval')->getDefault())->toBe('5');
});

it('accepts review option', function (): void {
    $command = Artisan::all()['consume:runner'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('review'))->toBeTrue();
});
