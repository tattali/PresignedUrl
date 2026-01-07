<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Bridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Tattali\PresignedUrl\Adapter\AdapterInterface;
use Tattali\PresignedUrl\Storage\StorageInterface;
use Tattali\PresignedUrl\Storage\UrlComponents;

/**
 * @method static void addBucket(string $name, AdapterInterface $adapter)
 * @method static AdapterInterface getBucket(string $name)
 * @method static bool hasBucket(string $name)
 * @method static string temporaryUrl(string $bucket, string $path, int|\DateTimeInterface $expiration)
 * @method static UrlComponents|null parseUrl(string $url)
 *
 * @see StorageInterface
 */
final class PresignedUrl extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'presigned-url';
    }
}
