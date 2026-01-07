<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\CompressionConfig;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\SecurityConfig;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Factory\StorageFactory;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

final class PresignedUrlFlowTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-integration-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_completes_full_presigned_url_flow(): void
    {
        file_put_contents($this->tempDir . '/document.pdf', 'PDF content here');

        $config = new Config(
            secret: 'my-secret-key-for-signing',
            baseUrl: 'https://cdn.example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('documents', StorageFactory::localAdapter($this->tempDir));

        $url = $storage->temporaryUrl('documents', 'document.pdf', 3600);

        self::assertStringStartsWith('https://cdn.example.com/documents/document.pdf?', $url);
        self::assertStringContainsString('X-Expires=', $url);
        self::assertStringContainsString('X-Signature=', $url);

        $components = $storage->parseUrl($url);

        self::assertNotNull($components);
        self::assertSame('documents', $components->bucket);
        self::assertSame('document.pdf', $components->path);
        self::assertGreaterThan(time(), $components->expires);

        $response = $server->serve(
            $components->bucket,
            $components->path,
            $components->expires,
            $components->signature
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($response->getHeader('Content-Type'));
        self::assertTrue($response->hasBody());
    }

    #[Test]
    public function it_handles_datetime_expiration(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $expiresAt = new DateTimeImmutable('+1 hour');
        $url = $storage->temporaryUrl('files', 'test.txt', $expiresAt);

        $components = $storage->parseUrl($url);

        self::assertNotNull($components);
        self::assertSame($expiresAt->getTimestamp(), $components->expires);

        $response = $server->serve(
            $components->bucket,
            $components->path,
            $components->expires,
            $components->signature
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_expired_urls(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $server = new FileServer($storage, $signer, $config);

        $expires = time() - 3600;
        $signature = $signer->sign('files', 'test.txt', $expires);

        $response = $server->serve('files', 'test.txt', $expires, $signature);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('Gone', $response->getBody());
    }

    #[Test]
    public function it_rejects_tampered_signatures(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $response = $server->serve('files', 'test.txt', time() + 3600, 'invalid-signature');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $response->getBody());
    }

    #[Test]
    public function it_respects_max_ttl(): void
    {
        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
            serving: new ServingConfig(maxTtl: 3600),
        );

        $storage = StorageFactory::create($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $url = $storage->temporaryUrl('files', 'test.txt', 86400);
        $components = $storage->parseUrl($url);

        self::assertNotNull($components);
        self::assertLessThanOrEqual(time() + 3600 + 1, $components->expires);
    }

    #[Test]
    public function it_blocks_dangerous_extensions(): void
    {
        file_put_contents($this->tempDir . '/evil.php', '<?php echo "evil";');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $signer = new HmacSigner($config->secret, $config->signature);
        $expires = time() + 3600;
        $signature = $signer->sign('files', 'evil.php', $expires);

        $response = $server->serve('files', 'evil.php', $expires, $signature);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_handles_range_requests(): void
    {
        file_put_contents($this->tempDir . '/large.txt', 'Hello World, this is a test file!');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $signer = new HmacSigner($config->secret, $config->signature);
        $expires = time() + 3600;
        $signature = $signer->sign('files', 'large.txt', $expires);

        $response = $server->serve('files', 'large.txt', $expires, $signature, 'GET', [
            'Range' => 'bytes=0-4',
        ]);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('Hello', $response->getBody());
        self::assertStringContainsString('bytes 0-4/', $response->getHeader('Content-Range') ?? '');
    }

    #[Test]
    public function it_handles_conditional_requests_with_etag(): void
    {
        file_put_contents($this->tempDir . '/cached.txt', 'Cached content');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $signer = new HmacSigner($config->secret, $config->signature);
        $expires = time() + 3600;
        $signature = $signer->sign('files', 'cached.txt', $expires);

        $response1 = $server->serve('files', 'cached.txt', $expires, $signature);
        $etag = $response1->getHeader('ETag');

        self::assertSame(200, $response1->getStatusCode());
        self::assertNotNull($etag);

        $response2 = $server->serve('files', 'cached.txt', $expires, $signature, 'GET', [
            'If-None-Match' => $etag,
        ]);

        self::assertSame(304, $response2->getStatusCode());
        self::assertFalse($response2->hasBody());
    }

    #[Test]
    public function it_compresses_compressible_content(): void
    {
        $jsonContent = str_repeat('{"key": "value"}', 200);
        file_put_contents($this->tempDir . '/data.json', $jsonContent);

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
            serving: new ServingConfig(
                compression: new CompressionConfig(enabled: true, minSize: 1024),
            ),
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $signer = new HmacSigner($config->secret, $config->signature);
        $expires = time() + 3600;
        $signature = $signer->sign('files', 'data.json', $expires);

        $response = $server->serve('files', 'data.json', $expires, $signature);

        self::assertSame(200, $response->getStatusCode());
        self::assertLessThan(strlen($jsonContent), strlen((string) $response->getBody()));
    }

    #[Test]
    public function it_handles_cors_requests(): void
    {
        file_put_contents($this->tempDir . '/api.json', '{"status":"ok"}');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
            security: new SecurityConfig(allowedOrigins: ['https://app.example.com']),
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $signer = new HmacSigner($config->secret, $config->signature);
        $expires = time() + 3600;
        $signature = $signer->sign('files', 'api.json', $expires);

        $response = $server->serve('files', 'api.json', $expires, $signature, 'GET', [
            'Origin' => 'https://app.example.com',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('GET, HEAD', $response->getHeader('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function it_handles_head_requests(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $signer = new HmacSigner($config->secret, $config->signature);
        $expires = time() + 3600;
        $signature = $signer->sign('files', 'test.txt', $expires);

        $response = $server->serve('files', 'test.txt', $expires, $signature, 'HEAD');

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasBody());
        self::assertSame('11', $response->getHeader('Content-Length'));
    }

    #[Test]
    public function it_handles_nested_paths(): void
    {
        mkdir($this->tempDir . '/deep/nested/folder', 0755, true);
        file_put_contents($this->tempDir . '/deep/nested/folder/file.txt', 'Nested content');

        $config = new Config(
            secret: 'secret',
            baseUrl: 'https://example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('files', new LocalAdapter($this->tempDir));

        $url = $storage->temporaryUrl('files', 'deep/nested/folder/file.txt', 3600);
        $components = $storage->parseUrl($url);

        self::assertNotNull($components);
        self::assertSame('deep/nested/folder/file.txt', $components->path);

        $response = $server->serve(
            $components->bucket,
            $components->path,
            $components->expires,
            $components->signature
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasBody());
    }

    #[Test]
    public function it_uses_factory_to_create_storage_and_server(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Factory test');

        $config = new Config(
            secret: 'factory-secret',
            baseUrl: 'https://factory.example.com',
        );

        [$storage, $server] = StorageFactory::createWithServer($config);
        $storage->addBucket('bucket', StorageFactory::localAdapter($this->tempDir));

        $url = $storage->temporaryUrl('bucket', 'test.txt', 3600);
        $components = $storage->parseUrl($url);

        self::assertNotNull($components);

        $response = $server->serve(
            $components->bucket,
            $components->path,
            $components->expires,
            $components->signature
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasBody());
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
