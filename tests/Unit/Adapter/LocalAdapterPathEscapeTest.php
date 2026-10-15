<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Exception\FileNotFoundException;
use Tattali\PresignedUrl\Exception\InvalidPathException;

final class LocalAdapterPathEscapeTest extends TestCase
{
    private string $root;
    private LocalAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/presigned-path-escape-' . uniqid();
        mkdir($this->root . '/files/inside', 0755, true);
        mkdir($this->root . '/files-old', 0755);
        file_put_contents($this->root . '/files-old/secret.txt', 'outside secret');
        file_put_contents($this->root . '/files/inside/safe.txt', 'inside content');
        $this->adapter = new LocalAdapter($this->root . '/files');
    }

    protected function tearDown(): void
    {
        foreach (['file-link.txt', 'dir-link', 'sibling-link', 'inside-file-link.txt', 'inside-dir-link'] as $link) {
            if (is_link($this->root . '/files/' . $link)) {
                unlink($this->root . '/files/' . $link);
            }
        }

        unlink($this->root . '/files/inside/safe.txt');
        unlink($this->root . '/files-old/secret.txt');
        rmdir($this->root . '/files/inside');
        rmdir($this->root . '/files-old');
        rmdir($this->root . '/files');
        rmdir($this->root);
    }

    public static function fileOperations(): array
    {
        return [
            'read' => ['read'],
            'readStream' => ['readStream'],
            'exists' => ['exists'],
            'size' => ['size'],
            'mimeType' => ['mimeType'],
            'lastModified' => ['lastModified'],
        ];
    }

    #[Test]
    #[DataProvider('fileOperations')]
    public function it_rejects_a_file_symlink_to_an_outside_file(string $operation): void
    {
        $this->createSymlink($this->root . '/files-old/secret.txt', $this->root . '/files/file-link.txt');

        $this->expectException(InvalidPathException::class);
        $this->adapter->$operation('file-link.txt');
    }

    #[Test]
    public function it_rejects_a_directory_symlink_to_an_outside_directory(): void
    {
        $this->createSymlink($this->root . '/files-old', $this->root . '/files/dir-link');

        $this->expectException(InvalidPathException::class);
        $this->adapter->read('dir-link/secret.txt');
    }

    #[Test]
    public function it_rejects_a_sibling_directory_with_the_same_base_prefix(): void
    {
        $this->createSymlink($this->root . '/files-old', $this->root . '/files/sibling-link');

        $this->expectException(InvalidPathException::class);
        $this->adapter->read('sibling-link/secret.txt');
    }

    public static function traversalPaths(): array
    {
        return [
            'leading parent' => ['../x'],
            'nested parents' => ['a/../../x'],
            'backslash parents' => ['a\\..\\..\\x'],
        ];
    }

    #[Test]
    #[DataProvider('traversalPaths')]
    public function it_rejects_parent_segments(string $path): void
    {
        $this->expectException(InvalidPathException::class);
        $this->adapter->read($path);
    }

    public static function encodedTraversalPaths(): array
    {
        return [
            'encoded dots' => ['%2e%2e/secret.txt'],
            'encoded dots and slash' => ['%2e%2e%2fsecret.txt'],
            'encoded slash' => ['..%2fsecret.txt'],
        ];
    }

    #[Test]
    #[DataProvider('encodedTraversalPaths')]
    public function it_treats_encoded_traversal_as_a_literal_missing_path(string $path): void
    {
        self::assertFalse($this->adapter->exists($path));
        $this->expectException(FileNotFoundException::class);
        $this->adapter->read($path);
    }

    #[Test]
    public function it_rejects_a_nul_byte_as_an_invalid_path(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->adapter->read("safe\0.txt");
    }

    #[Test]
    public function it_follows_a_file_symlink_to_a_file_inside_the_base(): void
    {
        $this->createSymlink($this->root . '/files/inside/safe.txt', $this->root . '/files/inside-file-link.txt');

        self::assertTrue($this->adapter->exists('inside-file-link.txt'));
        self::assertSame('inside content', $this->adapter->read('inside-file-link.txt'));
    }

    #[Test]
    public function it_follows_a_directory_symlink_to_a_directory_inside_the_base(): void
    {
        $this->createSymlink($this->root . '/files/inside', $this->root . '/files/inside-dir-link');

        self::assertTrue($this->adapter->exists('inside-dir-link/safe.txt'));
        self::assertSame('inside content', $this->adapter->read('inside-dir-link/safe.txt'));
    }

    private function createSymlink(string $target, string $link): void
    {
        if (!function_exists('symlink') || !@symlink($target, $link)) {
            $this->markTestSkipped('Creating symlinks is unavailable.');
        }
    }
}
