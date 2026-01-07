<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Adapter\AwsS3Adapter;
use Tattali\PresignedUrl\Adapter\FlysystemAdapter;
use Tattali\PresignedUrl\Bridge\Laravel\PresignedUrlServiceProvider;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Server\FileServerInterface;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Signer\SignerInterface;
use Tattali\PresignedUrl\Storage\Storage;
use Tattali\PresignedUrl\Storage\StorageInterface;

final class PresignedUrlServiceProviderTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        $this->app = new Container();
        Container::setInstance($this->app);

        $this->app->singleton('config', function () {
            return new Repository([
                'presigned-url' => [
                    'secret' => 'test-secret-key',
                    'base_url' => 'https://cdn.example.com',
                    'signature' => [
                        'algorithm' => 'sha256',
                        'length' => 16,
                        'expires_param' => 'X-Expires',
                        'signature_param' => 'X-Signature',
                    ],
                    'serving' => [
                        'default_ttl' => 3600,
                        'max_ttl' => 86400,
                        'cache_control' => 'private, max-age=3600',
                        'content_disposition' => 'inline',
                        'compression' => [
                            'enabled' => true,
                            'min_size' => 1024,
                            'level' => 6,
                            'types' => ['text/plain', 'application/json'],
                        ],
                    ],
                    'security' => [
                        'allowed_extensions' => [],
                        'blocked_extensions' => [],
                        'max_file_size' => 0,
                        'allowed_origins' => [],
                    ],
                    'buckets' => [],
                ],
            ]);
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    #[Test]
    public function it_registers_config_service(): void
    {
        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        self::assertTrue($this->app->bound(Config::class));

        $config = $this->app->make(Config::class);

        self::assertInstanceOf(Config::class, $config);
        self::assertSame('test-secret-key', $config->secret);
        self::assertSame('https://cdn.example.com', $config->baseUrl);
    }

    #[Test]
    public function it_registers_signer_service(): void
    {
        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        self::assertTrue($this->app->bound(SignerInterface::class));

        $signer = $this->app->make(SignerInterface::class);

        self::assertInstanceOf(HmacSigner::class, $signer);

        $signature = $signer->sign('bucket', 'file.txt', 12345);
        self::assertTrue($signer->verify('bucket', 'file.txt', 12345, $signature));
    }

    #[Test]
    public function it_registers_storage_service(): void
    {
        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        self::assertTrue($this->app->bound(StorageInterface::class));

        $storage = $this->app->make(StorageInterface::class);

        self::assertInstanceOf(Storage::class, $storage);
    }

    #[Test]
    public function it_registers_file_server_service(): void
    {
        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        self::assertTrue($this->app->bound(FileServerInterface::class));

        $server = $this->app->make(FileServerInterface::class);

        self::assertInstanceOf(FileServer::class, $server);
    }

    #[Test]
    public function it_registers_presigned_url_alias(): void
    {
        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        self::assertTrue($this->app->isAlias('presigned-url'));

        $storage = $this->app->make('presigned-url');

        self::assertInstanceOf(Storage::class, $storage);
    }

    #[Test]
    public function it_creates_config_with_custom_signature_settings(): void
    {
        $this->app['config']['presigned-url.signature'] = [
            'algorithm' => 'sha512',
            'length' => 32,
            'expires_param' => 'exp',
            'signature_param' => 'sig',
        ];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Config $config */
        $config = $this->app->make(Config::class);

        self::assertSame('sha512', $config->signature->algorithm);
        self::assertSame(32, $config->signature->length);
        self::assertSame('exp', $config->signature->expiresParam);
        self::assertSame('sig', $config->signature->signatureParam);
    }

    #[Test]
    public function it_creates_config_with_custom_serving_settings(): void
    {
        $this->app['config']['presigned-url.serving'] = [
            'default_ttl' => 7200,
            'max_ttl' => 43200,
            'cache_control' => 'public, max-age=7200',
            'content_disposition' => 'attachment',
            'compression' => [
                'enabled' => false,
                'min_size' => 2048,
                'level' => 9,
                'types' => [],
            ],
        ];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Config $config */
        $config = $this->app->make(Config::class);

        self::assertSame(7200, $config->serving->defaultTtl);
        self::assertSame(43200, $config->serving->maxTtl);
        self::assertSame('public, max-age=7200', $config->serving->cacheControl);
        self::assertSame('attachment', $config->serving->contentDisposition);
        self::assertFalse($config->serving->compression->enabled);
    }

    #[Test]
    public function it_creates_config_with_security_settings(): void
    {
        $this->app['config']['presigned-url.security'] = [
            'allowed_extensions' => ['pdf', 'jpg'],
            'blocked_extensions' => ['exe'],
            'max_file_size' => 10485760,
            'allowed_origins' => ['https://app.example.com'],
        ];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Config $config */
        $config = $this->app->make(Config::class);

        self::assertTrue($config->security->isExtensionAllowed('pdf'));
        self::assertFalse($config->security->isExtensionAllowed('exe'));
        self::assertTrue($config->security->isOriginAllowed('https://app.example.com'));
    }

    #[Test]
    public function it_registers_local_bucket(): void
    {
        $tempDir = sys_get_temp_dir() . '/presigned-laravel-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $this->app['config']['presigned-url.buckets'] = [
                'documents' => [
                    'adapter' => 'local',
                    'path' => $tempDir,
                ],
            ];

            $provider = new TestablePresignedUrlServiceProvider($this->app);
            $provider->register();

            /** @var Storage $storage */
            $storage = $this->app->make(StorageInterface::class);

            self::assertTrue($storage->hasBucket('documents'));
        } finally {
            rmdir($tempDir);
        }
    }

    #[Test]
    public function it_generates_temporary_url(): void
    {
        $tempDir = sys_get_temp_dir() . '/presigned-url-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.txt', 'Hello');

        try {
            $this->app['config']['presigned-url.buckets'] = [
                'files' => [
                    'adapter' => 'local',
                    'path' => $tempDir,
                ],
            ];

            $provider = new TestablePresignedUrlServiceProvider($this->app);
            $provider->register();

            /** @var Storage $storage */
            $storage = $this->app->make(StorageInterface::class);

            $url = $storage->temporaryUrl('files', 'test.txt', 3600);

            self::assertStringStartsWith('https://cdn.example.com/files/test.txt?', $url);
            self::assertStringContainsString('X-Expires=', $url);
            self::assertStringContainsString('X-Signature=', $url);
        } finally {
            unlink($tempDir . '/test.txt');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function it_services_are_singletons(): void
    {
        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        $config1 = $this->app->make(Config::class);
        $config2 = $this->app->make(Config::class);

        self::assertSame($config1, $config2);

        $signer1 = $this->app->make(SignerInterface::class);
        $signer2 = $this->app->make(SignerInterface::class);

        self::assertSame($signer1, $signer2);

        $storage1 = $this->app->make(StorageInterface::class);
        $storage2 = $this->app->make(StorageInterface::class);

        self::assertSame($storage1, $storage2);
    }

    #[Test]
    public function it_registers_s3_bucket(): void
    {
        $this->app['config']['presigned-url.buckets'] = [
            's3files' => [
                'adapter' => 's3',
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'bucket' => 'my-s3-bucket',
                'region' => 'us-east-1',
            ],
        ];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Storage $storage */
        $storage = $this->app->make(StorageInterface::class);

        self::assertTrue($storage->hasBucket('s3files'));

        $adapter = $storage->getBucket('s3files');
        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_registers_s3_bucket_with_endpoint(): void
    {
        $this->app['config']['presigned-url.buckets'] = [
            'minio' => [
                'adapter' => 's3',
                'key' => 'minioadmin',
                'secret' => 'minioadmin',
                'bucket' => 'test-bucket',
                'region' => 'us-east-1',
                'endpoint' => 'http://localhost:9000',
            ],
        ];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Storage $storage */
        $storage = $this->app->make(StorageInterface::class);

        self::assertTrue($storage->hasBucket('minio'));
    }

    #[Test]
    public function it_registers_flysystem_bucket(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $this->app->bind('my.flysystem.service', fn () => $filesystem);

        $this->app['config']['presigned-url.buckets'] = [
            'flysystem-bucket' => [
                'adapter' => 'flysystem',
                'service' => 'my.flysystem.service',
            ],
        ];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Storage $storage */
        $storage = $this->app->make(StorageInterface::class);

        self::assertTrue($storage->hasBucket('flysystem-bucket'));

        $adapter = $storage->getBucket('flysystem-bucket');
        self::assertInstanceOf(FlysystemAdapter::class, $adapter);
    }

    #[Test]
    public function it_injects_logger_when_available(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->app->bind(LoggerInterface::class, fn () => $logger);

        $provider = new TestablePresignedUrlServiceProviderWithLogger($this->app);
        $provider->register();

        $server = $this->app->make(FileServerInterface::class);

        self::assertInstanceOf(FileServer::class, $server);
    }

    #[Test]
    public function it_uses_default_values_when_config_not_set(): void
    {
        $this->app['config']['presigned-url.signature'] = [];
        $this->app['config']['presigned-url.serving'] = [];
        $this->app['config']['presigned-url.security'] = [];

        $provider = new TestablePresignedUrlServiceProvider($this->app);
        $provider->register();

        /** @var Config $config */
        $config = $this->app->make(Config::class);

        self::assertSame('sha256', $config->signature->algorithm);
        self::assertSame(16, $config->signature->length);
        self::assertSame('X-Expires', $config->signature->expiresParam);
        self::assertSame('X-Signature', $config->signature->signatureParam);
        self::assertSame(3600, $config->serving->defaultTtl);
        self::assertSame(86400, $config->serving->maxTtl);
    }
}

/**
 * Test-friendly service provider that skips file-based config loading.
 */
class TestablePresignedUrlServiceProvider extends PresignedUrlServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Config::class, function ($app): Config {
            /** @var array<string, mixed> $config */
            $config = $app['config']['presigned-url'];

            return $this->buildConfig($config);
        });

        $this->app->singleton(SignerInterface::class, function ($app): SignerInterface {
            $config = $app->make(Config::class);

            return new \Tattali\PresignedUrl\Signer\HmacSigner($config->secret, $config->signature);
        });

        $this->app->singleton(StorageInterface::class, function ($app): StorageInterface {
            $config = $app->make(Config::class);
            $signer = $app->make(SignerInterface::class);
            $storage = new \Tattali\PresignedUrl\Storage\Storage($config, $signer);

            /** @var array<string, array<string, mixed>> $buckets */
            $buckets = $app['config']['presigned-url.buckets'] ?? [];

            foreach ($buckets as $name => $bucketConfig) {
                $adapter = $this->createAdapter($app, $bucketConfig);
                $storage->addBucket($name, $adapter);
            }

            return $storage;
        });

        $this->app->singleton(FileServerInterface::class, function ($app): FileServerInterface {
            return new \Tattali\PresignedUrl\Server\FileServer(
                $app->make(StorageInterface::class),
                $app->make(SignerInterface::class),
                $app->make(Config::class),
            );
        });

        $this->app->alias(StorageInterface::class, 'presigned-url');
    }
}

/**
 * Test-friendly service provider that includes logger support.
 */
class TestablePresignedUrlServiceProviderWithLogger extends TestablePresignedUrlServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(FileServerInterface::class, function ($app): FileServerInterface {
            $logger = $app->bound(\Psr\Log\LoggerInterface::class)
                ? $app->make(\Psr\Log\LoggerInterface::class)
                : null;

            return new \Tattali\PresignedUrl\Server\FileServer(
                $app->make(StorageInterface::class),
                $app->make(SignerInterface::class),
                $app->make(Config::class),
                $logger,
            );
        });
    }
}

