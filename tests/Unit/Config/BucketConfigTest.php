<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\BucketConfig;

final class BucketConfigTest extends TestCase
{
    #[Test]
    public function it_creates_bucket_config(): void
    {
        $config = new BucketConfig(
            adapter: 'local',
            options: ['path' => '/tmp/files'],
        );

        self::assertSame('local', $config->adapter);
        self::assertSame(['path' => '/tmp/files'], $config->options);
    }

    #[Test]
    public function it_creates_bucket_config_with_empty_options(): void
    {
        $config = new BucketConfig(adapter: 's3');

        self::assertSame('s3', $config->adapter);
        self::assertSame([], $config->options);
    }

    #[Test]
    public function it_gets_option_value(): void
    {
        $config = new BucketConfig(
            adapter: 'local',
            options: ['path' => '/tmp', 'readonly' => true],
        );

        self::assertSame('/tmp', $config->getOption('path'));
        self::assertTrue($config->getOption('readonly'));
    }

    #[Test]
    public function it_returns_default_for_missing_option(): void
    {
        $config = new BucketConfig(adapter: 'local');

        self::assertNull($config->getOption('path'));
        self::assertSame('/default', $config->getOption('path', '/default'));
        self::assertSame(100, $config->getOption('size', 100));
    }
}
