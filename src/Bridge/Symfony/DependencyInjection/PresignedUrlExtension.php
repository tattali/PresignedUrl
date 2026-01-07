<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Bridge\Symfony\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Tattali\PresignedUrl\Adapter\AwsS3Adapter;
use Tattali\PresignedUrl\Adapter\FlysystemAdapter;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Bridge\Symfony\Controller\ServeController;
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

final class PresignedUrlExtension extends Extension
{
    /**
     * @param array<string, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerServices($config, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadFromConfig(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $container): void
    {
        $this->registerServices($config, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerServices(array $config, ContainerBuilder $container): void
    {
        $signatureConfig = new Definition(SignatureConfig::class, [
            $config['signature']['algorithm'] ?? 'sha256',
            $config['signature']['length'] ?? 16,
            $config['signature']['expires_param'] ?? 'X-Expires',
            $config['signature']['signature_param'] ?? 'X-Signature',
        ]);

        $compressionConfig = new Definition(CompressionConfig::class, [
            $config['serving']['compression']['enabled'] ?? true,
            $config['serving']['compression']['min_size'] ?? 1024,
            $config['serving']['compression']['level'] ?? 6,
            $config['serving']['compression']['types'] ?? [],
        ]);

        $servingConfig = new Definition(ServingConfig::class, [
            $config['serving']['default_ttl'] ?? 3600,
            $config['serving']['max_ttl'] ?? 86400,
            $config['serving']['cache_control'] ?? 'private, max-age=3600, must-revalidate',
            $config['serving']['content_disposition'] ?? 'inline',
            $compressionConfig,
        ]);

        $securityConfig = new Definition(SecurityConfig::class, [
            $config['security']['allowed_extensions'] ?? [],
            $config['security']['blocked_extensions'] ?? [],
            $config['security']['max_file_size'] ?? 0,
            $config['security']['allowed_origins'] ?? [],
        ]);

        $configDef = new Definition(Config::class, [
            $config['secret'],
            $config['base_url'],
            $signatureConfig,
            $servingConfig,
            $securityConfig,
            [],
        ]);
        $container->setDefinition(Config::class, $configDef);
        $container->setDefinition('presigned_url.config', $configDef);

        $signerDef = new Definition(HmacSigner::class, [
            $config['secret'],
            $signatureConfig,
        ]);
        $container->setDefinition(SignerInterface::class, $signerDef);
        $container->setDefinition('presigned_url.signer', $signerDef);

        $storageDef = new Definition(Storage::class, [
            new Reference(Config::class),
            new Reference(SignerInterface::class),
        ]);
        $container->setDefinition(StorageInterface::class, $storageDef);
        $container->setDefinition('presigned_url.storage', $storageDef);

        $loggerRef = new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE);
        $serverDef = new Definition(FileServer::class, [
            new Reference(StorageInterface::class),
            new Reference(SignerInterface::class),
            new Reference(Config::class),
            $loggerRef,
        ]);
        $container->setDefinition(FileServerInterface::class, $serverDef);
        $container->setDefinition('presigned_url.server', $serverDef);

        $controllerDef = new Definition(ServeController::class, [
            new Reference(FileServerInterface::class),
        ]);
        $controllerDef->addTag('controller.service_arguments');
        $container->setDefinition(ServeController::class, $controllerDef);

        $this->registerBuckets($config['buckets'] ?? [], $container);
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     */
    private function registerBuckets(array $buckets, ContainerBuilder $container): void
    {
        foreach ($buckets as $name => $bucketConfig) {
            $adapter = $bucketConfig['adapter'];

            $adapterDef = match ($adapter) {
                'local' => new Definition(LocalAdapter::class, [$bucketConfig['path']]),
                'flysystem' => new Definition(FlysystemAdapter::class, [new Reference($bucketConfig['service'])]),
                's3' => (new Definition(AwsS3Adapter::class))
                    ->setFactory([AwsS3Adapter::class, 'fromConfig'])
                    ->setArguments([[
                        'key' => $bucketConfig['key'],
                        'secret' => $bucketConfig['secret'],
                        'bucket' => $bucketConfig['bucket'],
                        'region' => $bucketConfig['region'],
                        'endpoint' => $bucketConfig['endpoint'] ?? null,
                    ]]),
                default => new Reference($adapter),
            };

            $adapterServiceId = sprintf('presigned_url.adapter.%s', $name);
            if ($adapterDef instanceof Definition) {
                $container->setDefinition($adapterServiceId, $adapterDef);
            }

            $storageDef = $container->getDefinition(StorageInterface::class);
            $storageDef->addMethodCall('addBucket', [
                $name,
                $adapterDef instanceof Definition ? new Reference($adapterServiceId) : $adapterDef,
            ]);
        }
    }

    public function getAlias(): string
    {
        return 'presigned_url';
    }
}
