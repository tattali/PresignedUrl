<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Storage;

use DateTimeInterface;
use Tattali\PresignedUrl\Adapter\AdapterInterface;

interface StorageInterface
{
    public function addBucket(string $name, AdapterInterface $adapter, bool $validate = true): void;

    public function getBucket(string $name): AdapterInterface;

    public function hasBucket(string $name): bool;

    public function temporaryUrl(string $bucket, string $path, int|DateTimeInterface $expiration): string;

    public function parseUrl(string $url): ?UrlComponents;
}
