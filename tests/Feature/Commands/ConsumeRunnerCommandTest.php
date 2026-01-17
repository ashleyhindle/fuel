<?php

use Illuminate\Support\Facades\Artisan;

it('is registered and has correct signature', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('consume:runner');
    expect($commands['consume:runner']->getDefinition()->hasOption('review'))->toBeTrue();
    expect($commands['consume:runner']->getDefinition()->hasOption('port'))->toBeTrue();
});

it('is hidden from command list', function (): void {
    $command = Artisan::all()['consume:runner'];

    expect($command->isHidden())->toBeTrue();
});

it('has correct description', function (): void {
    $command = Artisan::all()['consume:runner'];

    expect($command->getDescription())->toBe('Headless consume runner for background execution');
});

it('accepts review option', function (): void {
    $command = Artisan::all()['consume:runner'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('review'))->toBeTrue();
});

it('accepts port option', function (): void {
    $command = Artisan::all()['consume:runner'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('port'))->toBeTrue();
});
