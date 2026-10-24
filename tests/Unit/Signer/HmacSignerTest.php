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
        $this->signer = new HmacSigner('test-secret-0123456789abcdef0123456789');
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
        $config = new SignatureConfig(length: 24);
        $signer = new HmacSigner('test-secret-0123456789abcdef0123456789', $config);

        $signature = $signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertSame(48, strlen($signature));
    }

    #[Test]
    public function it_signs_bucket_path_and_expiry_with_hmac_sha256_by_default(): void
    {
        // hash_hmac('sha256', 'bucket:path/to/file.pdf:1700000000', 'test-secret-0123456789abcdef0123456789'), first 16 bytes as hex
        self::assertSame(
            '248f964dbaed6049bb8e56d61727b059',
            $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000),
        );
    }

    #[Test]
    public function it_uses_the_configured_algorithm(): void
    {
        $signer = new HmacSigner('test-secret-0123456789abcdef0123456789', new SignatureConfig(algorithm: 'sha512', length: 64));

        self::assertSame(
            '6479d8a3d7de8c91f23b64ba7da2acac93cd398c46460de9110d749fbb5c595c'
            . 'fc675c6fc5d57f000cc33a370165ba6160fc38fcae92dcfd1a42193c0893b922',
            $signer->sign('bucket', 'path/to/file.pdf', 1700000000),
        );
    }

    #[Test]
    public function it_returns_the_full_hash_when_length_equals_the_digest_size(): void
    {
        $signer = new HmacSigner('test-secret-0123456789abcdef0123456789', new SignatureConfig(length: 32));

        self::assertSame(
            '248f964dbaed6049bb8e56d61727b059494049a023d2b2b66c2fac4c143e2d72',
            $signer->sign('bucket', 'path/to/file.pdf', 1700000000),
        );
    }

    #[Test]
    public function it_rejects_tampered_expiry(): void
    {
        $signature = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertFalse($this->signer->verify('bucket', 'path/to/file.pdf', 1700003600, $signature));
    }

    #[Test]
    public function it_rejects_a_signature_made_with_another_secret(): void
    {
        $other = new HmacSigner('other-secret-0123456789abcdef0123456789');
        $signature = $other->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertFalse($this->signer->verify('bucket', 'path/to/file.pdf', 1700000000, $signature));
    }

    #[Test]
    public function it_rejects_a_truncated_signature(): void
    {
        $signature = $this->signer->sign('bucket', 'path/to/file.pdf', 1700000000);

        self::assertFalse($this->signer->verify('bucket', 'path/to/file.pdf', 1700000000, substr($signature, 0, -1)));
    }
}
