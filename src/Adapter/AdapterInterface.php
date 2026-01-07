<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Adapter;

interface AdapterInterface
{
    public function exists(string $path): bool;

    public function read(string $path): string;

    /**
     * @return resource
     */
    public function readStream(string $path);

    public function size(string $path): int;

    public function mimeType(string $path): string;

    public function lastModified(string $path): int;

    public function supportsNativePresignedUrl(): bool;

    public function nativePresignedUrl(string $path, int $expires): ?string;
}
