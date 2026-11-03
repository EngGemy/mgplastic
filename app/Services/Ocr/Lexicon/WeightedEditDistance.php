<?php

namespace App\Services\Ocr\Lexicon;

/**
 * Weighted Levenshtein with cheap substitutions for confusable Arabic/Latin letters.
 */
class WeightedEditDistance
{
    /** @var array<string, string> letter → group id */
    protected array $groupOf = [];

    public function __construct()
    {
        foreach ((array) config('ocr_lexicon.confusable_groups', []) as $i => $group) {
            foreach ((array) $group as $letter) {
                $this->groupOf[(string) $letter] = (string) $i;
            }
        }
    }

    public function distance(string $a, string $b): float
    {
        $a = $this->collapseRepeats($a);
        $b = $this->collapseRepeats($b);

        $aChars = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bChars = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($aChars);
        $m = count($bChars);

        if ($n === 0) {
            return (float) $m;
        }
        if ($m === 0) {
            return (float) $n;
        }

        // Bound: skip clearly different lengths.
        if (abs($n - $m) > 3) {
            return 99.0;
        }

        $prev = [];
        for ($j = 0; $j <= $m; $j++) {
            $prev[$j] = (float) $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            $curr = [0 => (float) $i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $this->subCost($aChars[$i - 1], $bChars[$j - 1]);
                $curr[$j] = min(
                    $prev[$j] + 1.0,
                    $curr[$j - 1] + 1.0,
                    $prev[$j - 1] + $cost
                );
            }
            $prev = $curr;
        }

        return (float) $prev[$m];
    }

    public function thresholdForLength(int $len): float
    {
        $map = (array) config('ocr_lexicon.fuzzy.max_distance', []);
        if (isset($map[$len])) {
            return (float) $map[$len];
        }

        return (float) ($map['default'] ?? 1.15);
    }

    protected function subCost(string $x, string $y): float
    {
        if ($x === $y) {
            return 0.0;
        }

        $gx = $this->groupOf[$x] ?? null;
        $gy = $this->groupOf[$y] ?? null;
        if ($gx !== null && $gx === $gy) {
            return 0.3;
        }

        // Case-insensitive Latin already normalized lower.
        if (strcasecmp($x, $y) === 0) {
            return 0.0;
        }

        return 1.0;
    }

    protected function collapseRepeats(string $s): string
    {
        return preg_replace('/(.)\1+/u', '$1', $s) ?? $s;
    }
}
