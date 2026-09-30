<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalize rich-text / HTML fields for mobile JSON APIs.
 * Filament RichEditor stores <p>…</p> etc.; apps must receive plain readable text.
 */
final class ApiText
{
    /**
     * Strip HTML tags, decode entities, collapse whitespace.
     * Empty / whitespace-only input → null (or '' when $emptyString is true).
     */
    public static function plain(mixed $value, bool $emptyString = false): ?string
    {
        if ($value === null) {
            return $emptyString ? '' : null;
        }

        $html = is_string($value) ? $value : (string) $value;

        if (trim($html) === '') {
            return $emptyString ? '' : null;
        }

        // Convert common block breaks to spaces before stripping
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<\/\s*p\s*>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<\/\s*li\s*>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<\/\s*h[1-6]\s*>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<\/\s*div\s*>/i', ' ', $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Remove leftover stray angle brackets / empty tag leftovers
        $text = preg_replace('/[<>]+/', '', $text) ?? $text;
        $text = Str::of($text)->squish()->toString();

        if ($text === '') {
            return $emptyString ? '' : null;
        }

        return $text;
    }

    /** Like plain() but always returns a string (never null). */
    public static function plainString(mixed $value): string
    {
        return (string) (self::plain($value, true) ?? '');
    }
}
