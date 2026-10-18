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
    protected const GZIP_ETAG_SUFFIX = '-gzip';

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
            return $this->doServe($bucket, $path, $expires, $signature, $method, $headers);
        } catch (PresignedUrlException $e) {
            return $this->handleException($e, $bucket, $path, $expires);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    protected function doServe(
        string $bucket,
        string $path,
        int $expires,
        string $signature,
        string $method,
        array $headers,
    ): FileResponse {
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
    }

    protected function handleException(PresignedUrlException $e, string $bucket, string $path, int $expires): FileResponse
    {
        return match (true) {
            $e instanceof InvalidSignatureException => $this->logAndRespond('warning', 'Invalid signature', ['bucket' => $bucket, 'path' => $path], 403, 'Forbidden'),
            $e instanceof ExpiredUrlException => $this->logAndRespond('info', 'Expired URL', ['bucket' => $bucket, 'path' => $path, 'expires' => $expires], 410, 'Gone'),
            $e instanceof FileNotFoundException, $e instanceof BucketNotFoundException => $this->logAndRespond('info', 'File not found', ['bucket' => $bucket, 'path' => $path], 404, 'Not Found'),
            default => $this->logAndRespond('warning', 'Bad request', ['bucket' => $bucket, 'path' => $path, 'error' => $e->getMessage()], 400, 'Bad Request'),
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function logAndRespond(string $level, string $message, array $context, int $status, string $body): FileResponse
    {
        $this->logger?->$level($message, $context);

        return new FileResponse($status, ['Content-Type' => 'text/plain'], $body);
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
        $parsed = $this->parseRequest($uri, $query);

        if ($parsed === null) {
            return new FileResponse(400, ['Content-Type' => 'text/plain'], 'Bad Request');
        }

        return $this->serve($parsed['bucket'], $parsed['path'], $parsed['expires'], $parsed['signature'], $method, $headers);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{bucket: string, path: string, expires: int, signature: string}|null
     */
    protected function parseRequest(string $uri, array $query): ?array
    {
        $expiresParam = $this->config->signature->expiresParam;
        $signatureParam = $this->config->signature->signatureParam;

        $expires = isset($query[$expiresParam]) ? (int) $query[$expiresParam] : 0;
        $signature = $query[$signatureParam] ?? '';

        $path = parse_url($uri, PHP_URL_PATH);
        $parts = is_string($path) ? explode('/', ltrim($path, '/'), 2) : [];

        if ($expires === 0 || $signature === '' || count($parts) !== 2) {
            return null;
        }

        return ['bucket' => $parts[0], 'path' => $parts[1], 'expires' => $expires, 'signature' => $signature];
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

        $compressible = $this->config->serving->compression->shouldCompress($mimeType, $size);
        $gzip = $compressible && $this->acceptsGzip($headers);

        if ($compressible) {
            $responseHeaders['Vary'] = 'Accept-Encoding';
        }

        $notModified = $this->isNotModified($headers, $gzip ? $etag . self::GZIP_ETAG_SUFFIX : $etag, $lastModified);

        if ($notModified || $method === 'HEAD') {
            if ($gzip) {
                // The compressed size is unknown without compressing, so no Content-Length is sent.
                $responseHeaders['ETag'] = '"' . $etag . self::GZIP_ETAG_SUFFIX . '"';
                unset($responseHeaders['Content-Length']);
            }

            if ($notModified) {
                return new FileResponse(304, $responseHeaders);
            }

            if ($gzip) {
                $responseHeaders['Content-Encoding'] = 'gzip';
            }

            return new FileResponse(200, $responseHeaders);
        }

        return $this->buildBodyResponse($adapter, $path, $mimeType, $size, $headers, $responseHeaders);
    }

    /**
     * @param array<string, string> $requestHeaders
     * @param array<string, string> $responseHeaders
     */
    protected function buildBodyResponse(
        AdapterInterface $adapter,
        string $path,
        string $mimeType,
        int $size,
        array $requestHeaders,
        array $responseHeaders,
    ): FileResponse {
        $range = $this->parseRange($requestHeaders, $size);

        if ($range !== null) {
            return $this->buildPartialResponse($adapter, $path, $range, $size, $responseHeaders);
        }

        if ($this->config->serving->compression->shouldCompress($mimeType, $size) && $this->acceptsGzip($requestHeaders)) {
            $compressed = gzencode($adapter->read($path), $this->config->serving->compression->level);

            if ($compressed !== false) {
                $responseHeaders['Content-Encoding'] = 'gzip';
                $responseHeaders['Content-Length'] = (string) strlen($compressed);
                $responseHeaders['ETag'] = '"' . trim($responseHeaders['ETag'] ?? '', '"') . self::GZIP_ETAG_SUFFIX . '"';

                return new FileResponse(200, $responseHeaders, $compressed);
            }
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
            'X-Content-Type-Options' => 'nosniff',
        ];

        $disposition = $this->config->serving->contentDisposition;
        if (!$this->config->serving->allowUnsafeInline && $this->isScriptableContent($path, $mimeType)) {
            $disposition = 'attachment';
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        $headers['Content-Disposition'] = ContentDisposition::format($disposition, basename($path));

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
     * Tells whether a browser could run script from the file when it is displayed inline.
     */
    protected function isScriptableContent(string $path, string $mimeType): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'xsl', 'js', 'mjs'], true)) {
            return true;
        }

        $mediaType = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if (str_ends_with($mediaType, '+xml') || str_starts_with($mediaType, 'multipart/')) {
            return true;
        }

        // Browsers split on commas and the last valid type wins, so anything but one type/subtype token is suspect.
        if (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $mediaType) !== 1) {
            return true;
        }

        return in_array($mediaType, [
            'text/html',
            'application/xhtml+xml',
            'image/svg+xml',
            'text/xml',
            'application/xml',
            'text/xsl',
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
            'application/ecmascript',
            'text/ecmascript',
        ], true);
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

        $parts = explode('-', substr($range, 6));

        if (count($parts) !== 2) {
            return null;
        }

        $start = $parts[0] !== '' ? (int) $parts[0] : 0;
        $end = $parts[1] !== '' ? min((int) $parts[1], $size - 1) : $size - 1;

        return ($start <= $end && $start < $size) ? ['start' => $start, 'end' => $end] : null;
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
     * @return resource
     */
    protected function getResponseBody(AdapterInterface $adapter, string $path, string $mimeType, int $size)
    {
        return $adapter->readStream($path);
    }

    /**
     * @param array<string, string> $requestHeaders
     */
    protected function acceptsGzip(array $requestHeaders): bool
    {
        $value = null;
        foreach ($requestHeaders as $name => $v) {
            if (strtolower((string) $name) === 'accept-encoding') {
                $value = $v;
                break;
            }
        }

        if ($value === null) {
            return false;
        }

        $gzipQ = null;
        $wildcardQ = null;

        foreach (explode(',', $value) as $part) {
            $params = explode(';', $part);
            $coding = strtolower(trim($params[0]));
            $q = 1.0;

            foreach (array_slice($params, 1) as $param) {
                $kv = explode('=', $param, 2);
                if (strtolower(trim($kv[0])) === 'q' && isset($kv[1]) && is_numeric(trim($kv[1]))) {
                    $q = (float) trim($kv[1]);
                }
            }

            if ($coding === 'gzip' || $coding === 'x-gzip') {
                $gzipQ = max($gzipQ ?? 0.0, $q);
            } elseif ($coding === '*') {
                $wildcardQ = $q;
            }
        }

        return ($gzipQ ?? $wildcardQ ?? 0.0) > 0.0;
    }

    protected function generateEtag(string $path, int $size, int $lastModified): string
    {
        return md5(sprintf('%s-%d-%d', $path, $size, $lastModified)); // NOSONAR - ETag for caching, not security
    }
}
