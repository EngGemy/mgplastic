<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\PromoBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoBannerController extends Controller
{
    use ApiResponds;

    /**
     * GET /api/v1/mobile/promo-banners?section=between_plumbers_stores
     */
    public function index(Request $request): JsonResponse
    {
        $section = $request->query('section', PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES);

        $items = PromoBanner::query()
            ->where('is_active', true)
            ->when($section, fn ($q) => $q->where('section', $section))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PromoBanner $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'subtitle' => $b->subtitle,
                'image_url' => $b->imageUrl(),
                'section' => $b->section,
                'link' => $b->resolveLinkPayload(),
                'sort_order' => (int) $b->sort_order,
            ])
            ->values();

        return $this->success([
            'section' => $section,
            'items' => $items,
        ], 'بانرات دعائية');
    }
}
