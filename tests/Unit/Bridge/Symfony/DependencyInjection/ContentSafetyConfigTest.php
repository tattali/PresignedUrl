<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tattali\PresignedUrl\Bridge\Symfony\DependencyInjection\PresignedUrlExtension;
use Tattali\PresignedUrl\Config\Config;

final class ContentSafetyConfigTest extends TestCase
{
    #[Test]
    public function it_defaults_allow_unsafe_inline_to_false(): void
    {
        $config = $this->buildConfig([
            'secret' => 'test-secret-0123456789abcdef0123456789',
            'base_url' => 'https://example.com',
        ]);

        self::assertFalse($config->serving->allowUnsafeInline);
    }

    #[Test]
    public function it_passes_allow_unsafe_inline_through(): void
    {
        $config = $this->buildConfig([
            'secret' => 'test-secret-0123456789abcdef0123456789',
            'base_url' => 'https://example.com',
            'serving' => [
                'content_disposition' => 'attachment',
                'allow_unsafe_inline' => true,
            ],
        ]);

        self::assertTrue($config->serving->allowUnsafeInline);
        self::assertSame('attachment', $config->serving->contentDisposition);
        self::assertTrue($config->serving->compression->enabled);
    }

    #[Test]
    public function it_rejects_non_boolean_allow_unsafe_inline(): void
    {
        $this->expectException(InvalidTypeException::class);

        $this->buildConfig([
            'secret' => 'test-secret-0123456789abcdef0123456789',
            'base_url' => 'https://example.com',
            'serving' => ['allow_unsafe_inline' => 'yes'],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildConfig(array $config): Config
    {
        $container = new ContainerBuilder();
        (new PresignedUrlExtension())->load([$config], $container);
        $container->getDefinition(Config::class)->setPublic(true);
        $container->compile();

        $built = $container->get(Config::class);
        self::assertInstanceOf(Config::class, $built);

        return $built;
    }
}
