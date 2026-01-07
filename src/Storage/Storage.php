<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Storage;

use DateTimeInterface;
use Tattali\PresignedUrl\Adapter\AdapterInterface;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Exception\BucketNotFoundException;
use Tattali\PresignedUrl\Signer\SignerInterface;

class Storage implements StorageInterface
{
    /** @var array<string, AdapterInterface> */
    protected array $buckets = [];

    public function __construct(
        protected readonly Config $config,
        protected readonly SignerInterface $signer,
    ) {}

    public function addBucket(string $name, AdapterInterface $adapter, bool $validate = true): void
    {
        if ($validate) {
            BucketNameValidator::validate($name);
        }

        $this->buckets[$name] = $adapter;
    }

    public function getBucket(string $name): AdapterInterface
    {
        if (!$this->hasBucket($name)) {
            throw new BucketNotFoundException($name);
        }

        return $this->buckets[$name];
    }

    public function hasBucket(string $name): bool
    {
        return isset($this->buckets[$name]);
    }

    public function temporaryUrl(string $bucket, string $path, int|DateTimeInterface $expiration): string
    {
        $adapter = $this->getBucket($bucket);
        $expires = $this->resolveExpiration($expiration);

        if ($adapter->supportsNativePresignedUrl()) {
            $nativeUrl = $adapter->nativePresignedUrl($path, $expires);
            if ($nativeUrl !== null) {
                return $nativeUrl;
            }
        }

        $signature = $this->signer->sign($bucket, $path, $expires);

        return $this->buildUrl($bucket, $path, $expires, $signature);
    }

    public function parseUrl(string $url): ?UrlComponents
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['path'], $parsed['query'])) {
            return null;
        }

        $path = ltrim($parsed['path'], '/');
        $parts = explode('/', $path, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$bucket, $filePath] = $parts;

        parse_str($parsed['query'], $query);

        $expiresParam = $this->config->signature->expiresParam;
        $signatureParam = $this->config->signature->signatureParam;

        if (!isset($query[$expiresParam], $query[$signatureParam])) {
            return null;
        }

        $expiresValue = $query[$expiresParam];
        $signatureValue = $query[$signatureParam];

        if (is_array($expiresValue) || is_array($signatureValue)) {
            return null;
        }

        $expires = (int) $expiresValue;
        $signature = (string) $signatureValue;

        if ($expires === 0 || $signature === '') {
            return null;
        }

        return new UrlComponents(
            bucket: $bucket,
            path: $filePath,
            expires: $expires,
            signature: $signature,
        );
    }

    protected function resolveExpiration(int|DateTimeInterface $expiration): int
    {
        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        $ttl = min($expiration, $this->config->serving->maxTtl);

        return time() + $ttl;
    }

    protected function buildUrl(string $bucket, string $path, int $expires, string $signature): string
    {
        $baseUrl = rtrim($this->config->baseUrl, '/');
        $expiresParam = $this->config->signature->expiresParam;
        $signatureParam = $this->config->signature->signatureParam;

        $query = http_build_query([
            $expiresParam => $expires,
            $signatureParam => $signature,
        ]);

        return sprintf('%s/%s/%s?%s', $baseUrl, $bucket, ltrim($path, '/'), $query);
    }
}
