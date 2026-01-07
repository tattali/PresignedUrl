<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

final readonly class SecurityConfig
{
    /**
     * @param list<string> $allowedExtensions
     * @param list<string> $blockedExtensions
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        public array $allowedExtensions = [],
        public array $blockedExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'exe', 'sh', 'bat', 'cmd'],
        public int $maxFileSize = 0,
        public array $allowedOrigins = [],
    ) {}

    public function isExtensionAllowed(string $extension): bool
    {
        $extension = strtolower($extension);

        if (in_array($extension, $this->blockedExtensions, true)) {
            return false;
        }

        if ($this->allowedExtensions === []) {
            return true;
        }

        return in_array($extension, $this->allowedExtensions, true);
    }

    public function isFileSizeAllowed(int $size): bool
    {
        if ($this->maxFileSize === 0) {
            return true;
        }

        return $size <= $this->maxFileSize;
    }

    public function isOriginAllowed(string $origin): bool
    {
        if ($this->allowedOrigins === []) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }
}
