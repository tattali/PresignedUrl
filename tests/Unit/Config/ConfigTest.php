<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\BucketConfig;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SecurityConfig;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Config\SignatureConfig;

final class ConfigTest extends TestCase
{
    #[Test]
    public function it_creates_config_with_defaults(): void
    {
        $config = new Config(
            secret: 'my-secret',
            baseUrl: 'https://cdn.example.com',
        );

        self::assertSame('my-secret', $config->secret);
        self::assertSame('https://cdn.example.com', $config->baseUrl);
        self::assertInstanceOf(SignatureConfig::class, $config->signature);
        self::assertInstanceOf(ServingConfig::class, $config->serving);
        self::assertInstanceOf(SecurityConfig::class, $config->security);
        self::assertSame([], $config->buckets);
    }

    #[Test]
    public function it_creates_config_with_custom_configs(): void
    {
        $signature = new SignatureConfig(algorithm: 'sha512', length: 32);
        $serving = new ServingConfig(defaultTtl: 7200);
        $security = new SecurityConfig(allowedExtensions: ['pdf']);

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://cdn.example.com',
            signature: $signature,
            serving: $serving,
            security: $security,
        );

        self::assertSame($signature, $config->signature);
        self::assertSame($serving, $config->serving);
        self::assertSame($security, $config->security);
    }

    #[Test]
    public function it_adds_bucket_with_immutable_method(): void
    {
        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://cdn.example.com',
        );

        $bucketConfig = new BucketConfig(adapter: 'local', options: ['path' => '/tmp/files']);

        $newConfig = $config->withBucket('documents', $bucketConfig);

        self::assertNotSame($config, $newConfig);
        self::assertSame([], $config->buckets);
        self::assertArrayHasKey('documents', $newConfig->buckets);
        self::assertSame($bucketConfig, $newConfig->buckets['documents']);
    }

    #[Test]
    public function it_preserves_existing_buckets_when_adding_new(): void
    {
        $bucket1 = new BucketConfig(adapter: 'local', options: ['path' => '/tmp/images']);
        $bucket2 = new BucketConfig(adapter: 'local', options: ['path' => '/tmp/documents']);

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://cdn.example.com',
            buckets: ['images' => $bucket1],
        );

        $newConfig = $config->withBucket('documents', $bucket2);

        self::assertCount(2, $newConfig->buckets);
        self::assertSame($bucket1, $newConfig->buckets['images']);
        self::assertSame($bucket2, $newConfig->buckets['documents']);
    }

    #[Test]
    public function it_replaces_existing_bucket_with_same_name(): void
    {
        $bucket1 = new BucketConfig(adapter: 'local', options: ['path' => '/tmp/old']);
        $bucket2 = new BucketConfig(adapter: 'local', options: ['path' => '/tmp/new']);

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://cdn.example.com',
            buckets: ['files' => $bucket1],
        );

        $newConfig = $config->withBucket('files', $bucket2);

        self::assertCount(1, $newConfig->buckets);
        self::assertSame($bucket2, $newConfig->buckets['files']);
    }

    #[Test]
    public function it_preserves_all_properties_when_adding_bucket(): void
    {
        $signature = new SignatureConfig(algorithm: 'sha512');
        $serving = new ServingConfig(defaultTtl: 7200);
        $security = new SecurityConfig(maxFileSize: 1000000);

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.test.com',
            signature: $signature,
            serving: $serving,
            security: $security,
        );

        $bucket = new BucketConfig(adapter: 'local', options: ['path' => '/tmp']);
        $newConfig = $config->withBucket('test', $bucket);

        self::assertSame('test-secret', $newConfig->secret);
        self::assertSame('https://cdn.test.com', $newConfig->baseUrl);
        self::assertSame($signature, $newConfig->signature);
        self::assertSame($serving, $newConfig->serving);
        self::assertSame($security, $newConfig->security);
    }
}
