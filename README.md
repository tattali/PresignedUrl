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
- Conditional gzip compression
- Configurable CORS
- Path traversal protection
- File extension validation
- Compatible with Symfony 6.4/7.0/8.0 and Laravel 10/11/12
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

// Generate a presigned URL (expires in 1 hour)
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
    'secret' => env('PRESIGNED_URL_SECRET', env('APP_KEY')),
    'base_url' => env('PRESIGNED_URL_BASE', env('APP_URL') . '/storage/serve'),

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
https://cdn.example.com/invoices/2024/invoice-001.pdf?X-Expires=1704067200&X-Signature=a1b2c3d4e5f6g7h8
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

Automatic gzip compression for configured MIME types if:
- Compression is enabled
- File size exceeds `min_size`
- MIME type is in the `types` list
- Client accepts gzip (`Accept-Encoding: gzip`)

### CORS

If `allowed_origins` is configured, CORS headers are added:

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

## Security

- Signatures use HMAC with timing-safe comparison (`hash_equals`)
- Path traversal protection in `LocalAdapter`
- Dangerous extensions blocked by default (php, exe, sh, etc.)
- Configurable file size validation
- Configurable CORS with origin whitelist

## License

MIT License. See [LICENSE](LICENSE) for details.
