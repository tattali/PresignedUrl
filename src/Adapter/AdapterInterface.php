<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Adapter;

interface AdapterInterface
{
    /**
     * Check if a file exists at the given path.
     */
    public function exists(string $path): bool;

    /**
     * Read the contents of a file.
     *
     * @throws \Tattali\PresignedUrl\Exception\FileNotFoundException
     */
    public function read(string $path): string;

    /**
     * Read a file as a stream.
     *
     * @return resource
     *
     * @throws \Tattali\PresignedUrl\Exception\FileNotFoundException
     */
    public function readStream(string $path);

    /**
     * Get the file size in bytes.
     *
     * @throws \Tattali\PresignedUrl\Exception\FileNotFoundException
     */
    public function size(string $path): int;

    /**
     * Get the MIME type of the file.
     */
    public function mimeType(string $path): string;

    /**
     * Get the last modified timestamp of the file.
     */
    public function lastModified(string $path): int;

    /**
     * Check if this adapter supports native presigned URLs.
     */
    public function supportsNativePresignedUrl(): bool;

    /**
     * Generate a native presigned URL for the given path.
     */
    public function nativePresignedUrl(string $path, int $expires): ?string;
}
