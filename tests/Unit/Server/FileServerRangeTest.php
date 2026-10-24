<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Server\FileResponse;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

final class FileServerRangeTest extends TestCase
{
    private string $tempDir;
    private FileServer $server;
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-range-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $config = new Config(secret: 'test-secret-0123456789abcdef0123456789', baseUrl: 'https://cdn.example.com');
        $this->signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $this->signer);
        $storage->addBucket('documents', new LocalAdapter($this->tempDir));
        $this->server = new FileServer($storage, $this->signer, $config);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function suffix_range_returns_last_bytes(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=-5']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 6-10/11', $response->getHeader('Content-Range'));
        self::assertSame('5', $response->getHeader('Content-Length'));
        self::assertSame('World', $this->body($response));
    }

    #[Test]
    public function suffix_larger_than_file_returns_entire_file_as_partial(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=-500']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 0-10/11', $response->getHeader('Content-Range'));
        self::assertSame('11', $response->getHeader('Content-Length'));
        self::assertSame('Hello World', $this->body($response));
    }

    #[Test]
    public function start_beyond_file_returns_416_with_validators(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=5000-']);

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */11', $response->getHeader('Content-Range'));
        self::assertSame('0', $response->getHeader('Content-Length'));
        self::assertNotNull($response->getHeader('ETag'));
        self::assertNotNull($response->getHeader('Last-Modified'));
        self::assertSame('bytes', $response->getHeader('Accept-Ranges'));
        self::assertSame('', $this->body($response));
    }

    #[Test]
    public function zero_suffix_is_unsatisfiable(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=-0']);

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */11', $response->getHeader('Content-Range'));
    }

    #[Test]
    public function range_on_empty_file_is_unsatisfiable(): void
    {
        file_put_contents($this->tempDir . '/test.txt', '');

        $response = $this->serve(['Range' => 'bytes=0-']);

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */0', $response->getHeader('Content-Range'));
        self::assertSame('0', $response->getHeader('Content-Length'));
    }

    #[Test]
    public function partial_body_is_a_resource_with_exact_bytes(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=2-6']);

        self::assertSame(206, $response->getStatusCode());
        self::assertTrue(is_resource($response->getBody()));
        self::assertSame('llo W', $this->body($response));
    }

    #[Test]
    public function short_read_stream_still_returns_entire_range(): void
    {
        $content = str_repeat('0123456789', 4);
        file_put_contents($this->tempDir . '/test.txt', $content);
        ShortReadStream::$filePath = $this->tempDir . '/test.txt';
        stream_wrapper_register('short-read', ShortReadStream::class);

        try {
            $config = new Config(secret: 'test-secret-0123456789abcdef0123456789', baseUrl: 'https://cdn.example.com');
            $storage = new Storage($config, $this->signer);
            $storage->addBucket('documents', new ShortReadLocalAdapter($this->tempDir));
            $this->server = new FileServer($storage, $this->signer, $config);

            $response = $this->serve(['Range' => 'bytes=5-34']);

            self::assertSame(206, $response->getStatusCode());
            self::assertSame('30', $response->getHeader('Content-Length'));
            self::assertSame('567890123456789012345678901234', $this->body($response));
        } finally {
            stream_wrapper_unregister('short-read');
        }
    }

    #[Test]
    public function non_seekable_stream_skips_to_range_start(): void
    {
        file_put_contents($this->tempDir . '/test.txt', str_repeat('0123456789', 4));
        ShortReadStream::$filePath = $this->tempDir . '/test.txt';
        ShortReadStream::$allowSeek = false;
        stream_wrapper_register('short-read', ShortReadStream::class);

        try {
            $config = new Config(secret: 'test-secret-0123456789abcdef0123456789', baseUrl: 'https://cdn.example.com');
            $storage = new Storage($config, $this->signer);
            $storage->addBucket('documents', new ShortReadLocalAdapter($this->tempDir));
            $this->server = new FileServer($storage, $this->signer, $config);

            $response = $this->serve(['Range' => 'bytes=12-21']);

            self::assertSame(206, $response->getStatusCode());
            self::assertSame('2345678901', $this->body($response));
        } finally {
            stream_wrapper_unregister('short-read');
            ShortReadStream::$allowSeek = true;
        }
    }

    #[Test]
    public function matching_if_range_etag_allows_partial_response(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');
        $etag = $this->serve()->getHeader('ETag');

        $response = $this->serve(['Range' => 'bytes=0-4', 'If-Range' => $etag ?? '']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('Hello', $this->body($response));
    }

    #[Test]
    public function different_if_range_etag_returns_full_body(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=0-4', 'If-Range' => '"other"']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('11', $response->getHeader('Content-Length'));
        self::assertSame('Hello World', $this->body($response));
    }

    #[Test]
    public function weak_if_range_etag_returns_full_body(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');
        $etag = $this->serve()->getHeader('ETag');

        $response = $this->serve(['Range' => 'bytes=0-4', 'If-Range' => 'W/' . $etag]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello World', $this->body($response));
    }

    #[Test]
    public function old_if_range_date_returns_full_body(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=0-4', 'If-Range' => 'Wed, 21 Oct 2015 07:28:00 GMT']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello World', $this->body($response));
    }

    #[Test]
    public function matching_if_range_date_allows_partial_response(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');
        $lastModified = $this->serve()->getHeader('Last-Modified');

        $response = $this->serve(['Range' => 'bytes=0-4', 'If-Range' => $lastModified ?? '']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('Hello', $this->body($response));
    }

    #[Test]
    public function multiple_ranges_are_ignored(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=0-1,4-5']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello World', $this->body($response));
    }

    #[Test]
    public function malformed_ranges_are_ignored(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        foreach (['bytes=foo-4', 'bytes=5-2', 'items=0-2'] as $range) {
            $response = $this->serve(['Range' => $range]);
            self::assertSame(200, $response->getStatusCode(), $range);
            self::assertSame('Hello World', $this->body($response), $range);
        }
    }

    #[Test]
    public function head_with_range_keeps_full_response_headers(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serve(['Range' => 'bytes=0-4'], 'HEAD');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('11', $response->getHeader('Content-Length'));
        self::assertFalse($response->hasBody());
    }

    /** @param array<string, string> $headers */
    private function serve(array $headers = [], string $method = 'GET'): FileResponse
    {
        $expires = time() + 3600;
        $signature = $this->signer->sign('documents', 'test.txt', $expires);

        return $this->server->serve('documents', 'test.txt', $expires, $signature, $method, $headers);
    }

    private function body(FileResponse $response): string
    {
        $body = $response->getBody();
        if (is_resource($body)) {
            $content = stream_get_contents($body);
            fclose($body);

            return $content === false ? '' : $content;
        }

        return $body ?? '';
    }
}

readonly class ShortReadLocalAdapter extends LocalAdapter
{
    public function readStream(string $path)
    {
        $stream = fopen('short-read://file', 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open short-read stream');
        }

        return $stream;
    }
}

final class ShortReadStream
{
    public static string $filePath;
    public static bool $allowSeek = true;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $handle = fopen(self::$filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, min($count, 8));
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return self::$allowSeek && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }
}
