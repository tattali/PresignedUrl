<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Exception\BucketNotFoundException;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

final class StorageTest extends TestCase
{
    private Storage $storage;
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $signer = new HmacSigner($this->config->secret, $this->config->signature);
        $this->storage = new Storage($this->config, $signer);
    }

    #[Test]
    public function it_adds_and_retrieves_buckets(): void
    {
        $adapter = new LocalAdapter('/tmp');
        $this->storage->addBucket('documents', $adapter);

        self::assertTrue($this->storage->hasBucket('documents'));
        self::assertSame($adapter, $this->storage->getBucket('documents'));
    }

    #[Test]
    public function it_throws_on_missing_bucket(): void
    {
        $this->expectException(BucketNotFoundException::class);

        $this->storage->getBucket('nonexistent');
    }

    #[Test]
    public function it_generates_temporary_url_with_ttl(): void
    {
        $adapter = new LocalAdapter('/tmp');
        $this->storage->addBucket('documents', $adapter);

        $url = $this->storage->temporaryUrl('documents', 'file.pdf', 3600);

        self::assertStringStartsWith('https://cdn.example.com/documents/file.pdf?', $url);
        self::assertStringContainsString('X-Expires=', $url);
        self::assertStringContainsString('X-Signature=', $url);
    }

    #[Test]
    public function it_generates_temporary_url_with_datetime(): void
    {
        $adapter = new LocalAdapter('/tmp');
        $this->storage->addBucket('documents', $adapter);

        $expiration = new DateTimeImmutable('+1 hour');
        $url = $this->storage->temporaryUrl('documents', 'file.pdf', $expiration);

        self::assertStringStartsWith('https://cdn.example.com/documents/file.pdf?', $url);
        self::assertStringContainsString('X-Expires=' . $expiration->getTimestamp(), $url);
    }

    #[Test]
    public function it_parses_valid_url(): void
    {
        $adapter = new LocalAdapter('/tmp');
        $this->storage->addBucket('documents', $adapter);

        $url = $this->storage->temporaryUrl('documents', 'path/to/file.pdf', 3600);
        $components = $this->storage->parseUrl($url);

        self::assertNotNull($components);
        self::assertSame('documents', $components->bucket);
        self::assertSame('path/to/file.pdf', $components->path);
        self::assertNotEmpty($components->signature);
    }

    #[Test]
    public function it_returns_null_for_invalid_url(): void
    {
        self::assertNull($this->storage->parseUrl('invalid-url'));
        self::assertNull($this->storage->parseUrl('https://example.com/no-query'));
        self::assertNull($this->storage->parseUrl('https://example.com/bucket-only'));
    }

    #[Test]
    public function it_respects_max_ttl(): void
    {
        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter('/tmp'));

        $url = $storage->temporaryUrl('documents', 'file.pdf', 999999);
        $components = $storage->parseUrl($url);

        self::assertNotNull($components);
        self::assertLessThanOrEqual(time() + 86400 + 1, $components->expires);
    }
}
