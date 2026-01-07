<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Signer;

interface SignerInterface
{
    public function sign(string $bucket, string $path, int $expires): string;

    public function verify(string $bucket, string $path, int $expires, string $signature): bool;
}
