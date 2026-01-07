<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

final readonly class BucketConfig
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $adapter,
        public array $options = [],
    ) {}

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
