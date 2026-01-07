<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Storage;

final readonly class UrlComponents
{
    public function __construct(
        public string $bucket,
        public string $path,
        public int $expires,
        public string $signature,
    ) {}
}
