<?php

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HomeHighlightsCategoryResolver
{
    /**
     * @param  Collection<int, ProductCategory>  $categories
     * @param  list<string>  $needles
     * @param  list<string>  $exclude
     * @param  list<string>  $prefer
     */
    public function resolve($categories, array $needles, array $exclude = [], array $prefer = []): ?int
    {
        if ($needles === []) {
            return null;
        }

        $matched = [];

        foreach ($categories as $cat) {
            $blob = mb_strtolower(trim(implode(' ', array_filter([
                (string) $cat->slug,
                localized_name($cat, 'name', ''),
                (string) optional($cat->translate('ar'))->description,
            ]))));

            foreach ($exclude as $ex) {
                if ($ex !== '' && Str::contains($blob, mb_strtolower($ex))) {
                    continue 2;
                }
            }

            foreach ($needles as $needle) {
                if ($needle !== '' && Str::contains($blob, mb_strtolower($needle))) {
                    $score = 1;
                    foreach ($prefer as $p) {
                        if ($p !== '' && Str::contains($blob, mb_strtolower($p))) {
                            $score += 10;
                        }
                    }
                    $matched[] = ['id' => (int) $cat->id, 'score' => $score];
                    break;
                }
            }
        }

        if ($matched === []) {
            return null;
        }

        usort($matched, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return $matched[0]['id'];
    }
}
