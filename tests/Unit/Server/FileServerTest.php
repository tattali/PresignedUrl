<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\CompressionConfig;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SecurityConfig;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

final class FileServerTest extends TestCase
{
    private string $tempDir;
    private FileServer $server;
    private Storage $storage;
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-server-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $this->signer = new HmacSigner($config->secret, $config->signature);
        $this->storage = new Storage($config, $this->signer);
        $this->storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $this->server = new FileServer($this->storage, $this->signer, $config);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_serves_valid_file(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeader('Content-Type'));
    }

    #[Test]
    public function it_returns_403_for_invalid_signature(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;

        $response = $this->server->serve('documents', 'test.txt', $expires, 'invalid-signature');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_410_for_expired_url(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() - 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature);

        self::assertSame(410, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_404_for_missing_file(): void
    {
        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'nonexistent.txt', $expires);

        $response = $this->server->serve('documents', 'nonexistent.txt', $expires, $signature);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_404_for_missing_bucket(): void
    {
        $expires = time() + 3600;
        $signature = $this->signer->sign('nonexistent', 'test.txt', $expires);

        $response = $this->server->serve('nonexistent', 'test.txt', $expires, $signature);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_head_requests(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'HEAD');

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasBody());
    }

    #[Test]
    public function it_returns_304_for_matching_etag(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $firstResponse = $this->server->serve('documents', 'test.txt', $expires, $signature);
        $etag = $firstResponse->getHeader('ETag');

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'If-None-Match' => $etag ?? '',
        ]);

        self::assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_range_requests(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=0-4',
        ]);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('5', $response->getHeader('Content-Length'));
        self::assertStringContainsString('bytes 0-4/11', $response->getHeader('Content-Range') ?? '');
    }

    #[Test]
    public function it_blocks_php_extensions(): void
    {
        file_put_contents($this->tempDir . '/test.php', '<?php echo "hello";');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.php', $expires);

        $response = $this->server->serve('documents', 'test.php', $expires, $signature);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_serves_from_request_uri(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serveFromRequest(
            uri: '/documents/test.txt',
            query: [
                'X-Expires' => (string) $expires,
                'X-Signature' => $signature,
            ],
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_cors_headers(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
            security: new SecurityConfig(allowedOrigins: ['https://example.com']),
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'test.txt', $expires);

        $response = $server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Origin' => 'https://example.com',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function it_logs_successful_file_serve(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('File served', $this->callback(function (array $context): bool {
                return $context['bucket'] === 'documents'
                    && $context['path'] === 'test.txt'
                    && $context['status'] === 200;
            }));

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config, $logger);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'test.txt', $expires);

        $server->serve('documents', 'test.txt', $expires, $signature);
    }

    #[Test]
    public function it_logs_invalid_signature(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Invalid signature', $this->callback(function (array $context): bool {
                return $context['bucket'] === 'documents' && $context['path'] === 'test.txt';
            }));

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config, $logger);

        $expires = time() + 3600;

        $server->serve('documents', 'test.txt', $expires, 'invalid-signature');
    }

    #[Test]
    public function it_logs_expired_url(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Expired URL', $this->callback(function (array $context): bool {
                return $context['bucket'] === 'documents' && $context['path'] === 'test.txt';
            }));

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config, $logger);

        $expires = time() - 3600;
        $signature = $signer->sign('documents', 'test.txt', $expires);

        $server->serve('documents', 'test.txt', $expires, $signature);
    }

    #[Test]
    public function it_returns_400_for_missing_query_params(): void
    {
        $response = $this->server->serveFromRequest('/documents/test.txt', []);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_400_for_missing_expires(): void
    {
        $response = $this->server->serveFromRequest(
            uri: '/documents/test.txt',
            query: ['X-Signature' => 'some-signature'],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_400_for_missing_signature(): void
    {
        $response = $this->server->serveFromRequest(
            uri: '/documents/test.txt',
            query: ['X-Expires' => (string) (time() + 3600)],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_400_for_invalid_uri_path(): void
    {
        $response = $this->server->serveFromRequest(
            uri: 'http:///invalid',
            query: [
                'X-Expires' => (string) (time() + 3600),
                'X-Signature' => 'some-signature',
            ],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_400_for_path_without_bucket(): void
    {
        $response = $this->server->serveFromRequest(
            uri: '/only-one-segment',
            query: [
                'X-Expires' => (string) (time() + 3600),
                'X-Signature' => 'some-signature',
            ],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function it_logs_file_not_found(): void
    {
        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('File not found', $this->callback(function (array $context): bool {
                return $context['bucket'] === 'documents' && $context['path'] === 'missing.txt';
            }));

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config, $logger);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'missing.txt', $expires);

        $server->serve('documents', 'missing.txt', $expires, $signature);
    }

    #[Test]
    public function it_returns_304_with_lowercase_if_none_match(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $firstResponse = $this->server->serve('documents', 'test.txt', $expires, $signature);
        $etag = $firstResponse->getHeader('ETag');

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'if-none-match' => $etag ?? '',
        ]);

        self::assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_304_with_if_modified_since(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'If-Modified-Since' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT',
        ]);

        self::assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_304_with_lowercase_if_modified_since(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'if-modified-since' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT',
        ]);

        self::assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_lowercase_range_header(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'range' => 'bytes=0-4',
        ]);

        self::assertSame(206, $response->getStatusCode());
    }

    #[Test]
    public function it_ignores_invalid_range_format(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'invalid-range',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_ignores_range_with_invalid_parts(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=1-2-3',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_ignores_range_with_start_beyond_size(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=1000-2000',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_ignores_range_with_start_greater_than_end(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=10-5',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_cors_with_lowercase_origin(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
            security: new SecurityConfig(allowedOrigins: ['https://example.com']),
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'test.txt', $expires);

        $response = $server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'origin' => 'https://example.com',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function it_handles_range_with_empty_start(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=-5',
        ]);

        self::assertSame(206, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_range_with_empty_end(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        $response = $this->server->serve('documents', 'test.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=5-',
        ]);

        self::assertSame(206, $response->getStatusCode());
        self::assertStringContainsString('bytes 5-10/11', $response->getHeader('Content-Range') ?? '');
    }

    #[Test]
    public function it_serves_compressed_content_when_enabled(): void
    {
        $largeContent = str_repeat('This is some content that should be compressed. ', 100);
        file_put_contents($this->tempDir . '/large.txt', $largeContent);

        $compressionConfig = new CompressionConfig(
            enabled: true,
            minSize: 100,
            types: ['text/plain'],
        );

        $servingConfig = new ServingConfig(compression: $compressionConfig);

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
            serving: $servingConfig,
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'large.txt', $expires);

        $response = $server->serve('documents', 'large.txt', $expires, $signature);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_blocks_files_exceeding_max_size(): void
    {
        file_put_contents($this->tempDir . '/large.txt', str_repeat('x', 1000));

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
            security: new SecurityConfig(maxFileSize: 100),
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'large.txt', $expires);

        $response = $server->serve('documents', 'large.txt', $expires, $signature);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_logs_bad_request_for_blocked_extension(): void
    {
        file_put_contents($this->tempDir . '/script.exe', 'binary');

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
            security: new SecurityConfig(blockedExtensions: ['exe']),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('File not found', $this->anything());

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config, $logger);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'script.exe', $expires);

        $response = $server->serve('documents', 'script.exe', $expires, $signature);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_serves_compressed_content_with_accept_encoding(): void
    {
        $largeContent = str_repeat('This is some content that should be compressed. ', 100);
        file_put_contents($this->tempDir . '/compress.txt', $largeContent);

        $compressionConfig = new CompressionConfig(
            enabled: true,
            minSize: 100,
            types: ['text/plain'],
        );

        $servingConfig = new ServingConfig(compression: $compressionConfig);

        $config = new Config(
            secret: 'test-secret',
            baseUrl: 'https://cdn.example.com',
            serving: $servingConfig,
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', 'compress.txt', $expires);

        $response = $server->serve('documents', 'compress.txt', $expires, $signature, 'GET', [
            'Accept-Encoding' => 'gzip, deflate',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
