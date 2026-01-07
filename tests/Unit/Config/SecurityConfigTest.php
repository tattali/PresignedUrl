<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\SecurityConfig;

final class SecurityConfigTest extends TestCase
{
    #[Test]
    public function it_blocks_default_dangerous_extensions(): void
    {
        $config = new SecurityConfig();

        self::assertFalse($config->isExtensionAllowed('php'));
        self::assertFalse($config->isExtensionAllowed('phtml'));
        self::assertFalse($config->isExtensionAllowed('exe'));
        self::assertFalse($config->isExtensionAllowed('sh'));
    }

    #[Test]
    public function it_allows_safe_extensions_by_default(): void
    {
        $config = new SecurityConfig();

        self::assertTrue($config->isExtensionAllowed('pdf'));
        self::assertTrue($config->isExtensionAllowed('jpg'));
        self::assertTrue($config->isExtensionAllowed('png'));
        self::assertTrue($config->isExtensionAllowed('txt'));
    }

    #[Test]
    public function it_respects_allowed_extensions_whitelist(): void
    {
        $config = new SecurityConfig(allowedExtensions: ['pdf', 'jpg']);

        self::assertTrue($config->isExtensionAllowed('pdf'));
        self::assertTrue($config->isExtensionAllowed('jpg'));
        self::assertFalse($config->isExtensionAllowed('png'));
        self::assertFalse($config->isExtensionAllowed('txt'));
    }

    #[Test]
    public function it_blocks_extensions_even_in_whitelist(): void
    {
        $config = new SecurityConfig(
            allowedExtensions: ['pdf', 'php'],
            blockedExtensions: ['php'],
        );

        self::assertTrue($config->isExtensionAllowed('pdf'));
        self::assertFalse($config->isExtensionAllowed('php'));
    }

    #[Test]
    public function it_validates_file_size_when_limit_set(): void
    {
        $config = new SecurityConfig(maxFileSize: 1024);

        self::assertTrue($config->isFileSizeAllowed(500));
        self::assertTrue($config->isFileSizeAllowed(1024));
        self::assertFalse($config->isFileSizeAllowed(1025));
    }

    #[Test]
    public function it_allows_any_size_when_limit_is_zero(): void
    {
        $config = new SecurityConfig(maxFileSize: 0);

        self::assertTrue($config->isFileSizeAllowed(0));
        self::assertTrue($config->isFileSizeAllowed(PHP_INT_MAX));
    }

    #[Test]
    public function it_validates_origins_when_configured(): void
    {
        $config = new SecurityConfig(allowedOrigins: ['https://example.com', 'https://app.example.com']);

        self::assertTrue($config->isOriginAllowed('https://example.com'));
        self::assertTrue($config->isOriginAllowed('https://app.example.com'));
        self::assertFalse($config->isOriginAllowed('https://evil.com'));
    }

    #[Test]
    public function it_allows_any_origin_when_not_configured(): void
    {
        $config = new SecurityConfig(allowedOrigins: []);

        self::assertTrue($config->isOriginAllowed('https://any-origin.com'));
    }

    #[Test]
    public function it_handles_case_insensitive_extensions(): void
    {
        $config = new SecurityConfig();

        self::assertFalse($config->isExtensionAllowed('PHP'));
        self::assertFalse($config->isExtensionAllowed('Php'));
        self::assertFalse($config->isExtensionAllowed('pHp'));
    }
}
