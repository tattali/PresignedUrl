<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

use Tattali\PresignedUrl\Exception\InvalidConfigurationException;

final readonly class SignatureConfig
{
    /**
     * Minimum number of HMAC bytes kept in a signature (16 bytes = 128 bits, 32 hex characters).
     */
    public const MIN_LENGTH = 16;

    /**
     * @param int $length number of bytes of the HMAC digest kept in the signature (hex-encoded, so 2 characters per byte)
     *
     * @throws InvalidConfigurationException when the algorithm is unknown or the length is outside [MIN_LENGTH, digest size]
     */
    public function __construct(
        public string $algorithm = 'sha256',
        public int $length = 16,
        public string $expiresParam = 'X-Expires',
        public string $signatureParam = 'X-Signature',
    ) {
        // hash_hmac() accepts any case, so the check and the digest size use the lowercased name.
        $normalizedAlgorithm = strtolower($algorithm);

        if (!in_array($normalizedAlgorithm, hash_hmac_algos(), true)) {
            throw InvalidConfigurationException::unknownAlgorithm($algorithm);
        }

        if ($length < self::MIN_LENGTH) {
            throw InvalidConfigurationException::signatureLengthTooShort($length, self::MIN_LENGTH);
        }

        $digestSize = strlen(hash_hmac($normalizedAlgorithm, '', '', true));
        if ($length > $digestSize) {
            throw InvalidConfigurationException::signatureLengthTooLong($length, $algorithm, $digestSize);
        }
    }
}
