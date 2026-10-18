<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ProductPromotion;
use Illuminate\Http\JsonResponse;

class ProductPromotionController extends Controller
{
    use ApiResponds;

    /** GET /api/v1/mobile/product-promotions — دعاية منتجات MG. */
    public function index(): JsonResponse
    {
        $items = ProductPromotion::query()
            ->where('is_active', true)
            ->with(['product.translations'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProductPromotion $p) => $this->mapPromotion($p))
            ->values();

        return $this->success(['items' => $items], 'دعاية منتجات MG');
    }

    public function show(ProductPromotion $promotion): JsonResponse
    {
        if (! $promotion->is_active) {
            return $this->error('الإعلان غير متاح', 404);
        }

        $promotion->loadMissing(['product.translations']);

        return $this->success($this->mapPromotion($promotion), 'تفاصيل الإعلان');
    }

    /** @return array<string, mixed> */
    protected function mapPromotion(ProductPromotion $p): array
    {
        $product = $p->product;

        return [
            'id' => $p->id,
            'title' => $p->title,
            'subtitle' => $p->subtitle,
            'body' => $p->body,
            'media_type' => $p->media_type,
            'image_url' => $p->imageUrl(),
            'comparison_image_url' => $p->comparisonImageUrl(),
            'video_url' => $p->video_url,
            'product_id' => $p->product_id ? (int) $p->product_id : null,
            'product_name' => $product ? localized_name($product, 'name') : null,
            'product_url' => $p->resolveProductLink(),
            'sort_order' => (int) $p->sort_order,
        ];
    }
}
