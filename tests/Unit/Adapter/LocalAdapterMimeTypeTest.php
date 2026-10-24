<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;

final class LocalAdapterMimeTypeTest extends TestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $tempDir;
    private LocalAdapter $adapter;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-mime-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->adapter = new LocalAdapter($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->tempDir) ?: [], ['.', '..']) as $file) {
            unlink($this->tempDir . '/' . $file);
        }

        rmdir($this->tempDir);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function extensionFallbackProvider(): iterable
    {
        yield 'js' => ['app.js', 'function f() { return 1; }', 'text/javascript'];
        yield 'js that libmagic takes for C++' => ['app-class.js', "class A {\n  constructor() { this.x = 1; }\n}\n", 'text/javascript'];
        yield 'mjs' => ['app.mjs', "const a = 1;\n", 'text/javascript'];
        yield 'upper-case extension' => ['APP.JS', 'function f() { return 1; }', 'text/javascript'];
        yield 'css' => ['site.css', 'body { color: red; }', 'text/css'];
        yield 'empty css' => ['empty.css', '', 'text/css'];
        yield 'svg without prolog' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>', 'image/svg+xml'];
        yield 'json' => ['data.json', '{"a": 1}', 'application/json'];
        yield 'txt' => ['notes.txt', 'plain words', 'text/plain'];
    }

    #[Test]
    #[DataProvider('extensionFallbackProvider')]
    public function it_uses_the_extension_when_detection_is_generic(string $name, string $content, string $expected): void
    {
        file_put_contents($this->tempDir . '/' . $name, $content);

        self::assertSame($expected, $this->adapter->mimeType($name));
    }

    #[Test]
    public function it_keeps_text_plain_for_an_unknown_extension(): void
    {
        file_put_contents($this->tempDir . '/notes.unknownext', 'plain words');

        self::assertSame('text/plain', $this->adapter->mimeType('notes.unknownext'));
    }

    #[Test]
    public function it_keeps_the_detected_guess_for_an_unknown_extension(): void
    {
        file_put_contents($this->tempDir . '/shape.unknownext', "class A {\n  constructor() { this.x = 1; }\n}\n");

        self::assertSame('text/x-c++', $this->adapter->mimeType('shape.unknownext'));
    }

    #[Test]
    public function it_keeps_text_plain_without_an_extension(): void
    {
        file_put_contents($this->tempDir . '/README', 'plain words');

        self::assertSame('text/plain', $this->adapter->mimeType('README'));
    }

    #[Test]
    public function it_keeps_a_specific_detected_type_over_the_extension(): void
    {
        file_put_contents($this->tempDir . '/image.css', (string) base64_decode(self::PNG_BASE64, true));

        self::assertSame('image/png', $this->adapter->mimeType('image.css'));
    }
}
