<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Server\ContentDisposition;

final class ContentDispositionTest extends TestCase
{
    #[Test]
    public function it_keeps_plain_ascii_names_unchanged(): void
    {
        self::assertSame('inline; filename="test.txt"', ContentDisposition::format('inline', 'test.txt'));
        self::assertSame(
            'attachment; filename="My Report (v2).final-1.pdf"',
            ContentDisposition::format('attachment', 'My Report (v2).final-1.pdf'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function specialNames(): iterable
    {
        yield 'double quote' => [
            'my"file.txt',
            'inline; filename="my_file.txt"; filename*=UTF-8\'\'my%22file.txt',
        ];
        yield 'backslash' => [
            'a\\b.txt',
            'inline; filename="a_b.txt"; filename*=UTF-8\'\'a%5Cb.txt',
        ];
        yield 'percent' => [
            '100%.txt',
            'inline; filename="100_.txt"; filename*=UTF-8\'\'100%25.txt',
        ];
        yield 'latin accents' => [
            'résumé.pdf',
            'inline; filename="r_sum_.pdf"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.pdf',
        ];
        yield 'cjk' => [
            '文件.pdf',
            'inline; filename="__.pdf"; filename*=UTF-8\'\'%E6%96%87%E4%BB%B6.pdf',
        ];
        yield 'quote injection' => [
            'x.txt"; filename="evil.html',
            'inline; filename="x.txt_; filename=_evil.html"; filename*=UTF-8\'\'x.txt%22%3B%20filename%3D%22evil.html',
        ];
    }

    #[Test]
    #[DataProvider('specialNames')]
    public function it_encodes_special_names(string $filename, string $expected): void
    {
        self::assertSame($expected, ContentDisposition::format('inline', $filename));
    }

    #[Test]
    public function it_strips_control_characters_from_both_forms(): void
    {
        $header = ContentDisposition::format('attachment', "evil\r\nSet-Cookie: a=b\x00\x1F\x7F.txt");

        self::assertSame('attachment; filename="evilSet-Cookie: a=b.txt"', $header);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $header);
    }

    #[Test]
    public function it_strips_control_characters_and_still_encodes_non_ascii(): void
    {
        $header = ContentDisposition::format('inline', "r\r\né.txt");

        self::assertSame('inline; filename="r_.txt"; filename*=UTF-8\'\'r%C3%A9.txt', $header);
    }

    #[Test]
    public function it_omits_extended_form_for_invalid_utf8(): void
    {
        $header = ContentDisposition::format('inline', "bad\xFF.txt");

        self::assertSame('inline; filename="bad_.txt"', $header);
    }

    #[Test]
    public function it_produces_only_visible_ascii(): void
    {
        $header = ContentDisposition::format('inline', "日本語 \"quoted\" \\ name\t.txt");

        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $header);
    }
}
