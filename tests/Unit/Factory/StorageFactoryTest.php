<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Factory;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Factory\StorageFactory;
use Tattali\PresignedUrl\Server\FileServerInterface;
use Tattali\PresignedUrl\Storage\StorageInterface;

final class StorageFactoryTest extends TestCase
{
    #[Test]
    public function it_creates_storage_instance(): void
    {
        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $storage = StorageFactory::create($config);

        self::assertInstanceOf(StorageInterface::class, $storage);
    }

    #[Test]
    public function it_creates_storage_with_server(): void
    {
        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);

        self::assertInstanceOf(StorageInterface::class, $storage);
        self::assertInstanceOf(FileServerInterface::class, $server);
    }

    #[Test]
    public function it_creates_local_adapter(): void
    {
        $adapter = StorageFactory::localAdapter('/tmp');

        self::assertInstanceOf(LocalAdapter::class, $adapter);
    }
}
