<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\AdapterInterface;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\CompressionConfig;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Config\ServingConfig;
use Tattali\PresignedUrl\Server\FileResponse;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

final class FileServerContentSafetyTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-safety-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function it_sends_nosniff_on_get(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serveLocal('test.txt');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    #[Test]
    public function it_sends_nosniff_on_head(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serveLocal('test.txt', method: 'HEAD');

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasBody());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    #[Test]
    public function it_sends_nosniff_on_not_modified(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');
        $etag = $this->serveLocal('test.txt')->getHeader('ETag') ?? '';

        $response = $this->serveLocal('test.txt', headers: ['If-None-Match' => $etag]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    #[Test]
    public function it_sends_nosniff_on_partial_content(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serveLocal('test.txt', headers: ['Range' => 'bytes=0-4']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function riskyFiles(): iterable
    {
        yield 'html' => ['page.html', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'];
        yield 'htm' => ['page.htm', '<html><body><script>alert(1)</script></body></html>'];
        yield 'svg' => ['image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'uppercase svg' => ['IMAGE.SVG', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'xml' => ['feed.xml', '<?xml version="1.0"?><root><child/></root>'];
        yield 'js' => ['app.js', 'alert(1);'];
        yield 'mjs' => ['app.mjs', 'export const a = 1;'];
        yield 'xhtml' => ['page.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"></html>'];
        yield 'txt sniffed as html' => ['notes.txt', '<html><head><script>alert(document.domain)</script></head><body></body></html>'];
    }

    #[Test]
    #[DataProvider('riskyFiles')]
    public function it_forces_attachment_and_sandbox_for_scriptable_content(string $name, string $content): void
    {
        file_put_contents($this->tempDir . '/' . $name, $content);

        $response = $this->serveLocal($name);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('attachment; filename="' . $name . '"', $response->getHeader('Content-Disposition'));
        self::assertSame('sandbox', $response->getHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    #[Test]
    public function it_detects_html_sniffed_by_finfo(): void
    {
        file_put_contents($this->tempDir . '/notes.txt', '<html><head><script>alert(1)</script></head><body></body></html>');

        $response = $this->serveLocal('notes.txt');

        self::assertSame('text/html', $response->getHeader('Content-Type'));
        self::assertSame('attachment; filename="notes.txt"', $response->getHeader('Content-Disposition'));
        self::assertSame('sandbox', $response->getHeader('Content-Security-Policy'));
    }

    #[Test]
    public function it_forces_attachment_and_sandbox_on_head_and_not_modified(): void
    {
        file_put_contents($this->tempDir . '/page.html', '<html><script>alert(1)</script></html>');

        $head = $this->serveLocal('page.html', method: 'HEAD');
        $notModified = $this->serveLocal('page.html', headers: ['If-None-Match' => $head->getHeader('ETag') ?? '']);

        self::assertSame('attachment; filename="page.html"', $head->getHeader('Content-Disposition'));
        self::assertSame('sandbox', $head->getHeader('Content-Security-Policy'));
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('attachment; filename="page.html"', $notModified->getHeader('Content-Disposition'));
        self::assertSame('sandbox', $notModified->getHeader('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function riskyMimeTypes(): iterable
    {
        yield 'text/html with params and mixed case' => ['blob.dat', 'Text/HTML; charset=UTF-8'];
        yield 'application/xhtml+xml' => ['blob.dat', 'application/xhtml+xml'];
        yield 'image/svg+xml' => ['blob.dat', 'image/svg+xml'];
        yield 'text/xml' => ['blob.dat', 'text/xml'];
        yield 'application/xml' => ['blob.dat', 'application/xml'];
        yield 'text/xsl' => ['blob.dat', 'text/xsl'];
        yield 'text/javascript' => ['blob.dat', 'text/javascript'];
        yield 'application/javascript' => ['blob.dat', 'application/javascript'];
        yield 'application/x-javascript' => ['blob.dat', 'application/x-javascript'];
        yield 'application/ecmascript' => ['blob.dat', 'application/ecmascript'];
        yield 'text/ecmascript' => ['blob.dat', 'text/ecmascript'];
        yield 'any +xml suffix' => ['blob.dat', 'application/atom+xml'];
        yield 'extension only, safe mime' => ['style.xsl', 'application/octet-stream'];
        yield 'svgz extension' => ['image.svgz', 'application/gzip'];
        yield 'comma list ending in html' => ['blob.bin', 'text/plain,text/html'];
        yield 'comma list with params' => ['blob.bin', 'text/plain, text/html;charset=utf-8'];
        yield 'multipart x-mixed-replace' => ['blob.bin', 'multipart/x-mixed-replace;boundary=x'];
        yield 'empty content type' => ['blob.bin', ''];
    }

    #[Test]
    #[DataProvider('riskyMimeTypes')]
    public function it_treats_scriptable_mime_types_as_risky(string $name, string $mimeType): void
    {
        $response = $this->serveMemory($name, $mimeType);

        self::assertSame('attachment; filename="' . $name . '"', $response->getHeader('Content-Disposition'));
        self::assertSame('sandbox', $response->getHeader('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeMimeTypes(): iterable
    {
        yield 'pdf' => ['application/pdf'];
        yield 'plain text with charset' => ['text/plain; charset=utf-8'];
        yield 'png' => ['image/png'];
    }

    #[Test]
    #[DataProvider('safeMimeTypes')]
    public function it_keeps_single_safe_media_types_inline(string $mimeType): void
    {
        $response = $this->serveMemory('blob.bin', $mimeType);

        self::assertSame('inline; filename="blob.bin"', $response->getHeader('Content-Disposition'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    #[Test]
    public function it_keeps_configured_inline_disposition_for_plain_text(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serveLocal('test.txt');

        self::assertSame('inline; filename="test.txt"', $response->getHeader('Content-Disposition'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    #[Test]
    public function it_keeps_configured_inline_disposition_for_png(): void
    {
        file_put_contents($this->tempDir . '/pixel.png', base64_decode(self::PNG_1X1, true));

        $response = $this->serveLocal('pixel.png');

        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame('inline; filename="pixel.png"', $response->getHeader('Content-Disposition'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    #[Test]
    public function it_keeps_configured_inline_disposition_for_pdf_without_csp(): void
    {
        $response = $this->serveMemory('doc.pdf', 'application/pdf');

        self::assertSame('inline; filename="doc.pdf"', $response->getHeader('Content-Disposition'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    #[Test]
    public function it_keeps_configured_attachment_disposition(): void
    {
        file_put_contents($this->tempDir . '/test.txt', 'Hello World');

        $response = $this->serveLocal('test.txt', serving: new ServingConfig(contentDisposition: 'attachment'));

        self::assertSame('attachment; filename="test.txt"', $response->getHeader('Content-Disposition'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    #[Test]
    public function it_defaults_allow_unsafe_inline_to_false(): void
    {
        self::assertFalse((new ServingConfig())->allowUnsafeInline);
    }

    #[Test]
    public function it_serves_risky_content_inline_when_unsafe_inline_is_allowed(): void
    {
        file_put_contents($this->tempDir . '/page.html', '<html><script>alert(1)</script></html>');

        $response = $this->serveLocal('page.html', serving: new ServingConfig(allowUnsafeInline: true));

        self::assertSame('inline; filename="page.html"', $response->getHeader('Content-Disposition'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    #[Test]
    public function it_accepts_allow_unsafe_inline_as_last_positional_argument(): void
    {
        $serving = new ServingConfig(3600, 86400, 'private', 'inline', new CompressionConfig(), true);

        self::assertTrue($serving->allowUnsafeInline);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function specialFilenames(): iterable
    {
        yield 'double quote' => ['my"file.txt', 'inline; filename="my_file.txt"; filename*=UTF-8\'\'my%22file.txt'];
        yield 'backslash' => ['a\\b.txt', 'inline; filename="a_b.txt"; filename*=UTF-8\'\'a%5Cb.txt'];
        yield 'crlf' => ["evil\r\nSet-Cookie: x=y.txt", 'inline; filename="evilSet-Cookie: x=y.txt"'];
        yield 'latin accents' => ['résumé.pdf', 'inline; filename="r_sum_.pdf"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.pdf'];
        yield 'cjk' => ['报告.pdf', 'inline; filename="__.pdf"; filename*=UTF-8\'\'%E6%8A%A5%E5%91%8A.pdf'];
        yield 'plain ascii' => ['test.txt', 'inline; filename="test.txt"'];
    }

    #[Test]
    #[DataProvider('specialFilenames')]
    public function it_escapes_filenames_in_content_disposition(string $name, string $expected): void
    {
        $mimeType = str_ends_with($name, '.pdf') ? 'application/pdf' : 'text/plain';

        $response = $this->serveMemory('dir/' . $name, $mimeType);

        self::assertSame($expected, $response->getHeader('Content-Disposition'));
    }

    #[Test]
    public function it_escapes_non_ascii_filenames_from_local_adapter(): void
    {
        file_put_contents($this->tempDir . '/résumé.pdf', '%PDF-1.4 test');

        $response = $this->serveLocal('résumé.pdf');

        self::assertSame(
            'inline; filename="r_sum_.pdf"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.pdf',
            $response->getHeader('Content-Disposition'),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function serveLocal(
        string $path,
        string $method = 'GET',
        array $headers = [],
        ?ServingConfig $serving = null,
    ): FileResponse {
        return $this->serve(new LocalAdapter($this->tempDir), $path, $method, $headers, $serving);
    }

    private function serveMemory(string $path, string $mimeType, ?ServingConfig $serving = null): FileResponse
    {
        return $this->serve(new InMemorySafetyAdapter($path, 'content', $mimeType), $path, 'GET', [], $serving);
    }

    /**
     * @param array<string, string> $headers
     */
    private function serve(
        AdapterInterface $adapter,
        string $path,
        string $method,
        array $headers,
        ?ServingConfig $serving,
    ): FileResponse {
        $config = new Config(
            secret: 'test-secret-0123456789abcdef0123456789',
            baseUrl: 'https://cdn.example.com',
            serving: $serving ?? new ServingConfig(),
        );

        $signer = new HmacSigner($config->secret, $config->signature);
        $storage = new Storage($config, $signer);
        $storage->addBucket('documents', $adapter);
        $server = new FileServer($storage, $signer, $config);

        $expires = time() + 3600;
        $signature = $signer->sign('documents', $path, $expires);

        return $server->serve('documents', $path, $expires, $signature, $method, $headers);
    }
}

final class InMemorySafetyAdapter implements AdapterInterface
{
    public function __construct(
        private readonly string $path,
        private readonly string $content,
        private readonly string $mimeType,
    ) {}

    public function exists(string $path): bool
    {
        return $path === $this->path;
    }

    public function read(string $path): string
    {
        return $this->content;
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $this->content);
        rewind($stream);

        return $stream;
    }

    public function size(string $path): int
    {
        return strlen($this->content);
    }

    public function mimeType(string $path): string
    {
        return $this->mimeType;
    }

    public function lastModified(string $path): int
    {
        return 1700000000;
    }

    public function supportsNativePresignedUrl(): bool
    {
        return false;
    }

    public function nativePresignedUrl(string $path, int $expires): ?string
    {
        return null;
    }
}
