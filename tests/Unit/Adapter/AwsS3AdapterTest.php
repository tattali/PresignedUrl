<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\AwsS3Adapter;

final class AwsS3AdapterTest extends TestCase
{
    #[Test]
    public function it_supports_native_presigned_urls(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        self::assertTrue($adapter->supportsNativePresignedUrl());
    }

    #[Test]
    public function it_creates_from_config(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_creates_from_config_with_endpoint(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'minioadmin',
            'secret' => 'minioadmin',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
            'endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
        ]);

        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_creates_from_config_with_custom_version(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'eu-west-1',
            'bucket' => 'test-bucket',
            'version' => '2006-03-01',
        ]);

        self::assertInstanceOf(AwsS3Adapter::class, $adapter);
    }

    #[Test]
    public function it_generates_native_presigned_url(): void
    {
        $adapter = AwsS3Adapter::fromConfig([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
        ]);

        $expires = time() + 3600;
        $url = $adapter->nativePresignedUrl('test/file.pdf', $expires);

        self::assertNotNull($url);
        self::assertStringContainsString('my-bucket', $url);
        self::assertStringContainsString('test/file.pdf', $url);
        self::assertStringContainsString('X-Amz-Signature', $url);
    }
}
