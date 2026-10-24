<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Laravel\Fixtures;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * A container that satisfies the Application contract for providers that only use container methods.
 */
final class TestApplication extends Container implements Application
{
    public function version(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function basePath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function bootstrapPath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function configPath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function databasePath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function langPath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function publicPath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function resourcePath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function storagePath(mixed $path = ''): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    /**
     * @param array<mixed>|string ...$environments
     */
    public function environment(mixed ...$environments): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function runningInConsole(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function runningUnitTests(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function hasDebugModeEnabled(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function maintenanceMode(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function isDownForMaintenance(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function registerConfiguredProviders(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function register(mixed $provider, mixed $force = false): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function registerDeferredProvider(mixed $provider, mixed $service = null): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function resolveProvider(mixed $provider): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function boot(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function booting(mixed $callback): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function booted(mixed $callback): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    /**
     * @param array<mixed> $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function getLocale(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function getNamespace(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function getProviders(mixed $provider): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function hasBeenBootstrapped(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function loadDeferredProviders(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function setLocale(mixed $locale): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function shouldSkipMiddleware(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function terminating(mixed $callback): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }

    public function terminate(): never
    {
        throw new LogicException(sprintf('%s() is not used by these tests.', __FUNCTION__));
    }
}
