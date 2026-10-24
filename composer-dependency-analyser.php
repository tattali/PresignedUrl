<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// The integrations below are optional: their packages are suggested, installed for development only,
// and used only by the files that integrate them.
return (new Configuration())
    ->ignoreErrorsOnPackageAndPath('aws/aws-sdk-php', __DIR__ . '/src/Adapter/AwsS3Adapter.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPaths('league/flysystem', [
        __DIR__ . '/src/Adapter/FlysystemAdapter.php',
        __DIR__ . '/src/Factory/StorageFactory.php',
    ], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPaths('psr/log', [
        __DIR__ . '/src/Server/FileServer.php',
        __DIR__ . '/src/Factory/StorageFactory.php',
        __DIR__ . '/src/Bridge/Laravel',
        __DIR__ . '/src/Bridge/Symfony',
    ], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackagesAndPaths([
        'illuminate/contracts',
        'illuminate/http',
        'illuminate/support',
    ], [__DIR__ . '/src/Bridge/Laravel'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackagesAndPaths([
        'symfony/config',
        'symfony/dependency-injection',
        'symfony/http-kernel',
        'symfony/routing',
    ], [__DIR__ . '/src/Bridge/Symfony'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPaths('symfony/http-foundation', [
        __DIR__ . '/src/Bridge/Symfony',
        __DIR__ . '/src/Bridge/Laravel/Http/Controllers/ServeController.php',
    ], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // finfo detects local MIME types, gzencode() compresses responses and the compression tests decode them with gzdecode();
    // both extensions ship with most PHP builds.
    ->ignoreErrorsOnExtensionAndPath('ext-fileinfo', __DIR__ . '/src/Adapter/LocalAdapter.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtensionAndPaths('ext-zlib', [
        __DIR__ . '/src/Server/FileServer.php',
        __DIR__ . '/tests/Unit/Server/FileServerCompressionTest.php',
    ], [ErrorType::SHADOW_DEPENDENCY]);
