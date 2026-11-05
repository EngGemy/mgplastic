<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class ArabicTextNormalizer
{
    private static bool $loggedMissingIntl = false;

    public static function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = self::nfkc($text);

        // Arabic-Indic / Extended Arabic-Indic digits → Latin
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', // Arabic decimal separator
            '٬' => '',  // Arabic thousands separator
        ]);

        // Western decimal comma between digits (handwriting / locales): 1,5 → 1.5
        $text = preg_replace('/(\d),(\d)/u', '$1.$2', $text) ?? $text;

        // Remove Arabic diacritics (tashkeel) + tatweel
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;

        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        // Multiplication / separators → x; collapse punctuation to space
        $text = str_replace(['×', '*', 'x', 'X', 'ｘ'], ' x ', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s.]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // Strip brand tokens MG / ام جي (detected on raw before normalize; catalog names include them)
        $text = preg_replace('/\bm[\s.\-_\/]*g\b/iu', '', $text) ?? $text;
        $text = preg_replace('/ام\s*\.?\s*جي(?:\s*بلاست)?/u', '', $text) ?? $text;
        $text = preg_replace('/امجي(?:\s*بلاست)?/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function nfkc(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                return $normalized;
            }
        }

        if (! self::$loggedMissingIntl) {
            self::$loggedMissingIntl = true;
            Log::channel('ocr')->warning('[OCR] intl_normalizer_missing', [
                'fallback' => 'presentation_form_map',
            ]);
        }

        return self::presentationFormFallback($text);
    }

    /**
     * Minimal Arabic presentation-form → isolated/standard letter map when intl is absent.
     */
    protected static function presentationFormFallback(string $text): string
    {
        // Common compatibility forms seen in catalog exports (subset; NFKC preferred).
        $map = [
            'ﻛ' => 'ك', 'ﻜ' => 'ك', 'ﻚ' => 'ك', 'ﻙ' => 'ك',
            'ﻮ' => 'و', 'ﻭ' => 'و', 'و' => 'و',
            'ﻉ' => 'ع', 'ﻊ' => 'ع', 'ﻋ' => 'ع', 'ﻌ' => 'ع',
            'ﺑ' => 'ب', 'ﺒ' => 'ب', 'ﺐ' => 'ب', 'ﺏ' => 'ب',
            'ﺻ' => 'ص', 'ﺼ' => 'ص', 'ﺺ' => 'ص', 'ﺹ' => 'ص',
            'ﺔ' => 'ة', 'ﻩ' => 'ه', 'ﻬ' => 'ه', 'ﻫ' => 'ه', 'ﻪ' => 'ه',
            'ﺍ' => 'ا', 'ﺎ' => 'ا',
            'ﻝ' => 'ل', 'ﻠ' => 'ل', 'ﻟ' => 'ل', 'ﻞ' => 'ل',
            'ى' => 'ى', 'ﻯ' => 'ى', 'ﻰ' => 'ى', 'ﻱ' => 'ي', 'ﻲ' => 'ي', 'ﻳ' => 'ي', 'ﻴ' => 'ي',
            'ﻥ' => 'ن', 'ﻨ' => 'ن', 'ﻧ' => 'ن', 'ﻦ' => 'ن',
            'ﻡ' => 'م', 'ﻤ' => 'م', 'ﻣ' => 'م', 'ﻢ' => 'م',
            'ﺕ' => 'ت', 'ﺘ' => 'ت', 'ﺗ' => 'ت', 'ﺖ' => 'ت',
            'ﺙ' => 'ث', 'ﺜ' => 'ث', 'ﺛ' => 'ث', 'ﺚ' => 'ث',
            'ﺝ' => 'ج', 'ﺠ' => 'ج', 'ﺟ' => 'ج', 'ﺞ' => 'ج',
            'ﺡ' => 'ح', 'ﺤ' => 'ح', 'ﺣ' => 'ح', 'ﺢ' => 'ح',
            'ﺥ' => 'خ', 'ﺨ' => 'خ', 'ﺧ' => 'خ', 'ﺦ' => 'خ',
            'ﺩ' => 'د', 'ﺪ' => 'د',
            'ﺫ' => 'ذ', 'ﺬ' => 'ذ',
            'ﺭ' => 'ر', 'ﺮ' => 'ر',
            'ﺯ' => 'ز', 'ﺰ' => 'ز',
            'ﺱ' => 'س', 'ﺴ' => 'س', 'ﺳ' => 'س', 'ﺲ' => 'س',
            'ﺵ' => 'ش', 'ﺸ' => 'ش', 'ﺷ' => 'ش', 'ﺶ' => 'ش',
            'ﺽ' => 'ض', 'ﻀ' => 'ض', 'ﺿ' => 'ض', 'ﺾ' => 'ض',
            'ﻁ' => 'ط', 'ﻄ' => 'ط', 'ﻃ' => 'ط', 'ﻂ' => 'ط',
            'ﻅ' => 'ظ', 'ﻈ' => 'ظ', 'ﻇ' => 'ظ', 'ﻆ' => 'ظ',
            'ﻍ' => 'غ', 'ﻐ' => 'غ', 'ﻏ' => 'غ', 'ﻎ' => 'غ',
            'ﻑ' => 'ف', 'ﻔ' => 'ف', 'ﻓ' => 'ف', 'ﻒ' => 'ف',
            'ﻕ' => 'ق', 'ﻘ' => 'ق', 'ﻗ' => 'ق', 'ﻖ' => 'ق',
            'ﻯ' => 'ى', 'ﻰ' => 'ى',
            'ﻻ' => 'لا', 'ﻼ' => 'لا', 'ﻹ' => 'لا', 'ﻸ' => 'لا', 'ﻹ' => 'لإ', 'ﻷ' => 'لأ',
            'اﱃ' => 'الى', 'ﱃ' => 'لى',
        ];

        return strtr($text, $map);
    }
}
