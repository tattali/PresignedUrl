<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SecurityConfig;
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
