<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tattali\PresignedUrl\Bridge\Symfony\Controller\ServeController;
use Tattali\PresignedUrl\Bridge\Symfony\DependencyInjection\PresignedUrlExtension;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Server\FileServerInterface;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Signer\SignerInterface;
use Tattali\PresignedUrl\Storage\Storage;
use Tattali\PresignedUrl\Storage\StorageInterface;

final class PresignedUrlExtensionTest extends TestCase
{
    #[Test]
    public function it_registers_core_services(): void
    {
        $container = $this->createContainer([
            'secret' => 'test-secret',
            'base_url' => 'https://cdn.example.com',
        ]);

        self::assertTrue($container->has(Config::class));
        self::assertTrue($container->has(SignerInterface::class));
        self::assertTrue($container->has(StorageInterface::class));
        self::assertTrue($container->has(FileServerInterface::class));
    }

    #[Test]
    public function it_registers_aliased_services(): void
    {
        $container = $this->createContainer([
            'secret' => 'test-secret',
            'base_url' => 'https://cdn.example.com',
        ]);

        self::assertTrue($container->has('presigned_url.config'));
        self::assertTrue($container->has('presigned_url.signer'));
        self::assertTrue($container->has('presigned_url.storage'));
        self::assertTrue($container->has('presigned_url.server'));
    }

    #[Test]
    public function it_registers_serve_controller(): void
    {
        $container = $this->createContainer([
            'secret' => 'test-secret',
            'base_url' => 'https://cdn.example.com',
        ]);

        self::assertTrue($container->has(ServeController::class));

        $definition = $container->getDefinition(ServeController::class);
        self::assertTrue($definition->hasTag('controller.service_arguments'));
    }

    #[Test]
    public function it_creates_config_with_defaults(): void
    {
        $container = $this->createContainer([
            'secret' => 'my-secret',
            'base_url' => 'https://example.com',
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        /** @var Config $config */
        $config = $container->get(Config::class);

        self::assertSame('my-secret', $config->secret);
        self::assertSame('https://example.com', $config->baseUrl);
        self::assertSame('sha256', $config->signature->algorithm);
        self::assertSame(16, $config->signature->length);
        self::assertSame(3600, $config->serving->defaultTtl);
    }

    #[Test]
    public function it_creates_config_with_custom_signature_settings(): void
    {
        $container = $this->createContainer([
            'secret' => 'my-secret',
            'base_url' => 'https://example.com',
            'signature' => [
                'algorithm' => 'sha512',
                'length' => 32,
                'expires_param' => 'exp',
                'signature_param' => 'sig',
            ],
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        /** @var Config $config */
        $config = $container->get(Config::class);

        self::assertSame('sha512', $config->signature->algorithm);
        self::assertSame(32, $config->signature->length);
        self::assertSame('exp', $config->signature->expiresParam);
        self::assertSame('sig', $config->signature->signatureParam);
    }

    #[Test]
    public function it_creates_config_with_custom_serving_settings(): void
    {
        $container = $this->createContainer([
            'secret' => 'my-secret',
            'base_url' => 'https://example.com',
            'serving' => [
                'default_ttl' => 7200,
                'max_ttl' => 43200,
                'cache_control' => 'public, max-age=7200',
                'content_disposition' => 'attachment',
            ],
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        /** @var Config $config */
        $config = $container->get(Config::class);

        self::assertSame(7200, $config->serving->defaultTtl);
        self::assertSame(43200, $config->serving->maxTtl);
        self::assertSame('public, max-age=7200', $config->serving->cacheControl);
        self::assertSame('attachment', $config->serving->contentDisposition);
    }

    #[Test]
    public function it_creates_config_with_security_settings(): void
    {
        $container = $this->createContainer([
            'secret' => 'my-secret',
            'base_url' => 'https://example.com',
            'security' => [
                'allowed_extensions' => ['pdf', 'jpg'],
                'blocked_extensions' => ['exe'],
                'max_file_size' => 10485760,
                'allowed_origins' => ['https://app.example.com'],
            ],
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        /** @var Config $config */
        $config = $container->get(Config::class);

        self::assertTrue($config->security->isExtensionAllowed('pdf'));
        self::assertFalse($config->security->isExtensionAllowed('exe'));
        self::assertTrue($config->security->isOriginAllowed('https://app.example.com'));
    }

    #[Test]
    public function it_creates_config_with_compression_settings(): void
    {
        $container = $this->createContainer([
            'secret' => 'my-secret',
            'base_url' => 'https://example.com',
            'serving' => [
                'compression' => [
                    'enabled' => false,
                    'min_size' => 2048,
                    'level' => 9,
                    'types' => ['text/plain'],
                ],
            ],
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        /** @var Config $config */
        $config = $container->get(Config::class);

        self::assertFalse($config->serving->compression->enabled);
        self::assertSame(2048, $config->serving->compression->minSize);
        self::assertSame(9, $config->serving->compression->level);
    }

    #[Test]
    public function it_creates_signer_service(): void
    {
        $container = $this->createContainer([
            'secret' => 'signer-test-secret',
            'base_url' => 'https://example.com',
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        $signer = $container->get(SignerInterface::class);

        self::assertInstanceOf(HmacSigner::class, $signer);

        $signature = $signer->sign('bucket', 'path', 12345);
        self::assertTrue($signer->verify('bucket', 'path', 12345, $signature));
    }

    #[Test]
    public function it_creates_storage_service(): void
    {
        $container = $this->createContainer([
            'secret' => 'storage-test',
            'base_url' => 'https://cdn.example.com',
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        $storage = $container->get(StorageInterface::class);

        self::assertInstanceOf(Storage::class, $storage);
    }

    #[Test]
    public function it_creates_file_server_service(): void
    {
        $container = $this->createContainer([
            'secret' => 'server-test',
            'base_url' => 'https://cdn.example.com',
        ]);

        $this->makeServicesPublic($container);
        $container->compile();

        $server = $container->get(FileServerInterface::class);

        self::assertInstanceOf(FileServer::class, $server);
    }

    #[Test]
    public function it_registers_local_bucket(): void
    {
        $tempDir = sys_get_temp_dir() . '/presigned-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $container = $this->createContainer([
                'secret' => 'bucket-test',
                'base_url' => 'https://cdn.example.com',
                'buckets' => [
                    'documents' => [
                        'adapter' => 'local',
                        'path' => $tempDir,
                    ],
                ],
            ]);

            $this->makeServicesPublic($container);
            $container->compile();

            /** @var Storage $storage */
            $storage = $container->get(StorageInterface::class);

            self::assertTrue($storage->hasBucket('documents'));
        } finally {
            rmdir($tempDir);
        }
    }

    #[Test]
    public function it_returns_correct_alias(): void
    {
        $extension = new PresignedUrlExtension();

        self::assertSame('presigned_url', $extension->getAlias());
    }

    #[Test]
    public function it_registers_s3_bucket(): void
    {
        $container = $this->createContainer([
            'secret' => 's3-test',
            'base_url' => 'https://cdn.example.com',
            'buckets' => [
                's3bucket' => [
                    'adapter' => 's3',
                    'key' => 'AKIAIOSFODNN7EXAMPLE',
                    'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                    'bucket' => 'my-s3-bucket',
                    'region' => 'us-east-1',
                ],
            ],
        ]);

        self::assertTrue($container->hasDefinition('presigned_url.adapter.s3bucket'));
    }

    #[Test]
    public function it_registers_s3_bucket_with_endpoint(): void
    {
        $container = $this->createContainer([
            'secret' => 's3-endpoint-test',
            'base_url' => 'https://cdn.example.com',
            'buckets' => [
                'minio' => [
                    'adapter' => 's3',
                    'key' => 'minioadmin',
                    'secret' => 'minioadmin',
                    'bucket' => 'test-bucket',
                    'region' => 'us-east-1',
                    'endpoint' => 'http://localhost:9000',
                ],
            ],
        ]);

        self::assertTrue($container->hasDefinition('presigned_url.adapter.minio'));
    }

    #[Test]
    public function it_registers_flysystem_bucket(): void
    {
        $container = $this->createContainer([
            'secret' => 'flysystem-test',
            'base_url' => 'https://cdn.example.com',
            'buckets' => [
                'flybucket' => [
                    'adapter' => 'flysystem',
                    'service' => 'my.flysystem.service',
                ],
            ],
        ]);

        self::assertTrue($container->hasDefinition('presigned_url.adapter.flybucket'));
    }

    #[Test]
    public function it_registers_custom_adapter_as_reference(): void
    {
        $container = $this->createContainer([
            'secret' => 'custom-test',
            'base_url' => 'https://cdn.example.com',
            'buckets' => [
                'custom' => [
                    'adapter' => 'my.custom.adapter.service',
                ],
            ],
        ]);

        $storageDef = $container->getDefinition(StorageInterface::class);
        $methodCalls = $storageDef->getMethodCalls();

        self::assertNotEmpty($methodCalls);
        self::assertSame('addBucket', $methodCalls[0][0]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new PresignedUrlExtension();

        $extension->load([$config], $container);

        return $container;
    }

    private function makeServicesPublic(ContainerBuilder $container): void
    {
        $services = [
            Config::class,
            SignerInterface::class,
            StorageInterface::class,
            FileServerInterface::class,
        ];

        foreach ($services as $service) {
            if ($container->hasDefinition($service)) {
                $container->getDefinition($service)->setPublic(true);
            }
        }
    }
}
