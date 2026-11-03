<?php

namespace App\Services\Ocr;

use App\Support\ArabicTextNormalizer;

/**
 * Units + dialect size words (ونص، نص، وربع، number words, fractions).
 */
class SizeDialectParser
{
    /**
     * @return array{0:?float,1:?float,2:?string,3:list<string>} size, size2, unit, notes
     */
    public function parse(string $normalized): array
    {
        $notes = [];
        $unit = $this->detectUnit($normalized);
        $text = $normalized;

        // Fractions as standalone tokens.
        foreach ((array) config('ocr_lexicon.size_words.fractions', []) as $token => $value) {
            $t = ArabicTextNormalizer::normalize((string) $token);
            if ($t !== '' && preg_match('/(?:^|\s)'.preg_quote($t, '/').'(?:\s|$)/u', $text)) {
                return [(float) $value, null, $unit ?? 'in', $notes];
            }
        }

        // Number words (واحد، اتنين، زوز…).
        foreach ((array) config('ocr_lexicon.size_words.number_words', []) as $word => $value) {
            $w = ArabicTextNormalizer::normalize((string) $word);
            if ($w === '') {
                continue;
            }
            if (preg_match('/(?:^|\s)'.preg_quote($w, '/').'(?:\s|$)/u', $text)) {
                $confirmed = (bool) (config('ocr_lexicon.size_words.number_words_confirmed.'.$word) ?? false);
                if (! $confirmed) {
                    $notes[] = 'unconfirmed_number_word';
                }
                $size = (float) $value;
                $size = $this->applyHalfQuarter($text, $size, $notes, afterWord: $w);

                return [$size, null, $unit ?? 'in', $notes];
            }
        }

        // Digits with optional ونص / وربع.
        if (preg_match_all('/([0-9]+(?:\.[0-9]+)?)/u', $text, $m)) {
            $nums = array_map('floatval', $m[1]);
            if ($nums === []) {
                return [null, null, $unit, $notes];
            }

            // "1 ونص" / "1 و نص"
            $size = $nums[0];
            $size = $this->applyHalfQuarter($text, $size, $notes);

            // Bare "نص" without leading digit already handled; if text is only نص:
            foreach ((array) config('ocr_lexicon.size_words.half', []) as $half) {
                $h = ArabicTextNormalizer::normalize((string) $half);
                if ($h !== '' && preg_match('/(?:^|\s)'.preg_quote($h, '/').'(?:\s|$)/u', $text)
                    && ! $this->hasHalfAdd($text)
                    && count($nums) === 0) {
                    return [0.5, null, $unit ?? 'in', $notes];
                }
            }

            $size2 = $nums[1] ?? null;

            return [$size, $size2, $unit, $notes];
        }

        // Bare half with no digits.
        foreach ((array) config('ocr_lexicon.size_words.half', []) as $half) {
            $h = ArabicTextNormalizer::normalize((string) $half);
            if ($h !== '' && preg_match('/(?:^|\s)'.preg_quote($h, '/').'(?:\s|$)/u', $text) && ! $this->hasHalfAdd($text)) {
                return [0.5, null, $unit ?? 'in', $notes];
            }
        }

        return [null, null, $unit, $notes];
    }

    public function detectUnit(string $normalized): ?string
    {
        foreach ((array) config('ocr_lexicon.units.in', []) as $w) {
            $t = ArabicTextNormalizer::normalize((string) $w);
            if ($t !== '' && (str_contains($normalized, $t) || preg_match('/(?:^|\s)'.preg_quote($t, '/').'(?:\s|$)/u', $normalized))) {
                return 'in';
            }
        }
        // Legacy
        if (preg_match('/بوص/u', $normalized) || preg_match('/\binch\b/i', $normalized) || preg_match('/\bin\b/i', $normalized)) {
            return 'in';
        }
        foreach ((array) config('ocr_lexicon.units.mm', []) as $w) {
            $t = ArabicTextNormalizer::normalize((string) $w);
            if ($t !== '' && str_contains($normalized, $t)) {
                return 'mm';
            }
        }
        if (preg_match('/\bmm\b|ملم|مليم/u', $normalized)) {
            return 'mm';
        }

        return null;
    }

    /**
     * @param  list<string>  $notes
     */
    protected function applyHalfQuarter(string $text, float $size, array &$notes, ?string $afterWord = null): float
    {
        if ($this->hasHalfAdd($text)) {
            return $size + 0.5;
        }
        if ($this->hasQuarterAdd($text)) {
            return $size + 0.25;
        }

        return $size;
    }

    protected function hasHalfAdd(string $text): bool
    {
        foreach ((array) config('ocr_lexicon.size_words.half_add', []) as $w) {
            $t = ArabicTextNormalizer::normalize((string) $w);
            if ($t !== '' && str_contains($text, $t)) {
                return true;
            }
        }

        return false;
    }

    protected function hasQuarterAdd(string $text): bool
    {
        foreach ((array) config('ocr_lexicon.size_words.quarter_add', []) as $w) {
            $t = ArabicTextNormalizer::normalize((string) $w);
            if ($t !== '' && str_contains($text, $t)) {
                return true;
            }
        }

        return false;
    }
}
