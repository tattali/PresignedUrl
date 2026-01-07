<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Server;

use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Adapter\AdapterInterface;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Exception\BucketNotFoundException;
use Tattali\PresignedUrl\Exception\ExpiredUrlException;
use Tattali\PresignedUrl\Exception\FileNotFoundException;
use Tattali\PresignedUrl\Exception\InvalidSignatureException;
use Tattali\PresignedUrl\Exception\PresignedUrlException;
use Tattali\PresignedUrl\Signer\SignerInterface;
use Tattali\PresignedUrl\Storage\StorageInterface;

readonly class FileServer implements FileServerInterface
{
    public function __construct(
        protected StorageInterface $storage,
        protected SignerInterface $signer,
        protected Config $config,
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function serve(
        string $bucket,
        string $path,
        int $expires,
        string $signature,
        string $method = 'GET',
        array $headers = [],
    ): FileResponse {
        try {
            $this->validateSignature($bucket, $path, $expires, $signature);
            $this->validateExpiration($expires);

            $adapter = $this->storage->getBucket($bucket);
            $this->validateExtension($path);

            if (!$adapter->exists($path)) {
                throw new FileNotFoundException($path);
            }

            $this->validateFileSize($adapter, $path);

            $response = $this->buildResponse($adapter, $path, $method, $headers);

            $this->logger?->info('File served', [
                'bucket' => $bucket,
                'path' => $path,
                'status' => $response->getStatusCode(),
                'method' => $method,
            ]);

            return $response;
        } catch (InvalidSignatureException) {
            $this->logger?->warning('Invalid signature', ['bucket' => $bucket, 'path' => $path]);

            return new FileResponse(403, ['Content-Type' => 'text/plain'], 'Forbidden');
        } catch (ExpiredUrlException) {
            $this->logger?->info('Expired URL', ['bucket' => $bucket, 'path' => $path, 'expires' => $expires]);

            return new FileResponse(410, ['Content-Type' => 'text/plain'], 'Gone');
        } catch (FileNotFoundException|BucketNotFoundException) {
            $this->logger?->info('File not found', ['bucket' => $bucket, 'path' => $path]);

            return new FileResponse(404, ['Content-Type' => 'text/plain'], 'Not Found');
        } catch (PresignedUrlException $e) {
            $this->logger?->warning('Bad request', ['bucket' => $bucket, 'path' => $path, 'error' => $e->getMessage()]);

            return new FileResponse(400, ['Content-Type' => 'text/plain'], 'Bad Request');
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function serveFromRequest(
        string $uri,
        array $query = [],
        string $method = 'GET',
        array $headers = [],
    ): FileResponse {
        $expiresParam = $this->config->signature->expiresParam;
        $signatureParam = $this->config->signature->signatureParam;

        $expires = isset($query[$expiresParam]) ? (int) $query[$expiresParam] : 0;
        $signature = $query[$signatureParam] ?? '';

        if ($expires === 0 || $signature === '') {
            return new FileResponse(400, ['Content-Type' => 'text/plain'], 'Bad Request');
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return new FileResponse(400, ['Content-Type' => 'text/plain'], 'Bad Request');
        }

        $path = ltrim($path, '/');
        $parts = explode('/', $path, 2);

        if (count($parts) !== 2) {
            return new FileResponse(400, ['Content-Type' => 'text/plain'], 'Bad Request');
        }

        [$bucket, $filePath] = $parts;

        return $this->serve($bucket, $filePath, $expires, $signature, $method, $headers);
    }

    protected function validateSignature(string $bucket, string $path, int $expires, string $signature): void
    {
        if (!$this->signer->verify($bucket, $path, $expires, $signature)) {
            throw new InvalidSignatureException();
        }
    }

    protected function validateExpiration(int $expires): void
    {
        if (time() > $expires) {
            throw new ExpiredUrlException();
        }
    }

    protected function validateExtension(string $path): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension !== '' && !$this->config->security->isExtensionAllowed($extension)) {
            throw new FileNotFoundException($path);
        }
    }

    protected function validateFileSize(AdapterInterface $adapter, string $path): void
    {
        $size = $adapter->size($path);

        if (!$this->config->security->isFileSizeAllowed($size)) {
            throw new FileNotFoundException($path);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    protected function buildResponse(
        AdapterInterface $adapter,
        string $path,
        string $method,
        array $headers,
    ): FileResponse {
        $size = $adapter->size($path);
        $mimeType = $adapter->mimeType($path);
        $lastModified = $adapter->lastModified($path);
        $etag = $this->generateEtag($path, $size, $lastModified);

        $responseHeaders = $this->buildHeaders($path, $mimeType, $size, $lastModified, $etag, $headers);

        if ($this->isNotModified($headers, $etag, $lastModified)) {
            return new FileResponse(304, $responseHeaders);
        }

        if ($method === 'HEAD') {
            return new FileResponse(200, $responseHeaders);
        }

        $range = $this->parseRange($headers, $size);

        if ($range !== null) {
            return $this->buildPartialResponse($adapter, $path, $range, $size, $responseHeaders);
        }

        $body = $this->getResponseBody($adapter, $path, $mimeType, $size);

        return new FileResponse(200, $responseHeaders, $body);
    }

    /**
     * @param array<string, string> $requestHeaders
     *
     * @return array<string, string>
     */
    protected function buildHeaders(
        string $path,
        string $mimeType,
        int $size,
        int $lastModified,
        string $etag,
        array $requestHeaders,
    ): array {
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $size,
            'ETag' => '"' . $etag . '"',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'Cache-Control' => $this->config->serving->cacheControl,
            'Accept-Ranges' => 'bytes',
        ];

        $filename = basename($path);
        $disposition = $this->config->serving->contentDisposition;
        $headers['Content-Disposition'] = sprintf('%s; filename="%s"', $disposition, $filename);

        $origin = $requestHeaders['Origin'] ?? $requestHeaders['origin'] ?? null;
        if ($origin !== null && $this->config->security->isOriginAllowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Methods'] = 'GET, HEAD';
            $headers['Access-Control-Allow-Headers'] = 'Range';
            $headers['Access-Control-Expose-Headers'] = 'Content-Length, Content-Range, Accept-Ranges';
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function isNotModified(array $headers, string $etag, int $lastModified): bool
    {
        $ifNoneMatch = $headers['If-None-Match'] ?? $headers['if-none-match'] ?? null;
        if ($ifNoneMatch !== null) {
            $ifNoneMatch = trim($ifNoneMatch, '"');
            if ($ifNoneMatch === $etag) {
                return true;
            }
        }

        $ifModifiedSince = $headers['If-Modified-Since'] ?? $headers['if-modified-since'] ?? null;
        if ($ifModifiedSince !== null) {
            $timestamp = strtotime($ifModifiedSince);
            if ($timestamp !== false && $lastModified <= $timestamp) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{start: int, end: int}|null
     */
    protected function parseRange(array $headers, int $size): ?array
    {
        $range = $headers['Range'] ?? $headers['range'] ?? null;

        if ($range === null || !str_starts_with($range, 'bytes=')) {
            return null;
        }

        $range = substr($range, 6);
        $parts = explode('-', $range);

        if (count($parts) !== 2) {
            return null;
        }

        $start = $parts[0] !== '' ? (int) $parts[0] : 0;
        $end = $parts[1] !== '' ? (int) $parts[1] : $size - 1;

        if ($start > $end || $start >= $size) {
            return null;
        }

        $end = min($end, $size - 1);

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param array{start: int, end: int} $range
     * @param array<string, string> $headers
     */
    protected function buildPartialResponse(
        AdapterInterface $adapter,
        string $path,
        array $range,
        int $totalSize,
        array $headers,
    ): FileResponse {
        $start = $range['start'];
        $end = $range['end'];
        $length = $end - $start + 1;

        $headers['Content-Length'] = (string) $length;
        $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $totalSize);

        $stream = $adapter->readStream($path);
        fseek($stream, $start);

        $content = $length > 0 ? fread($stream, $length) : '';
        fclose($stream);

        return new FileResponse(206, $headers, $content !== false ? $content : '');
    }

    /**
     * @return resource|string
     */
    protected function getResponseBody(AdapterInterface $adapter, string $path, string $mimeType, int $size)
    {
        $compression = $this->config->serving->compression;

        if ($compression->shouldCompress($mimeType, $size)) {
            $content = $adapter->read($path);
            $compressed = gzencode($content, $compression->level);

            if ($compressed !== false) {
                return $compressed;
            }

            return $content;
        }

        return $adapter->readStream($path);
    }

    protected function generateEtag(string $path, int $size, int $lastModified): string
    {
        return md5(sprintf('%s-%d-%d', $path, $size, $lastModified));
    }
}
