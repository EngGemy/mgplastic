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
        foreach (config('home_highlights', []) as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }

            $categoryId = $this->resolveCategoryId($categories, $row['category_match'] ?? []);

            $items[] = [
                'key' => (string) $row['key'],
                'label' => (string) ($row['label'] ?? $row['key']),
                'icon' => (string) ($row['icon'] ?? $row['key']),
                'link' => $row['link'] ?? $row['deep_link'] ?? null,
                'category_id' => $categoryId,
            ];
        }

        return $this->success($items, 'أيقونات بانر الرئيسية');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductCategory>  $categories
     * @param  list<string>  $needles
     */
    protected function resolveCategoryId($categories, array $needles): ?int
    {
        if ($needles === []) {
            return null;
        }

        foreach ($categories as $cat) {
            $blob = mb_strtolower(trim(implode(' ', array_filter([
                (string) $cat->slug,
                localized_name($cat, 'name', ''),
                (string) optional($cat->translate('ar'))->description,
            ]))));

            foreach ($needles as $needle) {
                if ($needle !== '' && Str::contains($blob, mb_strtolower($needle))) {
                    return (int) $cat->id;
                }
            }
        }

        return null;
    }
}
