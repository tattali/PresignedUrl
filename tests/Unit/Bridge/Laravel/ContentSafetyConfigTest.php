<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Laravel;

use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Bridge\Laravel\PresignedUrlServiceProvider;
use Tattali\PresignedUrl\Config\Config;

final class ContentSafetyConfigTest extends TestCase
{
    #[Test]
    public function it_ships_allow_unsafe_inline_disabled_in_published_config(): void
    {
        // The published file calls env(), which needs a booted Laravel app, so read it as text.
        $published = file_get_contents(dirname(__DIR__, 4) . '/src/Bridge/Laravel/config/presigned-url.php');

        self::assertIsString($published);
        self::assertMatchesRegularExpression(
            "/'serving' => \\[[^\\]]*'allow_unsafe_inline' => false,/s",
            $published,
        );
    }

    #[Test]
    public function it_defaults_allow_unsafe_inline_to_false_when_key_is_missing(): void
    {
        $config = $this->buildConfig(['serving' => ['content_disposition' => 'inline']]);

        self::assertFalse($config->serving->allowUnsafeInline);
    }

    #[Test]
    public function it_passes_allow_unsafe_inline_false_through(): void
    {
        $config = $this->buildConfig(['serving' => ['allow_unsafe_inline' => false]]);

        self::assertFalse($config->serving->allowUnsafeInline);
    }

    #[Test]
    public function it_passes_allow_unsafe_inline_true_through(): void
    {
        $config = $this->buildConfig(['serving' => [
            'content_disposition' => 'attachment',
            'allow_unsafe_inline' => true,
        ]]);

        self::assertTrue($config->serving->allowUnsafeInline);
        self::assertSame('attachment', $config->serving->contentDisposition);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildConfig(array $overrides): Config
    {
        $provider = new ContentSafetyTestServiceProvider(new Container());

        return $provider->exposeBuildConfig(array_merge([
            'secret' => 'test-secret-0123456789abcdef0123456789',
            'base_url' => 'https://cdn.example.com',
        ], $overrides));
    }
}

class ContentSafetyTestServiceProvider extends PresignedUrlServiceProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function exposeBuildConfig(array $config): Config
    {
        return $this->buildConfig($config);
    }
}
