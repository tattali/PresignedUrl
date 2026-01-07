<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Adapter;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\FlysystemAdapter;
use Tattali\PresignedUrl\Exception\FileNotFoundException;

final class FlysystemAdapterTest extends TestCase
{
    #[Test]
    public function it_checks_if_file_exists(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('fileExists')
            ->with('test.txt')
            ->willReturn(true);

        $adapter = new FlysystemAdapter($filesystem);

        self::assertTrue($adapter->exists('test.txt'));
    }

    #[Test]
    public function it_returns_false_when_file_does_not_exist(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('fileExists')
            ->with('missing.txt')
            ->willReturn(false);

        $adapter = new FlysystemAdapter($filesystem);

        self::assertFalse($adapter->exists('missing.txt'));
    }

    #[Test]
    public function it_reads_file_content(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('read')
            ->with('test.txt')
            ->willReturn('Hello World');

        $adapter = new FlysystemAdapter($filesystem);

        self::assertSame('Hello World', $adapter->read('test.txt'));
    }

    #[Test]
    public function it_throws_on_read_failure(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('read')
            ->with('missing.txt')
            ->willThrowException(UnableToReadFile::fromLocation('missing.txt'));

        $adapter = new FlysystemAdapter($filesystem);

        $this->expectException(FileNotFoundException::class);
        $adapter->read('missing.txt');
    }

    #[Test]
    public function it_reads_file_as_stream(): void
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'Hello World');
        rewind($stream);

        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('readStream')
            ->with('test.txt')
            ->willReturn($stream);

        $adapter = new FlysystemAdapter($filesystem);
        $result = $adapter->readStream('test.txt');

        self::assertIsResource($result);
        self::assertSame('Hello World', stream_get_contents($result));

        fclose($result);
    }

    #[Test]
    public function it_throws_on_read_stream_failure(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('readStream')
            ->with('missing.txt')
            ->willThrowException(UnableToReadFile::fromLocation('missing.txt'));

        $adapter = new FlysystemAdapter($filesystem);

        $this->expectException(FileNotFoundException::class);
        $adapter->readStream('missing.txt');
    }

    #[Test]
    public function it_returns_file_size(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('fileSize')
            ->with('test.txt')
            ->willReturn(1024);

        $adapter = new FlysystemAdapter($filesystem);

        self::assertSame(1024, $adapter->size('test.txt'));
    }

    #[Test]
    public function it_throws_on_size_failure(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('fileSize')
            ->with('missing.txt')
            ->willThrowException(new \RuntimeException('File not found'));

        $adapter = new FlysystemAdapter($filesystem);

        $this->expectException(FileNotFoundException::class);
        $adapter->size('missing.txt');
    }

    #[Test]
    public function it_returns_mime_type(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('mimeType')
            ->with('test.pdf')
            ->willReturn('application/pdf');

        $adapter = new FlysystemAdapter($filesystem);

        self::assertSame('application/pdf', $adapter->mimeType('test.pdf'));
    }

    #[Test]
    public function it_returns_default_mime_type_on_failure(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('mimeType')
            ->with('unknown')
            ->willThrowException(new \RuntimeException('Cannot detect'));

        $adapter = new FlysystemAdapter($filesystem);

        self::assertSame('application/octet-stream', $adapter->mimeType('unknown'));
    }

    #[Test]
    public function it_returns_last_modified(): void
    {
        $timestamp = time();

        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('lastModified')
            ->with('test.txt')
            ->willReturn($timestamp);

        $adapter = new FlysystemAdapter($filesystem);

        self::assertSame($timestamp, $adapter->lastModified('test.txt'));
    }

    #[Test]
    public function it_returns_zero_on_last_modified_failure(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->once())
            ->method('lastModified')
            ->with('missing.txt')
            ->willThrowException(new \RuntimeException('File not found'));

        $adapter = new FlysystemAdapter($filesystem);

        self::assertSame(0, $adapter->lastModified('missing.txt'));
    }

    #[Test]
    public function it_does_not_support_native_presigned_urls(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $adapter = new FlysystemAdapter($filesystem);

        self::assertFalse($adapter->supportsNativePresignedUrl());
        self::assertNull($adapter->nativePresignedUrl('test.txt', time() + 3600));
    }
}
