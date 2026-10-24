<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Factory;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Factory\StorageFactory;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Storage\Storage;

final class StorageFactoryTest extends TestCase
{
    #[Test]
    public function it_creates_storage_instance(): void
    {
        $config = new Config(
            secret: 'test-secret-0123456789abcdef0123456789',
            baseUrl: 'https://cdn.example.com',
        );

        $storage = StorageFactory::create($config);

        self::assertInstanceOf(Storage::class, $storage);
    }

    #[Test]
    public function it_creates_storage_with_server(): void
    {
        $config = new Config(
            secret: 'test-secret-0123456789abcdef0123456789',
            baseUrl: 'https://cdn.example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);

        self::assertInstanceOf(Storage::class, $storage);
        self::assertInstanceOf(FileServer::class, $server);
    }

    #[Test]
    public function it_creates_local_adapter(): void
    {
        $directory = sys_get_temp_dir() . '/presigned-factory-test-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/file.txt', 'local content');

        try {
            $adapter = StorageFactory::localAdapter($directory);

            self::assertSame('local content', $adapter->read('file.txt'));
        } finally {
            unlink($directory . '/file.txt');
            rmdir($directory);
        }
    }

    #[Test]
    public function it_creates_flysystem_adapter(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('fileExists')
            ->with('file.txt')
            ->willReturn(true);

        $adapter = StorageFactory::flysystemAdapter($filesystem);

        self::assertTrue($adapter->exists('file.txt'));
    }

    #[Test]
    public function it_creates_s3_adapter(): void
    {
        $adapter = StorageFactory::s3Adapter([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        $url = $adapter->nativePresignedUrl('file.pdf', time() + 3600);

        self::assertNotNull($url);
        self::assertStringContainsString('my-bucket', $url);
    }
}
