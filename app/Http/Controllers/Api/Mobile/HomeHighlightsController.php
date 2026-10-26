<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\HomeMobileLayoutService;
use Illuminate\Http\JsonResponse;

class HomeHighlightsController extends Controller
{
    use ApiResponds;

    public function index(HomeMobileLayoutService $layout): JsonResponse
    {
        $layout->ensureSeeded();

        $items = collect($layout->mapHighlightsForApi())
            ->map(fn (array $h) => [
                'key' => $h['key'],
                'label' => $h['label'],
                'icon' => $h['icon'],
                'link' => $h['link'] ?? null,
                'category_id' => $h['category_id'] ?? null,
            ])
            ->values()
            ->all();

        return $this->success($items, 'أيقونات بانر الرئيسية');
    }
}
