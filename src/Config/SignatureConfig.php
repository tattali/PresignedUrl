<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Config;

final readonly class SignatureConfig
{
    public function __construct(
        public string $algorithm = 'sha256',
        public int $length = 16,
        public string $expiresParam = 'X-Expires',
        public string $signatureParam = 'X-Signature',
    ) {}
}
