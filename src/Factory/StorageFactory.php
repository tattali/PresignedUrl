<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Factory;

use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Adapter\AwsS3Adapter;
use Tattali\PresignedUrl\Adapter\FlysystemAdapter;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Server\FileServerInterface;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Signer\SignerInterface;
use Tattali\PresignedUrl\Storage\Storage;
use Tattali\PresignedUrl\Storage\StorageInterface;

final class StorageFactory
{
    public static function create(Config $config, ?SignerInterface $signer = null): StorageInterface
    {
        $signer ??= new HmacSigner($config->secret, $config->signature);

        return new Storage($config, $signer);
    }

    /**
     * @return array{StorageInterface, FileServerInterface}
     */
    public static function createWithServer(
        Config $config,
        ?SignerInterface $signer = null,
        ?LoggerInterface $logger = null,
    ): array {
        $signer ??= new HmacSigner($config->secret, $config->signature);

        $storage = new Storage($config, $signer);
        $server = new FileServer($storage, $signer, $config, $logger);

        return [$storage, $server];
    }

    public static function localAdapter(string $path): LocalAdapter
    {
        return new LocalAdapter($path);
    }

    public static function flysystemAdapter(FilesystemOperator $filesystem): FlysystemAdapter
    {
        return new FlysystemAdapter($filesystem);
    }

    /**
     * @param array{
     *     key: string,
     *     secret: string,
     *     region: string,
     *     bucket: string,
     *     endpoint?: string,
     *     use_path_style_endpoint?: bool,
     *     version?: string,
     * } $config
     */
    public static function s3Adapter(array $config): AwsS3Adapter
    {
        return AwsS3Adapter::fromConfig($config);
    }
}
