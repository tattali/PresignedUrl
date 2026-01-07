<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\CompressionConfig;

final class CompressionConfigTest extends TestCase
{
    #[Test]
    public function it_has_default_values(): void
    {
        $config = new CompressionConfig();

        self::assertTrue($config->enabled);
        self::assertSame(1024, $config->minSize);
        self::assertSame(6, $config->level);
        self::assertContains('text/plain', $config->types);
        self::assertContains('application/json', $config->types);
    }

    #[Test]
    public function it_should_compress_compressible_type_above_min_size(): void
    {
        $config = new CompressionConfig();

        self::assertTrue($config->shouldCompress('text/plain', 2048));
        self::assertTrue($config->shouldCompress('application/json', 1024));
        self::assertTrue($config->shouldCompress('text/html', 10000));
    }

    #[Test]
    public function it_should_not_compress_when_disabled(): void
    {
        $config = new CompressionConfig(enabled: false);

        self::assertFalse($config->shouldCompress('text/plain', 2048));
        self::assertFalse($config->shouldCompress('application/json', 10000));
    }

    #[Test]
    public function it_should_not_compress_below_min_size(): void
    {
        $config = new CompressionConfig(minSize: 1024);

        self::assertFalse($config->shouldCompress('text/plain', 500));
        self::assertFalse($config->shouldCompress('text/plain', 1023));
        self::assertTrue($config->shouldCompress('text/plain', 1024));
    }

    #[Test]
    public function it_should_not_compress_non_compressible_types(): void
    {
        $config = new CompressionConfig();

        self::assertFalse($config->shouldCompress('image/jpeg', 10000));
        self::assertFalse($config->shouldCompress('image/png', 10000));
        self::assertFalse($config->shouldCompress('application/pdf', 10000));
        self::assertFalse($config->shouldCompress('video/mp4', 1000000));
    }

    #[Test]
    public function it_respects_custom_types(): void
    {
        $config = new CompressionConfig(types: ['custom/type']);

        self::assertTrue($config->shouldCompress('custom/type', 2048));
        self::assertFalse($config->shouldCompress('text/plain', 2048));
    }

    #[Test]
    public function it_respects_custom_min_size(): void
    {
        $config = new CompressionConfig(minSize: 500);

        self::assertTrue($config->shouldCompress('text/plain', 500));
        self::assertFalse($config->shouldCompress('text/plain', 499));
    }

    #[Test]
    public function it_compresses_svg_images(): void
    {
        $config = new CompressionConfig();

        self::assertTrue($config->shouldCompress('image/svg+xml', 2048));
    }

    #[Test]
    public function it_compresses_javascript(): void
    {
        $config = new CompressionConfig();

        self::assertTrue($config->shouldCompress('text/javascript', 2048));
        self::assertTrue($config->shouldCompress('application/javascript', 2048));
    }
}
