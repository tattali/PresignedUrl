<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Bridge\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Adapter\AwsS3Adapter;
use Tattali\PresignedUrl\Adapter\FlysystemAdapter;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\CompressionConfig;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SecurityConfig;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Config\SignatureConfig;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Server\FileServerInterface;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Signer\SignerInterface;
use Tattali\PresignedUrl\Storage\Storage;
use Tattali\PresignedUrl\Storage\StorageInterface;

class PresignedUrlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/presigned-url.php',
            'presigned-url',
        );

        $this->app->singleton(Config::class, function (Application $app): Config {
            /** @var array<string, mixed> $config */
            $config = $app['config']['presigned-url'];

            return $this->buildConfig($config);
        });

        $this->app->singleton(SignerInterface::class, function (Application $app): SignerInterface {
            $config = $app->make(Config::class);

            return new HmacSigner($config->secret, $config->signature);
        });

        $this->app->singleton(StorageInterface::class, function (Application $app): StorageInterface {
            $config = $app->make(Config::class);
            $signer = $app->make(SignerInterface::class);
            $storage = new Storage($config, $signer);

            /** @var array<string, array<string, mixed>> $buckets */
            $buckets = $app['config']['presigned-url.buckets'] ?? [];

            foreach ($buckets as $name => $bucketConfig) {
                $adapter = $this->createAdapter($app, $bucketConfig);
                $storage->addBucket($name, $adapter);
            }

            return $storage;
        });

        $this->app->singleton(FileServerInterface::class, function (Application $app): FileServerInterface {
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return new FileServer(
                $app->make(StorageInterface::class),
                $app->make(SignerInterface::class),
                $app->make(Config::class),
                $logger,
            );
        });

        $this->app->alias(StorageInterface::class, 'presigned-url');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/presigned-url.php' => config_path('presigned-url.php'),
        ], 'presigned-url-config');

        $this->loadRoutesFrom(__DIR__ . '/routes/presigned-url.php');
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function buildConfig(array $config): Config
    {
        $signatureConfig = new SignatureConfig(
            algorithm: $config['signature']['algorithm'] ?? 'sha256',
            length: $config['signature']['length'] ?? 16,
            expiresParam: $config['signature']['expires_param'] ?? 'X-Expires',
            signatureParam: $config['signature']['signature_param'] ?? 'X-Signature',
        );

        $compressionConfig = new CompressionConfig(
            enabled: $config['serving']['compression']['enabled'] ?? true,
            minSize: $config['serving']['compression']['min_size'] ?? 1024,
            level: $config['serving']['compression']['level'] ?? 6,
            types: $config['serving']['compression']['types'] ?? [],
        );

        $servingConfig = new ServingConfig(
            defaultTtl: $config['serving']['default_ttl'] ?? 3600,
            maxTtl: $config['serving']['max_ttl'] ?? 86400,
            cacheControl: $config['serving']['cache_control'] ?? 'private, max-age=3600, must-revalidate',
            contentDisposition: $config['serving']['content_disposition'] ?? 'inline',
            compression: $compressionConfig,
        );

        $securityConfig = new SecurityConfig(
            allowedExtensions: $config['security']['allowed_extensions'] ?? [],
            blockedExtensions: $config['security']['blocked_extensions'] ?? [],
            maxFileSize: $config['security']['max_file_size'] ?? 0,
            allowedOrigins: $config['security']['allowed_origins'] ?? [],
        );

        return new Config(
            secret: $config['secret'],
            baseUrl: $config['base_url'],
            signature: $signatureConfig,
            serving: $servingConfig,
            security: $securityConfig,
        );
    }

    /**
     * @param Application|\Illuminate\Contracts\Container\Container $app
     * @param array<string, mixed> $bucketConfig
     */
    protected function createAdapter(mixed $app, array $bucketConfig): LocalAdapter|FlysystemAdapter|AwsS3Adapter
    {
        $adapter = $bucketConfig['adapter'];

        return match ($adapter) {
            'local' => new LocalAdapter($bucketConfig['path']),
            'flysystem' => new FlysystemAdapter($app->make($bucketConfig['service'])),
            's3' => AwsS3Adapter::fromConfig([
                'key' => $bucketConfig['key'],
                'secret' => $bucketConfig['secret'],
                'bucket' => $bucketConfig['bucket'],
                'region' => $bucketConfig['region'],
                'endpoint' => $bucketConfig['endpoint'] ?? null,
            ]),
            default => $app->make($adapter),
        };
    }
}
