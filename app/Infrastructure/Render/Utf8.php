<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

/**
 * Normalises untrusted text bytes to UTF-8 for display: BOMs are honoured,
 * valid UTF-8 is kept as is, and anything else is read as Windows-1256
 * (Arabic) when it looks Arabic, else Windows-1252.
 */
final class Utf8
{
    public static function normalise(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            return self::clean((string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE'));
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            return self::clean((string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE'));
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return self::clean($bytes);
        }

        $arabic = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1256');
        $latin = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');

        // Windows-1256 maps the 0xC1-0xDF / 0xE1-0xED range to Arabic letters.
        $arabicLetters = preg_match_all('/\p{Arabic}/u', $arabic);
        $threshold = max(1, (int) (mb_strlen($arabic) * 0.2));

        return self::clean($arabicLetters !== false && $arabicLetters >= $threshold ? $arabic : $latin);
    }

    private static function clean(string $text): string
    {
        // NUL and other C0 controls (except tab / newline / CR) are noise
        // in a text view and can confuse downstream consumers.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    }
}
