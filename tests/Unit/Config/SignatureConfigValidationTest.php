<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SignatureConfig;
use Tattali\PresignedUrl\Exception\InvalidConfigurationException;

final class SignatureConfigValidationTest extends TestCase
{
    #[Test]
    public function the_exception_is_an_invalid_argument_exception(): void
    {
        self::assertTrue(is_subclass_of(InvalidConfigurationException::class, InvalidArgumentException::class));
    }

    #[Test]
    public function it_exposes_the_minimum_length(): void
    {
        self::assertSame(16, SignatureConfig::MIN_LENGTH);
    }

    #[Test]
    public function it_rejects_a_length_below_the_minimum(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new SignatureConfig(length: 15);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validLengths(): iterable
    {
        yield 'sha256 minimum' => ['sha256', 16];
        yield 'sha256 full digest' => ['sha256', 32];
        yield 'sha512 full digest' => ['sha512', 64];
    }

    #[Test]
    #[DataProvider('validLengths')]
    public function it_accepts_lengths_within_the_digest_size(string $algorithm, int $length): void
    {
        $config = new SignatureConfig(algorithm: $algorithm, length: $length);

        self::assertSame($length, $config->length);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function tooLongLengths(): iterable
    {
        yield 'sha256 33' => ['sha256', 33];
        yield 'sha512 65' => ['sha512', 65];
    }

    #[Test]
    #[DataProvider('tooLongLengths')]
    public function it_rejects_a_length_above_the_digest_size(string $algorithm, int $length): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new SignatureConfig(algorithm: $algorithm, length: $length);
    }

    #[Test]
    public function it_rejects_an_unknown_algorithm(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new SignatureConfig(algorithm: 'not-an-algorithm');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortSecrets(): iterable
    {
        yield 'empty' => [''];
        yield '31 bytes' => [str_repeat('a', 31)];
    }

    #[Test]
    #[DataProvider('shortSecrets')]
    public function config_rejects_a_secret_shorter_than_the_minimum(string $secret): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Config(secret: $secret, baseUrl: 'https://example.com');
    }

    #[Test]
    public function config_accepts_a_32_byte_secret(): void
    {
        $secret = str_repeat('a', 32);

        $config = new Config(secret: $secret, baseUrl: 'https://example.com');

        self::assertSame($secret, $config->secret);
    }

    #[Test]
    public function config_exception_message_never_contains_the_secret(): void
    {
        $secret = 'short-secret-value-xyz';

        try {
            new Config(secret: $secret, baseUrl: 'https://example.com');
            self::fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    #[Test]
    public function it_accepts_an_uppercase_algorithm_name_like_hash_hmac_does(): void
    {
        $config = new SignatureConfig('SHA256', 16);

        self::assertSame('SHA256', $config->algorithm);
        self::assertSame(16, $config->length);
    }
}
