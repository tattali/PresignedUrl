<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

final readonly class CompressionConfig
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        public bool $enabled = true,
        public int $minSize = 1024,
        public int $level = 6,
        public array $types = [
            'text/plain',
            'text/html',
            'text/css',
            'text/xml',
            'text/javascript',
            'application/javascript',
            'application/json',
            'application/xml',
            'image/svg+xml',
        ],
    ) {}

    public function shouldCompress(string $mimeType, int $size): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($size < $this->minSize) {
            return false;
        }

        return in_array($mimeType, $this->types, true);
    }
}
