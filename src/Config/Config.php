<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

use Tattali\PresignedUrl\Exception\InvalidConfigurationException;
use Tattali\PresignedUrl\Signer\HmacSigner;

final readonly class Config
{
    /**
     * @param string $secret at least HmacSigner::MIN_SECRET_LENGTH bytes
     * @param array<string, BucketConfig> $buckets
     *
     * @throws InvalidConfigurationException when the secret is shorter than HmacSigner::MIN_SECRET_LENGTH bytes
     */
    public function __construct(
        public string $secret,
        public string $baseUrl,
        public SignatureConfig $signature = new SignatureConfig(),
        public ServingConfig $serving = new ServingConfig(),
        public SecurityConfig $security = new SecurityConfig(),
        public array $buckets = [],
    ) {
        if (strlen($secret) < HmacSigner::MIN_SECRET_LENGTH) {
            throw InvalidConfigurationException::secretTooShort(HmacSigner::MIN_SECRET_LENGTH);
        }
    }

    public function withBucket(string $name, BucketConfig $config): self
    {
        $buckets = $this->buckets;
        $buckets[$name] = $config;

        return new self(
            secret: $this->secret,
            baseUrl: $this->baseUrl,
            signature: $this->signature,
            serving: $this->serving,
            security: $this->security,
            buckets: $buckets,
        );
    }
}
