<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Adapter;

use finfo;
use Tattali\PresignedUrl\Exception\FileNotFoundException;
use Tattali\PresignedUrl\Exception\InvalidPathException;

readonly class LocalAdapter implements AdapterInterface
{
    public function __construct(
        protected string $basePath,
    ) {}

    public function exists(string $path): bool
    {
        $fullPath = $this->resolvePath($path);

        return file_exists($fullPath) && is_file($fullPath);
    }

    public function read(string $path): string
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureFileExists($fullPath, $path);

        $content = file_get_contents($fullPath);

        if ($content === false) {
            throw new FileNotFoundException($path);
        }

        return $content;
    }

    public function readStream(string $path)
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureFileExists($fullPath, $path);

        $stream = fopen($fullPath, 'rb');

        if ($stream === false) {
            throw new FileNotFoundException($path);
        }

        return $stream;
    }

    public function size(string $path): int
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureFileExists($fullPath, $path);

        $size = filesize($fullPath);

        return $size !== false ? $size : 0;
    }

    public function mimeType(string $path): string
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureFileExists($fullPath, $path);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fullPath);

        return $mimeType !== false ? $mimeType : 'application/octet-stream';
    }

    public function lastModified(string $path): int
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureFileExists($fullPath, $path);

        $mtime = filemtime($fullPath);

        return $mtime !== false ? $mtime : 0;
    }

    public function supportsNativePresignedUrl(): bool
    {
        return false;
    }

    public function nativePresignedUrl(string $path, int $expires): ?string
    {
        return null;
    }

    protected function resolvePath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidPathException($path);
        }

        // The realpath cache can outlive a directory swapped for a symlink; drop it so the check sees the current tree.
        clearstatcache(true);

        $normalized = $this->normalizePath($path);
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . $normalized;

        $realBasePath = realpath($this->basePath);

        if ($realBasePath === false) {
            throw new InvalidPathException($path);
        }

        $realFullPath = realpath($fullPath);
        $existingPath = $fullPath;

        while ($realFullPath === false) {
            $parentPath = dirname($existingPath);

            if ($parentPath === $existingPath) {
                throw new InvalidPathException($path);
            }

            $existingPath = $parentPath;
            $realFullPath = realpath($existingPath);
        }

        $basePrefix = rtrim($realBasePath, '/\\') . DIRECTORY_SEPARATOR;

        if ($realFullPath !== $realBasePath && !str_starts_with($realFullPath, $basePrefix)) {
            throw new InvalidPathException($path);
        }

        return $existingPath === $fullPath ? $realFullPath : $fullPath;
    }

    protected function normalizePath(string $path): string
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $path = ltrim($path, DIRECTORY_SEPARATOR);

        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                throw new InvalidPathException($path);
            }

            $normalized[] = $part;
        }

        return implode(DIRECTORY_SEPARATOR, $normalized);
    }

    protected function ensureFileExists(string $fullPath, string $originalPath): void
    {
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            throw new FileNotFoundException($originalPath);
        }
    }
}
