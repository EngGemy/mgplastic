<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class HomeHighlightsController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $categories = ProductCategory::query()
            ->with('translations')
            ->orderBy('id')
            ->get();

        $items = [];
        $rows = app(\App\Services\HomeMobileLayoutService::class)->highlightDefinitions();
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }

            $categoryId = $this->resolveCategoryId(
                $categories,
                $row['category_match'] ?? [],
                $row['category_exclude'] ?? [],
                $row['category_prefer'] ?? [],
            );

            $link = $row['link'] ?? $row['deep_link'] ?? null;
            if (! filled($link) && $categoryId) {
                $link = 'mgplastic://category/'.$categoryId;
            }

            $items[] = [
                'key' => (string) $row['key'],
                'label' => (string) ($row['label'] ?? $row['key']),
                'icon' => (string) ($row['icon'] ?? $row['key']),
                'link' => $link,
                'category_id' => $categoryId,
            ];
        }

        return $this->success($items, 'أيقونات بانر الرئيسية');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductCategory>  $categories
     * @param  list<string>  $needles
     * @param  list<string>  $exclude
     * @param  list<string>  $prefer
     */
    protected function resolveCategoryId($categories, array $needles, array $exclude = [], array $prefer = []): ?int
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

            $excluded = false;
            foreach ($exclude as $ex) {
                if ($ex !== '' && Str::contains($blob, mb_strtolower($ex))) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
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
