<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Adapter;

use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use Tattali\PresignedUrl\Exception\FileNotFoundException;
use Throwable;

readonly class AwsS3Adapter implements AdapterInterface
{
    public function __construct(
        protected S3Client $client,
        protected string $bucket,
    ) {}

    /**
     * @param array{
     *     key: string,
     *     secret: string,
     *     region: string,
     *     bucket: string,
     *     endpoint?: string,
     *     use_path_style_endpoint?: bool,
     *     version?: string,
     * } $config
     */
    public static function fromConfig(array $config): self
    {
        $clientConfig = [
            'region' => $config['region'],
            'version' => $config['version'] ?? 'latest',
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
        ];

        if (isset($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        if (isset($config['use_path_style_endpoint'])) {
            $clientConfig['use_path_style_endpoint'] = $config['use_path_style_endpoint'];
        }

        $client = new S3Client($clientConfig);

        return new self($client, $config['bucket']);
    }

    public function exists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    public function read(string $path): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            /** @var \Psr\Http\Message\StreamInterface|string $body */
            $body = $result['Body'];

            return is_string($body) ? $body : (string) $body;
        } catch (Throwable) {
            throw new FileNotFoundException($path);
        }
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            /** @var \Psr\Http\Message\StreamInterface $body */
            $body = $result['Body'];
            $stream = $body->detach();

            if ($stream === null) {
                throw new FileNotFoundException($path);
            }

            return $stream;
        } catch (FileNotFoundException $e) {
            throw $e;
        } catch (Throwable) {
            throw new FileNotFoundException($path);
        }
    }

    public function size(string $path): int
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            /** @var int|numeric-string|null $contentLength */
            $contentLength = $result['ContentLength'] ?? 0;

            return is_int($contentLength) ? $contentLength : (int) $contentLength;
        } catch (Throwable) {
            throw new FileNotFoundException($path);
        }
    }

    public function mimeType(string $path): string
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            $contentType = $result['ContentType'] ?? null;

            return is_string($contentType) ? $contentType : 'application/octet-stream';
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }

    public function lastModified(string $path): int
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            $lastModified = $result['LastModified'] ?? null;

            if ($lastModified instanceof DateTimeInterface) {
                return $lastModified->getTimestamp();
            }

            return 0;
        } catch (Throwable) {
            return 0;
        }
    }

    public function supportsNativePresignedUrl(): bool
    {
        return true;
    }

    public function nativePresignedUrl(string $path, int $expires): ?string
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $path,
        ]);

        $expiresAt = new DateTimeImmutable('@' . $expires);
        $request = $this->client->createPresignedRequest($command, $expiresAt);

        return (string) $request->getUri();
    }
}
