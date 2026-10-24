<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Server;

/**
 * Builds a Content-Disposition header value as described in RFC 6266 and RFC 5987.
 */
final class ContentDisposition
{
    public static function format(string $type, string $filename): string
    {
        $filename = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        $fallback = self::asciiFallback($filename);

        $header = sprintf('%s; filename="%s"', $type, $fallback);

        if ($fallback !== $filename && preg_match('//u', $filename) === 1) {
            $header .= "; filename*=UTF-8''" . rawurlencode($filename);
        }

        return $header;
    }

    /**
     * Replaces every character that cannot appear as-is in the quoted filename parameter.
     */
    private static function asciiFallback(string $filename): string
    {
        $pattern = preg_match('//u', $filename) === 1 ? '/[^\x20-\x7E]/u' : '/[^\x20-\x7E]/';
        $fallback = (string) preg_replace($pattern, '_', $filename);

        return str_replace(['"', '\\', '%', '/'], '_', $fallback);
    }
}
