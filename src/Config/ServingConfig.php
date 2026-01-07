<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

final readonly class ServingConfig
{
    public function __construct(
        public int $defaultTtl = 3600,
        public int $maxTtl = 86400,
        public string $cacheControl = 'private, max-age=3600, must-revalidate',
        public string $contentDisposition = 'inline',
        public CompressionConfig $compression = new CompressionConfig(),
    ) {}
}
