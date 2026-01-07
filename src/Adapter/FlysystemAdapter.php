<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Adapter;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Tattali\PresignedUrl\Exception\FileNotFoundException;
use Throwable;

readonly class FlysystemAdapter implements AdapterInterface
{
    public function __construct(
        protected FilesystemOperator $filesystem,
    ) {}

    public function exists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($path);
        } catch (UnableToReadFile) {
            throw new FileNotFoundException($path);
        }
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        try {
            $stream = $this->filesystem->readStream($path);
        } catch (UnableToReadFile) {
            throw new FileNotFoundException($path);
        }

        return $stream;
    }

    public function size(string $path): int
    {
        try {
            return $this->filesystem->fileSize($path);
        } catch (Throwable) {
            throw new FileNotFoundException($path);
        }
    }

    public function mimeType(string $path): string
    {
        try {
            return $this->filesystem->mimeType($path);
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }

    public function lastModified(string $path): int
    {
        try {
            return $this->filesystem->lastModified($path);
        } catch (Throwable) {
            return 0;
        }
    }

    public function supportsNativePresignedUrl(): bool
    {
        return false;
    }

    public function nativePresignedUrl(string $path, int $expires): ?string
    {
        return null;
    }
}
