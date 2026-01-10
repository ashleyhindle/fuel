<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
        __DIR__.'/config',
    ])
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/bootstrap',
        __DIR__.'/storage',
        __DIR__.'/.fuel',
        __DIR__.'/agent-resources',
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true
    )
    ->withRules([
        // Always add declare(strict_types=1) to all PHP files
        DeclareStrictTypesRector::class,
    ])
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true
    )
    ->withParallel();
