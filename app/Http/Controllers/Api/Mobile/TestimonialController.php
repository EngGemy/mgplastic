<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestimonialController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        if (! config('testimonials.enabled', true)) {
            return $this->success(['items' => [], 'enabled' => false], 'آراء العملاء');
        }

        $limit = min(50, max(1, (int) $request->query('limit', config('testimonials.public_limit', 24))));
        $items = Testimonial::query()
            ->forPublic()
            ->limit($limit)
            ->get()
            ->map(fn (Testimonial $t) => $t->toPublicArray())
            ->values();

        return $this->success([
            'enabled' => true,
            'items' => $items,
            'animation' => [
                'style' => 'marquee_cards',
                'autoplay' => true,
                'pause_on_touch' => true,
                'stars' => 5,
            ],
        ], 'آراء العملاء عن منتجات MG');
    }
}
