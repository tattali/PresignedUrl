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
        /**
         * Serve scriptable files (HTML, SVG, XML, JavaScript) with the configured disposition.
         *
         * By default (false) such files are always sent as `Content-Disposition: attachment`
         * with `Content-Security-Policy: sandbox`, so an uploaded HTML or SVG file cannot run
         * script on the serving origin. Set to true only when every served file is trusted or
         * the files are served from a separate, cookieless domain.
         */
        public bool $allowUnsafeInline = false,
    ) {}
}
