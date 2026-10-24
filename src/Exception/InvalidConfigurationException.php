<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Exception;

use InvalidArgumentException;

/**
 * Thrown at construction time when a signing configuration is unsafe or unusable.
 *
 * Messages never include the secret value.
 */
final class InvalidConfigurationException extends InvalidArgumentException
{
    public static function secretTooShort(int $minLength): self
    {
        return new self(sprintf(
            'The signing secret must be at least %d bytes long. Generate one with bin2hex(random_bytes(32)).',
            $minLength,
        ));
    }

    public static function unknownAlgorithm(string $algorithm): self
    {
        return new self(sprintf(
            'The signature algorithm "%s" is not supported by hash_hmac(). Use one of hash_hmac_algos(), for example "sha256".',
            $algorithm,
        ));
    }

    public static function signatureLengthTooShort(int $length, int $minLength): self
    {
        return new self(sprintf(
            'The signature length must be at least %d bytes, %d given.',
            $minLength,
            $length,
        ));
    }

    public static function signatureLengthTooLong(int $length, string $algorithm, int $digestSize): self
    {
        return new self(sprintf(
            'The signature length must not exceed the %d-byte digest of "%s", %d given.',
            $digestSize,
            $algorithm,
            $length,
        ));
    }
}
