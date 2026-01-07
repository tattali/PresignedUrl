<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

final readonly class Config
{
    /**
     * @param array<string, BucketConfig> $buckets
     */
    public function __construct(
        public string $secret,
        public string $baseUrl,
        public SignatureConfig $signature = new SignatureConfig(),
        public ServingConfig $serving = new ServingConfig(),
        public SecurityConfig $security = new SecurityConfig(),
        public array $buckets = [],
    ) {}

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
