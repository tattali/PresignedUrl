<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Adapter\LocalAdapter;
use Tattali\PresignedUrl\Config\Config;
use Tattali\PresignedUrl\Server\FileServer;
use Tattali\PresignedUrl\Signer\HmacSigner;
use Tattali\PresignedUrl\Storage\Storage;

final class StorageLeadingSlashTest extends TestCase
{
    private string $tempDir;
    private Storage $storage;
    private FileServer $server;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/presigned-leading-slash-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/file.txt', 'Hello World');

        $config = new Config(secret: 'test-secret-0123456789abcdef0123456789', baseUrl: 'https://cdn.example.com');
        $signer = new HmacSigner($config->secret, $config->signature);
        $this->storage = new Storage($config, $signer);
        $this->storage->addBucket('bucket', new LocalAdapter($this->tempDir));
        $this->server = new FileServer($this->storage, $signer, $config);
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/file.txt');
        @rmdir($this->tempDir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return [
            'no slash' => ['file.txt'],
            'one slash' => ['/file.txt'],
            'two slashes' => ['//file.txt'],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function generated_url_is_served(string $path): void
    {
        $url = $this->storage->temporaryUrl('bucket', $path, 3600);

        $response = $this->server->serveFromRequest(
            (string) parse_url($url, PHP_URL_PATH),
            $this->query($url),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello World', $this->body($response->getBody()));
    }

    #[Test]
    public function slash_variants_produce_the_same_url(): void
    {
        $expires = new DateTimeImmutable('+1 hour');

        $plain = $this->storage->temporaryUrl('bucket', 'file.txt', $expires);

        self::assertSame($plain, $this->storage->temporaryUrl('bucket', '/file.txt', $expires));
        self::assertSame($plain, $this->storage->temporaryUrl('bucket', '//file.txt', $expires));
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_map(static fn(mixed $v): string => (string) $v, $query);
    }

    private function body(mixed $body): string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return (string) $body;
    }
}
