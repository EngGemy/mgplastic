<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\LandingPageService;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(protected LandingPageService $landing) {}

    public function products(Request $request)
    {
        $categoryId = $request->query('category_id');
        $categoryId = is_numeric($categoryId) ? (int) $categoryId : null;
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(48, max(12, (int) $request->query('per_page', 24)));

        $paginator = $this->landing->paginatedProducts($categoryId, $page, $perPage);

        return response()->json([
            'data' => $paginator->getCollection()->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}
