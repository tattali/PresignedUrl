# PresignedUrl

[![CI](https://github.com/tattali/PresignedUrl/actions/workflows/ci.yml/badge.svg)](https://github.com/tattali/PresignedUrl/actions/workflows/ci.yml)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=tattali_PresignedUrl&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=tattali_PresignedUrl)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=tattali_PresignedUrl&metric=coverage)](https://sonarcloud.io/summary/new_code?id=tattali_PresignedUrl)
[![Latest Stable Version](https://poser.pugx.org/tattali/presigned-url/v/stable)](https://packagist.org/packages/tattali/presigned-url)
[![License](https://poser.pugx.org/tattali/presigned-url/license)](https://packagist.org/packages/tattali/presigned-url)

S3-style presigned URLs for any storage backend.

## Installation

```bash
composer require tattali/presigned-url
```

## Features

- Presigned URL generation with HMAC signature (timing-safe)
- Multi-bucket support with different adapters
- Conditional caching (ETag, If-None-Match, If-Modified-Since -> 304)
- Range requests (206 Partial Content)
- Conditional gzip compression (see the known issue under [Compression](#compression))
- Configurable CORS
- `..` path segments rejected by `LocalAdapter`
- File extension validation
- Symfony 6.4/7.x/8.x and Laravel 12 integrations (CI does not install Laravel 10 or 11)
- Zero core dependencies (PHP 8.2+)

## Standalone Usage

```php
<?php

use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Factory\StorageFactory;

// Configuration
$config = new Config(
    secret: 'your-secret-key',
    baseUrl: 'https://cdn.example.com',
);

// Create storage and server
[$storage, $server] = StorageFactory::createWithServer($config);

// Add a bucket with a local adapter
$storage->addBucket('invoices', StorageFactory::localAdapter('/var/storage/invoices'));

// Generate a presigned URL (expires in 1 hour). Pass the path without a leading slash.
$url = $storage->temporaryUrl('invoices', 'invoice-2024.pdf', 3600);
// https://cdn.example.com/invoices/invoice-2024.pdf?X-Expires=1234567890&X-Signature=abc123...

// Or with a DateTime
$url = $storage->temporaryUrl('invoices', 'invoice-2024.pdf', new \DateTimeImmutable('+1 hour'));
```

### Serving Files

```php
// In your controller or entry point
$response = $server->serve(
    bucket: 'invoices',
    path: 'invoice-2024.pdf',
    expires: (int) $_GET['X-Expires'],
    signature: $_GET['X-Signature'],
    method: $_SERVER['REQUEST_METHOD'],
    headers: getallheaders(),
);

// Send the response
$response->send();
```

## Adapters

### LocalAdapter

Local filesystem storage with path traversal protection.

```php
use Tattali\PresignedUrl\Factory\StorageFactory;

$adapter = StorageFactory::localAdapter('/var/storage/files');
$storage->addBucket('documents', $adapter);
```

### FlysystemAdapter

Wrapper for [League Flysystem](https://flysystem.thephpleague.com/).

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tattali\PresignedUrl\Factory\StorageFactory;

$filesystem = new Filesystem(new LocalFilesystemAdapter('/var/storage'));
$adapter = StorageFactory::flysystemAdapter($filesystem);
$storage->addBucket('documents', $adapter);
```

### AwsS3Adapter

Uses native S3 presigned URLs.

```php
use Tattali\PresignedUrl\Factory\StorageFactory;

$adapter = StorageFactory::s3Adapter([
    'key' => 'AKIAIOSFODNN7EXAMPLE',
    'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    'region' => 'eu-west-1',
    'bucket' => 'my-bucket',
    'endpoint' => null, // Optional, for S3-compatible services (MinIO, etc.)
]);
$storage->addBucket('s3-files', $adapter);

// Generated URLs will be native S3 presigned URLs
$url = $storage->temporaryUrl('s3-files', 'document.pdf', 3600);
```

## Advanced Configuration

```php
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SignatureConfig;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Config\SecurityConfig;
use Tattali\PresignedUrl\Config\CompressionConfig;

$config = new Config(
    secret: 'your-secret-key',
    baseUrl: 'https://cdn.example.com',

    // Signature configuration
    signature: new SignatureConfig(
        algorithm: 'sha256',
        length: 16,
        expiresParam: 'X-Expires',
        signatureParam: 'X-Signature',
    ),

    // Serving configuration
    serving: new ServingConfig(
        defaultTtl: 3600,
        maxTtl: 86400,
        cacheControl: 'private, max-age=3600, must-revalidate',
        contentDisposition: 'inline', // or 'attachment'
        compression: new CompressionConfig(
            enabled: true,
            minSize: 1024,
            level: 6,
            types: ['text/css', 'application/javascript', 'application/json'],
        ),
    ),

    // Security configuration
    security: new SecurityConfig(
        allowedExtensions: [], // Empty = all allowed (except blocked)
        blockedExtensions: ['php', 'exe', 'sh', 'bat'],
        maxFileSize: 0, // 0 = unlimited
        allowedOrigins: ['https://example.com'], // For CORS
    ),
);
```

## Symfony Integration

### Bundle Configuration

```php
// config/bundles.php
return [
    // ...
    Tattali\PresignedUrl\Bridge\Symfony\PresignedUrlBundle::class => ['all' => true],
];
```

```yaml
# config/packages/presigned_url.yaml
presigned_url:
    secret: '%env(PRESIGNED_URL_SECRET)%'
    base_url: '%env(PRESIGNED_URL_BASE)%'

    signature:
        algorithm: sha256
        length: 16
        expires_param: X-Expires
        signature_param: X-Signature

    serving:
        default_ttl: 3600
        max_ttl: 86400
        cache_control: 'private, max-age=3600, must-revalidate'
        content_disposition: inline
        compression:
            enabled: true
            min_size: 1024
            level: 6
            types:
                - text/css
                - application/javascript
                - application/json

    security:
        allowed_extensions: []
        blocked_extensions: [php, exe, sh, bat]
        max_file_size: 0
        allowed_origins: []

    buckets:
        invoices:
            adapter: local
            path: '%kernel.project_dir%/var/storage/invoices'

        documents:
            adapter: flysystem
            service: 'default.storage' # Flysystem service

        s3_files:
            adapter: s3
            key: '%env(AWS_ACCESS_KEY_ID)%'
            secret: '%env(AWS_SECRET_ACCESS_KEY)%'
            region: '%env(AWS_DEFAULT_REGION)%'
            bucket: '%env(AWS_BUCKET)%'
```

### Routes

```yaml
# config/routes/presigned_url.yaml
presigned_url_serve:
    path: /storage/{bucket}/{path}
    controller: Tattali\PresignedUrl\Bridge\Symfony\Controller\ServeController
    requirements:
        path: .+
    methods: [GET, HEAD]
```

### Usage in a Controller

```php
use Tattali\PresignedUrl\Storage\StorageInterface;

class InvoiceController
{
    public function __construct(
        private StorageInterface $storage,
    ) {}

    public function download(string $invoiceId): Response
    {
        $url = $this->storage->temporaryUrl(
            'invoices',
            sprintf('%s.pdf', $invoiceId),
            new \DateTimeImmutable('+1 hour'),
        );

        return new RedirectResponse($url);
    }
}
```

## Laravel Integration

### Publishing Configuration

```bash
php artisan vendor:publish --tag=presigned-url-config
```

### Configuration

```php
// config/presigned-url.php
return [
    'secret' => env('PRESIGNED_STORAGE_SECRET', env('APP_KEY')),
    'base_url' => env('PRESIGNED_STORAGE_URL', env('APP_URL') . '/storage/serve'),

    'signature' => [
        'algorithm' => 'sha256',
        'length' => 16,
        'expires_param' => 'X-Expires',
        'signature_param' => 'X-Signature',
    ],

    'serving' => [
        'default_ttl' => 3600,
        'max_ttl' => 86400,
        'cache_control' => 'private, max-age=3600, must-revalidate',
        'content_disposition' => 'inline',
        'compression' => [
            'enabled' => true,
            'min_size' => 1024,
            'level' => 6,
            'types' => ['text/css', 'application/javascript', 'application/json'],
        ],
    ],

    'security' => [
        'allowed_extensions' => [],
        'blocked_extensions' => ['php', 'exe', 'sh', 'bat'],
        'max_file_size' => 0,
        'allowed_origins' => [],
    ],

    'buckets' => [
        'invoices' => [
            'adapter' => 'local',
            'path' => storage_path('app/invoices'),
        ],
    ],
];
```

### Usage with the Facade

```php
use Tattali\PresignedUrl\Bridge\Laravel\Facades\PresignedUrl;

// Generate a presigned URL
$url = PresignedUrl::temporaryUrl('invoices', 'invoice.pdf', 3600);

// Or with dependency injection
use Tattali\PresignedUrl\Storage\StorageInterface;

class InvoiceController extends Controller
{
    public function download(StorageInterface $storage, string $id)
    {
        $url = $storage->temporaryUrl('invoices', "{$id}.pdf", 3600);

        return redirect($url);
    }
}
```

### Routes

Routes are automatically registered:

- `GET /storage/serve/{bucket}/{path}` -> `presigned-url.serve`
- `HEAD /storage/serve/{bucket}/{path}` -> `presigned-url.serve.head`

## URL Format

```
/{bucket}/{path}?X-Expires={timestamp}&X-Signature={signature}
```

Example:
```
https://cdn.example.com/invoices/2024/invoice-001.pdf?X-Expires=1704067200&X-Signature=05322bd54b5348554216f098c328a885
```

## FileServer Features

### Conditional Caching

The server supports HTTP cache headers:

- `ETag`: File hash for validation
- `If-None-Match`: Returns 304 if the file hasn't changed
- `If-Modified-Since`: Returns 304 if not modified since the date

### Range Requests

Support for partial requests for streaming:

```
GET /bucket/video.mp4
Range: bytes=0-1023

HTTP/1.1 206 Partial Content
Content-Range: bytes 0-1023/1048576
Content-Length: 1024
```

### Compression

`FileServer` gzips the body when compression is enabled, the file is at least `min_size` bytes and its MIME type is in the `types` list. It does not look at the request's `Accept-Encoding` header.

Known issue in 1.0.x: the compressed body is sent without a `Content-Encoding: gzip` header and with the uncompressed `Content-Length`, so clients cannot decode it. Set `enabled: false` and let the web server compress responses.

### CORS

When the request has an `Origin` header that is allowed, it is echoed back with these headers. An empty `allowed_origins` list (the default) allows every origin, so set it to restrict CORS:

```
Access-Control-Allow-Origin: https://example.com
Access-Control-Allow-Methods: GET, HEAD
Access-Control-Allow-Headers: Range
Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges
```

## Exceptions

| Exception | Description |
|-----------|-------------|
| `PresignedUrlException` | Base exception |
| `BucketNotFoundException` | Bucket not found |
| `FileNotFoundException` | File not found |
| `InvalidPathException` | Path traversal detected |
| `InvalidSignatureException` | Invalid signature |
| `ExpiredUrlException` | URL expired |

## HTTP Response Codes

| Code | Description |
|------|-------------|
| 200 | File served successfully |
| 206 | Partial content (range request) |
| 304 | Not modified (valid cache) |
| 400 | Invalid request |
| 403 | Invalid signature |
| 404 | File or bucket not found |
| 410 | URL expired |

## Security Model

This section describes what the code in 1.0.x does. File names refer to `src/`. To report a vulnerability, see [SECURITY.md](SECURITY.md).

### What the signature covers

`Signer/HmacSigner.php` signs the string `{bucket}:{path}:{expires}`:

- `bucket`: the bucket name, the first segment of the URL path;
- `path`: the rest of the URL path, exactly as passed to `temporaryUrl()`;
- `expires`: the expiry as a Unix timestamp, the `X-Expires` query parameter.

Nothing else is signed: not the HTTP method, the host or base URL, other query parameters, request headers such as `Range` or `Origin`, or response headers such as `Content-Disposition`.

`Storage::buildUrl()` strips a leading `/` from the path in the URL but `sign()` receives the path as given. A path passed with a leading slash therefore produces a URL that fails verification with 403. Pass paths without a leading slash.

Bucket names are validated by `Storage::addBucket()` (3 to 63 characters, lowercase letters, digits and single hyphens), so they cannot contain `:` and the signed string is unambiguous. If you call `addBucket($name, $adapter, validate: false)`, do not use `:` in bucket names.

### Algorithm

- `hash_hmac()` with `SignatureConfig::$algorithm`, `sha256` by default. Any algorithm `hash_hmac()` supports can be configured.
- The binary digest is hex-encoded and cut to `SignatureConfig::$length` bytes: 16 bytes by default, so 32 hexadecimal characters and 128 bits. A `length` larger than the digest gives the full digest.
- One secret per `Config`, shared by every bucket.
- The library does not check the secret or the length. An empty secret is accepted, which lets anyone compute valid signatures, and a small `length` gives a short signature. Use a random secret of at least 32 bytes (for example `bin2hex(random_bytes(32))`) and keep `length` at 16 or more.
- The Laravel configuration falls back to `APP_KEY` when `PRESIGNED_STORAGE_SECRET` is not set. Set a dedicated secret.

### Signature comparison

`HmacSigner::verify()` recomputes the signature and compares it with `hash_equals()`, which takes the same time whatever the position of the first differing character. The comparison is case-sensitive: the signature must be lowercase hex.

`FileServer` checks the signature before anything else (`FileServer::doServe()`). An unsigned request gets 403 whether or not the bucket, the file or the expiry are valid.

### Expiry

- `Storage::temporaryUrl()` with an integer TTL sets `expires = time() + min($ttl, maxTtl)`. `maxTtl` is 86400 seconds by default.
- With a `DateTimeInterface`, its timestamp is used as is. `maxTtl` is not applied.
- `ServingConfig::$defaultTtl` is not used by the core classes.
- `FileServer::validateExpiration()` rejects a request with 410 when `time() > expires`, so a URL is valid up to and including its expiry second.
- The expiry is signed, so it cannot be changed without the secret. The server does not apply `maxTtl` to incoming URLs: a URL signed with a far expiry stays valid until then.
- Both checks use the local clock. Servers that generate and serve URLs need synchronised clocks.
- A missing, non-numeric or zero `X-Expires` gives 400.

### Paths

- `LocalAdapter` rejects any `..` segment with `InvalidPathException` (400). It also drops empty and `.` segments and treats `\` as a separator.
- It then checks that the real path of the file's parent directory starts with the real path of the bucket directory. The file itself is not resolved, so symbolic links inside the bucket directory are followed, including links to files outside it. Do not let untrusted users create symbolic links in a bucket directory.
- `FlysystemAdapter` passes the path to Flysystem, whose path normaliser rejects traversal above the root. Flysystem's exceptions are not converted to an HTTP status by `FileServer`.
- `AwsS3Adapter` uses the path as an S3 key.
- Paths are inserted into the URL without percent-encoding. Keep object names to characters that are safe in a URL path (no `?`, `#` or `%`).

### Extensions and sizes

- `FileServer::validateExtension()` takes the last extension only (`pathinfo()`), lowercased. Blocked or not allowed extensions answer 404.
- The default block-list is `php`, `phtml`, `php3`, `php4`, `php5`, `php7`, `phps`, `phar`, `exe`, `sh`, `bat` and `cmd`. Block-list entries must be lowercase.
- When `allowedExtensions` is not empty, only those extensions are served. Files without an extension skip both lists, even when an allow-list is set.
- When `maxFileSize` is above 0, larger files answer 404.
- The extension says nothing about the content. The `Content-Type` comes from the adapter: `LocalAdapter` detects it from the file content with `finfo`.

### What is not protected

- **Replay.** Anyone who has a URL can use it any number of times until it expires. URLs are not bound to a user, a session or an IP address.
- **Revocation.** A single URL cannot be revoked. Changing the secret invalidates all of them.
- **Method.** GET and HEAD share the same signature.
- **Host.** The URL works on any host that serves the same bucket with the same secret.
- **Leaks.** The signature is in the query string, so it ends up in access logs, browser history and `Referer` headers.
- **Content served inline.** Files are served with `Content-Disposition: inline` by default, without `X-Content-Type-Options` or `Content-Security-Policy` headers, and the file name is inserted into `Content-Disposition` without escaping. If buckets hold files uploaded by users (HTML or SVG in particular), set `content_disposition` to `attachment`, serve them from a separate domain, or add `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox` at the web server.
- **CORS.** With an empty `allowedOrigins` list (the default), any `Origin` is echoed back in `Access-Control-Allow-Origin`.
- **Native S3 URLs.** For `AwsS3Adapter` buckets the client downloads directly from S3, so the extension and size checks, CORS settings and logging of `FileServer` do not apply. Access is governed by S3 and your IAM permissions.
- **Confidentiality.** The HMAC proves that a URL was issued with the secret. It does not encrypt anything: serve over HTTPS.
- **Rate limiting.** There is none.

### Rotating the secret

Rotation is not supported out of the box. `HmacSigner` holds one secret and URLs carry no key identifier, so changing the secret makes every outstanding URL fail with 403.

To rotate without breaking issued URLs, implement `SignerInterface` so that it signs with the new secret and also accepts the old one, then remove the old secret once the longest-lived URL signed with it has expired (`maxTtl` for integer TTLs):

```php
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Signer\SignerInterface;

final class RotatingSigner implements SignerInterface
{
    /** @param list<HmacSigner> $previous */
    public function __construct(
        private HmacSigner $current,
        private array $previous = [],
    ) {}

    public function sign(string $bucket, string $path, int $expires): string
    {
        return $this->current->sign($bucket, $path, $expires);
    }

    public function verify(string $bucket, string $path, int $expires, string $signature): bool
    {
        foreach ([$this->current, ...$this->previous] as $signer) {
            if ($signer->verify($bucket, $path, $expires, $signature)) {
                return true;
            }
        }

        return false;
    }
}

$signer = new RotatingSigner(
    new HmacSigner($newSecret, $config->signature),
    [new HmacSigner($oldSecret, $config->signature)],
);

[$storage, $server] = StorageFactory::createWithServer($config, $signer);
```

With the Symfony bundle, decorate the `Tattali\PresignedUrl\Signer\SignerInterface` service. With Laravel, extend the `SignerInterface` binding in a service provider.

## Compatibility with AWS S3

The URLs that this library signs itself (`LocalAdapter`, `FlysystemAdapter` and custom adapters) are **not** AWS Signature Version 4 URLs:

- they carry `X-Expires` and `X-Signature` and none of the `X-Amz-*` parameters;
- the signature is an HMAC of `bucket:path:expires`, not of a SigV4 canonical request with a credential scope, region, service and signed headers;
- `X-Expires` is an absolute Unix timestamp, whereas S3's `X-Amz-Expires` is a duration in seconds.

S3, S3-compatible services and AWS SDKs cannot validate these URLs. Only this library's `FileServer` (directly or through the Symfony and Laravel controllers) can.

For `AwsS3Adapter` buckets, `temporaryUrl()` does not use this format at all. It returns the URL built by the AWS SDK's `S3Client::createPresignedRequest()` for a `GetObject` command (`Adapter/AwsS3Adapter.php`). With the SDK's default settings this is a SigV4 query-string URL (`X-Amz-Algorithm=AWS4-HMAC-SHA256`, `X-Amz-Credential`, `X-Amz-Date`, `X-Amz-Expires`, `X-Amz-SignedHeaders=host`, `X-Amz-Signature`), signed with your AWS credentials and checked by S3.

- The SDK refuses SigV4 expirations longer than one week (604800 seconds) with an `InvalidArgumentException`. The default `maxTtl` keeps integer TTLs below that, but a `DateTimeInterface` further than a week ahead fails.
- S3-compatible services reached through the `endpoint` option must accept SigV4 presigned URLs.
- Only downloads (`GetObject`) are presigned. Uploads are not supported.

## Why not Flysystem temporaryUrl() / native S3 presigning?

- **Native S3 presigning** only exists for objects stored in S3 or an S3-compatible service. When every file is in S3, calling the AWS SDK directly is enough. `AwsS3Adapter` does exactly that.
- **Flysystem 3's `temporaryUrl()`** delegates to the adapter or to a `TemporaryUrlGenerator` you configure, and throws `UnableToGenerateTemporaryUrl` otherwise. The local adapter has no generator: you would have to write the signing and the endpoint that checks the URL and serves the file.
- **This library** gives one `temporaryUrl()` call for local, Flysystem and S3 buckets. For non-S3 buckets it also provides the serving side: signature and expiry checks, ETag and `Last-Modified` handling (304), range requests and CORS, with Symfony and Laravel controllers.
- `FlysystemAdapter` never uses Flysystem's own `temporaryUrl()`, so a Flysystem bucket backed by S3 is streamed through PHP. Use `AwsS3Adapter` for S3 so that clients download from S3 directly.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Static Analysis

```bash
vendor/bin/phpstan analyse
```

## Code Formatting

```bash
vendor/bin/php-cs-fixer fix
```

## License

MIT License. See [LICENSE](LICENSE) for details.
