<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
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

final class FileServerCompressionTest extends TestCase
{
    private string $tempDir;
    private string $content;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-compression-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->content = str_repeat('Hello compressible world. ', 100);
        file_put_contents($this->tempDir . '/big.txt', $this->content);
        file_put_contents($this->tempDir . '/small.txt', 'tiny');
        file_put_contents($this->tempDir . '/data.bin', str_repeat("\x00\x01\x02\xff", 500));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tempDir);
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $file, array $headers = [], string $method = 'GET', bool $enabled = true): FileResponse
    {
        $config = new Config(
            secret: 'test-secret-0123456789abcdef0123456789',
            baseUrl: 'https://cdn.example.com',
            serving: new ServingConfig(compression: new CompressionConfig(enabled: $enabled, minSize: 100)),
        );
        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));
        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;

        return $server->serve('documents', $file, $expires, $signer->sign('documents', $file, $expires), $method, $headers);
    }

    private function bodyOf(FileResponse $r): string
    {
        $b = $r->getBody();

        return is_resource($b) ? (string) stream_get_contents($b) : (string) $b;
    }

    #[Test]
    public function no_accept_encoding_gives_identity_with_vary(): void
    {
        $r = $this->get('big.txt');

        self::assertSame(200, $r->getStatusCode());
        self::assertNull($r->getHeader('Content-Encoding'));
        self::assertSame((string) strlen($this->content), $r->getHeader('Content-Length'));
        self::assertSame('Accept-Encoding', $r->getHeader('Vary'));
        self::assertSame($this->content, $this->bodyOf($r));
    }

    #[Test]
    public function gzip_is_declared_and_consistent(): void
    {
        $identity = $this->get('big.txt');
        $r = $this->get('big.txt', ['Accept-Encoding' => 'gzip']);
        $body = $this->bodyOf($r);

        self::assertSame('gzip', $r->getHeader('Content-Encoding'));
        self::assertSame($this->content, gzdecode($body));
        self::assertSame((string) strlen($body), $r->getHeader('Content-Length'));
        self::assertSame('Accept-Encoding', $r->getHeader('Vary'));
        self::assertNotSame($identity->getHeader('ETag'), $r->getHeader('ETag'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function encodings(): array
    {
        return [
            'q zero' => ['gzip;q=0', false],
            'q zero spaced' => ['gzip; q=0.0, deflate', false],
            'wildcard' => ['*', true],
            'wildcard but gzip refused' => ['*, gzip;q=0', false],
            'deflate br' => ['deflate, br', false],
            'x-gzip' => ['x-gzip', true],
            'mixed case' => ['GZip;q=0.5, br', true],
            'empty' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('encodings')]
    public function accept_encoding_is_parsed(string $header, bool $expectGzip): void
    {
        $r = $this->get('big.txt', ['Accept-Encoding' => $header]);

        self::assertSame($expectGzip ? 'gzip' : null, $r->getHeader('Content-Encoding'));
        if (!$expectGzip) {
            self::assertSame($this->content, $this->bodyOf($r));
        }
    }

    #[Test]
    public function lowercase_header_key_works(): void
    {
        $r = $this->get('big.txt', ['accept-encoding' => 'gzip']);

        self::assertSame('gzip', $r->getHeader('Content-Encoding'));
    }

    #[Test]
    public function small_or_non_compressible_files_have_no_vary(): void
    {
        foreach (['small.txt', 'data.bin'] as $file) {
            $r = $this->get($file, ['Accept-Encoding' => 'gzip']);

            self::assertNull($r->getHeader('Content-Encoding'), $file);
            self::assertNull($r->getHeader('Vary'), $file);
        }
    }

    #[Test]
    public function disabled_compression_has_no_vary(): void
    {
        $r = $this->get('big.txt', ['Accept-Encoding' => 'gzip'], 'GET', false);

        self::assertNull($r->getHeader('Content-Encoding'));
        self::assertNull($r->getHeader('Vary'));
    }

    #[Test]
    public function range_requests_are_never_gzipped(): void
    {
        $r = $this->get('big.txt', ['Accept-Encoding' => 'gzip', 'Range' => 'bytes=0-9']);

        self::assertSame(206, $r->getStatusCode());
        self::assertNull($r->getHeader('Content-Encoding'));
        self::assertSame('10', $r->getHeader('Content-Length'));
        self::assertSame(substr($this->content, 0, 10), $this->bodyOf($r));
    }

    #[Test]
    public function head_is_identity_with_vary(): void
    {
        $r = $this->get('big.txt', ['Accept-Encoding' => 'gzip'], 'HEAD');

        self::assertSame(200, $r->getStatusCode());
        self::assertFalse($r->hasBody());
        self::assertNull($r->getHeader('Content-Encoding'));
        self::assertSame((string) strlen($this->content), $r->getHeader('Content-Length'));
        self::assertSame('Accept-Encoding', $r->getHeader('Vary'));
    }

    #[Test]
    public function not_modified_has_vary_and_matches_gzip_etag(): void
    {
        $gz = $this->get('big.txt', ['Accept-Encoding' => 'gzip']);
        $etag = $gz->getHeader('ETag') ?? '';

        $r = $this->get('big.txt', ['Accept-Encoding' => 'gzip', 'If-None-Match' => $etag]);

        self::assertSame(304, $r->getStatusCode());
        self::assertSame('Accept-Encoding', $r->getHeader('Vary'));
        self::assertSame($etag, $r->getHeader('ETag'));

        $identity = $this->get('big.txt');
        $r2 = $this->get('big.txt', ['If-None-Match' => $identity->getHeader('ETag') ?? '']);
        self::assertSame(304, $r2->getStatusCode());
        self::assertSame('Accept-Encoding', $r2->getHeader('Vary'));
    }
}
