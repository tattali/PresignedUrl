<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Signer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\SignatureConfig;
use Tattali\PresignedUrl\Signer\HmacSigner;

final class HmacSignerTest extends TestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new HmacSigner('test-secret');
    }

    #[Test]
    public function it_generates_consistent_signatures(): void
    {
        $signature1 = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);
        $signature2 = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertSame($signature1, $signature2);
    }

    #[Test]
    public function it_generates_different_signatures_for_different_buckets(): void
    {
        $signature1 = $this->signer->sign('bucket1', 'path/to/file.pdf', 1700000000);
        $signature2 = $this->signer->sign('bucket2', 'path/to/file.pdf', 1700000000);

        self::assertNotSame($signature1, $signature2);
    }

    #[Test]
    public function it_generates_different_signatures_for_different_paths(): void
    {
        $signature1 = $this->signer->sign('bucket', 'path/to/file1.pdf', 1700000000);
        $signature2 = $this->signer->sign('bucket', 'path/to/file2.pdf', 1700000000);

        self::assertNotSame($signature1, $signature2);
    }

    #[Test]
    public function it_generates_different_signatures_for_different_expiration(): void
    {
        $signature1 = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);
        $signature2 = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000001);

        self::assertNotSame($signature1, $signature2);
    }

    #[Test]
    public function it_verifies_valid_signature(): void
    {
        $signature = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertTrue($this->signer->verify('bucket', 'path/to/file.pdf', 1700000000, $signature));
    }

    #[Test]
    public function it_rejects_invalid_signature(): void
    {
        self::assertFalse($this->signer->verify('bucket', 'path/to/file.pdf', 1700000000, 'invalid'));
    }

    #[Test]
    public function it_rejects_tampered_bucket(): void
    {
        $signature = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertFalse($this->signer->verify('tampered', 'path/to/file.pdf', 1700000000, $signature));
    }

    #[Test]
    public function it_rejects_tampered_path(): void
    {
        $signature = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertFalse($this->signer->verify('bucket', 'tampered/file.pdf', 1700000000, $signature));
    }

    #[Test]
    public function it_respects_custom_signature_length(): void
    {
        $config = new SignatureConfig(length: 8);
        $signer = new HmacSigner('test-secret', $config);

        $signature = $signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertSame(16, strlen($signature));
    }
}
