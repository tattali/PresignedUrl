<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Signer;

use Tattali\PresignedUrl\Config\SignatureConfig;

readonly class HmacSigner implements SignerInterface
{
    public function __construct(
        protected string $secret,
        protected SignatureConfig $config = new SignatureConfig(),
    ) {}

    public function sign(string $bucket, string $path, int $expires): string
    {
        $data = $this->buildSignatureData($bucket, $path, $expires);
        $hash = hash_hmac($this->config->algorithm, $data, $this->secret, true);

        return $this->formatSignature($hash);
    }

    public function verify(string $bucket, string $path, int $expires, string $signature): bool
    {
        $expected = $this->sign($bucket, $path, $expires);

        return hash_equals($expected, $signature);
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
