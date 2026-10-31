<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\CompressionConfig;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Server\FileResponse;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

/**
 * The zlib extension is optional: without it the server must keep serving files, uncompressed.
 */
final class FileServerWithoutZlibTest extends TestCase
{
    private string $tempDir;
    private string $content;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-nozlib-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->content = str_repeat('Hello compressible world. ', 100);
        file_put_contents($this->tempDir . '/big.txt', $this->content);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files === false ? [] : $files as $f) {
            unlink($f);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function a_gzip_request_is_served_uncompressed_when_zlib_is_missing(): void
    {
        $r = $this->get('GET');

        self::assertSame(200, $r->getStatusCode());
        self::assertNull($r->getHeader('Content-Encoding'));
        self::assertSame((string) strlen($this->content), $r->getHeader('Content-Length'));
        self::assertStringNotContainsString('-gzip', (string) $r->getHeader('ETag'));
        $body = $r->getBody();
        self::assertSame($this->content, is_resource($body) ? (string) stream_get_contents($body) : (string) $body);
    }

    #[Test]
    public function head_does_not_announce_gzip_when_zlib_is_missing(): void
    {
        $r = $this->get('HEAD');

        self::assertSame(200, $r->getStatusCode());
        self::assertNull($r->getHeader('Content-Encoding'));
        self::assertSame((string) strlen($this->content), $r->getHeader('Content-Length'));
        self::assertStringNotContainsString('-gzip', (string) $r->getHeader('ETag'));
    }

    private function get(string $method): FileResponse
    {
        $config = new Config(
            secret: 'test-secret-0123456789abcdef0123456789',
            baseUrl: 'https://cdn.example.com',
            serving: new ServingConfig(compression: new CompressionConfig(enabled: true, minSize: 100)),
        );
        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));
        $server = new FileServerWithoutZlib($storage, $signer, $config);

        $expires = time() + 3600;

        return $server->serve('documents', 'big.txt', $expires, $signer->sign('documents', 'big.txt', $expires), $method, ['Accept-Encoding' => 'gzip']);
    }
}

final readonly class FileServerWithoutZlib extends FileServer
{
    protected function isZlibAvailable(): bool
    {
        return false;
    }
}
