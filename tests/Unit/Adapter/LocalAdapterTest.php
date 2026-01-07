<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Exception\FileNotFoundException;
use Tattali\PresignedUrl\Exception\InvalidPathException;

final class LocalAdapterTest extends TestCase
{
    private string $tempDir;
    private LocalAdapter $adapter;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-storage-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->adapter = new LocalAdapter($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_checks_if_file_exists(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'content');

        self::assertTrue($this->adapter->exists('test.txt'));
        self::assertFalse($this->adapter->exists('nonexistent.txt'));
    }

    #[Test]
    public function it_reads_file_content(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        self::assertSame('Hello World', $this->adapter->read('test.txt'));
    }

    #[Test]
    public function it_reads_file_as_stream(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Stream Content');

        $stream = $this->adapter->readStream('test.txt');

        self::assertIsResource($stream);
        self::assertSame('Stream Content', stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function it_returns_file_size(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello');

        self::assertSame(5, $this->adapter->size('test.txt'));
    }

    #[Test]
    public function it_returns_mime_type(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello');

        self::assertSame('text/plain', $this->adapter->mimeType('test.txt'));
    }

    #[Test]
    public function it_returns_last_modified(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello');
        $expected = filemtime($this->tempDir . '/test.txt');

        self::assertSame($expected, $this->adapter->lastModified('test.txt'));
    }

    #[Test]
    public function it_throws_on_path_traversal_with_double_dots(): void
    {
        $this->expectException(InvalidPathException::class);

        $this->adapter->read('../etc/passwd');
    }

    #[Test]
    public function it_throws_on_file_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->adapter->read('nonexistent.txt');
    }

    #[Test]
    public function it_handles_nested_paths(): void
    {
        mkdir($this->tempDir . '/nested/deep', 0755, true);
        file_put_contents($this->tempDir . '/nested/deep/file.txt', 'Nested');

        self::assertTrue($this->adapter->exists('nested/deep/file.txt'));
        self::assertSame('Nested', $this->adapter->read('nested/deep/file.txt'));
    }

    #[Test]
    public function it_does_not_support_native_presigned_urls(): void
    {
        self::assertFalse($this->adapter->supportsNativePresignedUrl());
        self::assertNull($this->adapter->nativePresignedUrl('test.txt', time() + 3600));
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
