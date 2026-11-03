<?php

namespace App\Services\Ocr\Lexicon;

use App\Support\ArabicTextNormalizer;

/**
 * Light Arabic morphology for OCR tokens: article, leading و, plural/dual suffixes, broken plurals.
 */
class ArabicMorphology
{
    /**
     * Expand a token into lemma candidates (original + stripped forms).
     *
     * @return list<string>
     */
    public function lemmas(string $token): array
    {
        $token = ArabicTextNormalizer::normalize($token);
        if ($token === '') {
            return [];
        }

        $out = [$token];
        $broken = (array) config('ocr_lexicon.broken_plurals', []);
        if (isset($broken[$token])) {
            $out[] = ArabicTextNormalizer::normalize((string) $broken[$token]);
        }

        $stripped = $this->stripAffixes($token);
        foreach ($stripped as $s) {
            $out[] = $s;
            if (isset($broken[$s])) {
                $out[] = ArabicTextNormalizer::normalize((string) $broken[$s]);
            }
        }

        return array_values(array_unique(array_filter($out, fn ($t) => $t !== '')));
    }

    /**
     * @return list<string>
     */
    public function stripAffixes(string $token): array
    {
        $forms = [$token];

        // Leading و (and)
        if (mb_strlen($token) > 3 && str_starts_with($token, 'و') && ! str_starts_with($token, 'واي')) {
            $forms[] = mb_substr($token, 1);
        }

        // Definite article ال
        foreach ($forms as $f) {
            if (mb_strlen($f) > 4 && str_starts_with($f, 'ال')) {
                $forms[] = mb_substr($f, 2);
            }
        }

        // Plural / dual suffixes
        $suffixes = ['ات', 'ين', 'ون', 'ان'];
        $withSuffix = $forms;
        foreach ($withSuffix as $f) {
            foreach ($suffixes as $suf) {
                $len = mb_strlen($suf);
                if (mb_strlen($f) > $len + 2 && str_ends_with($f, $suf)) {
                    $forms[] = mb_substr($f, 0, mb_strlen($f) - $len);
                }
            }
        }

        // Collapse repeated letters (طوبوو → طوبو)
        foreach ($forms as $f) {
            $collapsed = preg_replace('/(.)\1+/u', '$1', $f) ?? $f;
            if ($collapsed !== $f) {
                $forms[] = $collapsed;
            }
        }

        return array_values(array_unique(array_filter($forms, fn ($t) => $t !== '')));
    }
}
