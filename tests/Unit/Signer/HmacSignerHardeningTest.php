<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Signer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\SignatureConfig;
use Tattali\PresignedUrl\Exception\InvalidConfigurationException;
use Tattali\PresignedUrl\Signer\HmacSigner;

final class HmacSignerHardeningTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef0123456789';

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortSecrets(): iterable
    {
        yield 'empty' => [''];
        yield '31 bytes' => [str_repeat('a', 31)];
        yield '8 multibyte chars, 24 bytes' => [str_repeat('é€', 4) . str_repeat('x', 4)];
    }

    #[Test]
    #[DataProvider('shortSecrets')]
    public function it_rejects_a_secret_shorter_than_the_minimum(string $secret): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new HmacSigner($secret);
    }

    #[Test]
    public function it_accepts_a_secret_of_exactly_the_minimum_length(): void
    {
        $signer = new HmacSigner(str_repeat('a', 32));

        self::assertSame(32, strlen($signer->sign('bucket', 'file.txt', 1700000000)));
    }

    #[Test]
    public function it_exposes_the_minimum_secret_length(): void
    {
        self::assertSame(32, HmacSigner::MIN_SECRET_LENGTH);
    }

    #[Test]
    public function the_exception_message_never_contains_the_secret(): void
    {
        $secret = 'short-secret-value-xyz';

        try {
            new HmacSigner($secret);
            self::fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringContainsString('32', $e->getMessage());
            self::assertStringContainsString('bin2hex(random_bytes(32))', $e->getMessage());
        }
    }

    #[Test]
    public function a_signature_from_before_the_hardening_still_verifies(): void
    {
        $signer = new HmacSigner(self::SECRET);

        self::assertSame('248f964dbaed6049bb8e56d61727b059', $signer->sign('bucket', 'path/to/file.pdf', 1700000000));
        self::assertTrue($signer->verify('bucket', 'path/to/file.pdf', 1700000000, '248f964dbaed6049bb8e56d61727b059'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSignatures(): iterable
    {
        $valid = '248f964dbaed6049bb8e56d61727b059';

        yield 'empty' => [''];
        yield 'truncated' => [substr($valid, 0, 31)];
        yield 'one extra char' => [$valid . '0'];
        yield 'uppercase' => [strtoupper($valid)];
        yield 'non-hex of the right length' => [str_repeat('z', 32)];
        yield 'padded with whitespace' => [' ' . substr($valid, 1)];
        yield 'trailing newline' => [substr($valid, 0, 31) . "\n"];
    }

    #[Test]
    #[DataProvider('malformedSignatures')]
    public function it_rejects_malformed_signatures(string $signature): void
    {
        $signer = new HmacSigner(self::SECRET);

        self::assertFalse($signer->verify('bucket', 'path/to/file.pdf', 1700000000, $signature));
    }

    #[Test]
    public function an_uppercase_algorithm_name_produces_the_same_signature(): void
    {
        $lower = new HmacSigner(self::SECRET, new SignatureConfig('sha256'));
        $upper = new HmacSigner(self::SECRET, new SignatureConfig('SHA256'));

        self::assertSame(
            $lower->sign('documents', 'a.txt', 1893456000),
            $upper->sign('documents', 'a.txt', 1893456000),
        );
    }

    #[Test]
    public function a_subclass_overriding_format_signature_verifies_its_own_signatures(): void
    {
        $signer = new UppercaseHexSigner(self::SECRET);

        $signature = $signer->sign('documents', 'a.txt', 1893456000);

        self::assertSame(strtoupper($signature), $signature);
        self::assertTrue($signer->verify('documents', 'a.txt', 1893456000, $signature));
        self::assertFalse($signer->verify('documents', 'a.txt', 1893456000, strtolower($signature)));
        self::assertFalse($signer->verify('documents', 'b.txt', 1893456000, $signature));
        self::assertFalse($signer->verify('documents', 'a.txt', 1893456000, substr($signature, 0, -1)));
    }
}

// A named class: anonymous readonly classes need PHP 8.3, and HmacSigner is readonly.
readonly class UppercaseHexSigner extends HmacSigner
{
    protected function formatSignature(string $hash): string
    {
        return strtoupper(parent::formatSignature($hash));
    }
}
