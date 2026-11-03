<?php

namespace App\Support;

/**
 * Display-safe Unicode helpers (NFKC only — does not alter Arabic spelling).
 */
final class UnicodeText
{
    /**
     * Collapse Arabic presentation forms (U+FB50–U+FDFF / U+FE70–U+FEFF) to standard letters.
     */
    public static function nfkc(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        return ArabicTextNormalizer::nfkc($text);
    }

    public static function needsNfkc(string $text): bool
    {
        $normalized = self::nfkc($text);

        return $normalized !== '' && $normalized !== $text;
    }
}
