<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Signer;

use Tattali\PresignedUrl\Config\SignatureConfig;
use Tattali\PresignedUrl\Exception\InvalidConfigurationException;

readonly class HmacSigner implements SignerInterface
{
    /**
     * Minimum secret length in bytes (measured with strlen(), so multibyte characters count by bytes).
     *
     * 32 bytes = 256 bits, the amount of key entropy recommended for HMAC-SHA256, the default algorithm.
     * An empty or short secret would let anyone brute-force or guess it and forge valid signatures.
     */
    public const MIN_SECRET_LENGTH = 32;

    /**
     * @throws InvalidConfigurationException when the secret is shorter than MIN_SECRET_LENGTH bytes
     */
    public function __construct(
        protected string $secret,
        protected SignatureConfig $config = new SignatureConfig(),
    ) {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw InvalidConfigurationException::secretTooShort(self::MIN_SECRET_LENGTH);
        }
    }

    public function sign(string $bucket, string $path, int $expires): string
    {
        $data = $this->buildSignatureData($bucket, $path, $expires);
        $hash = hash_hmac($this->config->algorithm, $data, $this->secret, true);

        return $this->formatSignature($hash);
    }

    public function verify(string $bucket, string $path, int $expires, string $signature): bool
    {
        $expected = $this->sign($bucket, $path, $expires);

        // The length of the expected value is public; comparing it first keeps hash_equals() to equal-length strings.
        return strlen($signature) === strlen($expected) && hash_equals($expected, $signature);
    }

    protected function buildSignatureData(string $bucket, string $path, int $expires): string
    {
        return sprintf('%s:%s:%d', $bucket, $path, $expires);
    }

    protected function formatSignature(string $hash): string
    {
        return substr(bin2hex($hash), 0, $this->config->length * 2);
    }
}
