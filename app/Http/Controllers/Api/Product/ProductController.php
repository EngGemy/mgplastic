<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public static function middleware(): array
    {
        // write endpoints require auth:sanctum; read endpoints are public
        return [ new Middleware('auth:sanctum', only: [
            'store','update','destroy','addImages','deleteImage'
        ]) ];
    }

    /** normalize Accept-Language to ar|en (default en) */
    private function language(Request $request): string
    {
        $raw  = (string) $request->header('Accept-Language', 'en');
        $lang = substr(strtolower(str_replace('_','-',$raw)), 0, 2);
        return in_array($lang, ['ar','en'], true) ? $lang : 'en';
    }

    /** admin guard for write ops */
    private function ensureAdmin(): ?\Illuminate\Http\JsonResponse
    {
        $u = Auth::user();
        if (! $u || $u->role !== 'admin') {
            return response()->json(['status'=>false,'message'=>'Only admin can perform this action'], 403);
        }
        return null;
    }

    // ---------------- READ ----------------

    /**
     * GET /v1/products/categories
     * - Tree mode (default): all parents with children
     * - Children mode: ?parent_id=ID => that parent with its children
     * - ?include_empty=false => hide empty parents/children
     */
    public function categories(Request $request)
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $includeEmpty = filter_var($request->query('include_empty', 'true'), FILTER_VALIDATE_BOOLEAN);

        // children mode
        if ($request->filled('parent_id')) {
            $parent = ProductCategory::query()
                ->with(['translations'])
                ->withCount('products')
                ->find((int) $request->query('parent_id'));

            if (! $parent) {
                return response()->json(['status'=>false,'message'=>'Parent category not found'], 404)
                    ->header('Content-Language', $lang);
            }

            $children = $parent->children()
                ->with(['translations'])
                ->withCount('products')
                ->when(! $includeEmpty, fn($q) => $q->has('products'))
                ->orderBy('id')
                ->get();

            return response()->json([
                'status' => true,
                'data'   => [
                    'parent'   => new ProductCategoryResource($parent),
                    'children' => ProductCategoryResource::collection($children),
                ],
            ])->header('Content-Language', $lang);
        }

        // tree mode
        $parents = ProductCategory::query()
            ->whereNull('parent_id')
            ->with(['translations'])
            ->withCount('products')
            ->when(! $includeEmpty, fn($q) => $q->has('products'))
            ->orderBy('id')
            ->get();

        $parents->load([
            'children' => function ($q) use ($includeEmpty) {
                $q->with(['translations'])
                    ->withCount('products')
                    ->when(! $includeEmpty, fn($qq) => $qq->has('products'))
                    ->orderBy('id');
            }
        ]);

        return response()->json([
            'status' => true,
            'data'   => ProductCategoryResource::collection($parents),
        ])->header('Content-Language', $lang);
    }

    /**
     * GET /v1/products/category/{id}
     * - products for a given child category
     * - ?with_children=true => include direct children too
     * - ?standard_id, ?color_id, ?search
     * - ?per_page=15 => paginate
     */
    public function productsByCategory($categoryId, Request $request)
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $category = ProductCategory::find($categoryId);
        if (! $category) {
            return response()->json(['status'=>false,'message'=>'Category not found'], 404)
                ->header('Content-Language', $lang);
        }

        $withChildren = filter_var($request->query('with_children', 'false'), FILTER_VALIDATE_BOOLEAN);

        $query = Product::query()
            ->with(['translations','images','variants','color','standard','category.translations']);

        if ($withChildren) {
            $ids = ProductCategory::query()
                ->where('id', $categoryId)
                ->orWhere('parent_id', $categoryId)
                ->pluck('id')
                ->all();
            $query->whereIn('product_category_id', $ids);
        } else {
            $query->where('product_category_id', $categoryId);
        }

        if ($request->filled('standard_id')) {
            $query->where('product_standard_id', (int) $request->query('standard_id'));
        }
        if ($request->filled('color_id')) {
            $query->where('product_color_id', (int) $request->query('color_id'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            if ($search !== '') {
                $query->whereTranslationLike('name', '%'.$search.'%');
            }
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $page = $query->latest()->paginate($perPage);
            return response()->json([
                'status' => true,
                'data'   => ProductResource::collection($page),
                'meta'   => [
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                    'per_page'     => $page->perPage(),
                    'total'        => $page->total(),
                ],
            ])->header('Content-Language', $lang);
        }

        $products = $query->latest()->get();

        return response()->json([
            'status' => true,
            'data'   => ProductResource::collection($products),
        ])->header('Content-Language', $lang);
    }

    /** GET /v1/products/{id} */
    public function showold(Request $request, $id)
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $product = Product::with(['translations','images','variants','color','standard','category.translations'])->find($id);
        if (! $product) {
            return response()->json(['status'=>false,'message'=>'Product not found'], 404)
                ->header('Content-Language', $lang);
        }

        return response()->json([
            'status' => true,
            'data'   => new ProductResource($product),
        ])->header('Content-Language', $lang);
    }

    // ProductController@show

// مثال في ProductController@show
    public function showoolds($id)
    {
        $product = \App\Models\Product::with([
            'translations',
            'category.parent.translations',
            'images',
            'standard',
            'color',
            'sizes.system',
        ])->findOrFail($id);

        // ----- اللغة -----
        $locale = app()->getLocale();
        $t = optional($product->translate($locale) ?: $product->translate('en'));

        // ----- Helpers -----
        $toStorageUrl = function (?string $path): ?string {
            if (!$path) return null;
            // لو الرابط مش http(s) حوّله لـ storage URL
            return \Illuminate\Support\Str::startsWith($path, ['http://','https://'])
                ? $path
                : \Storage::disk('public')->url(ltrim($path, '/'));
        };

        $titleMap = [
            'volume_ml'     => ['en' => 'Volume (BAR)',    'ar' => 'الضفط (BAR)'],
            'thickness_mm'  => ['en' => 'Thickness (mm)', 'ar' => 'السُّمك (ملليمتر)'],

            'length_m'      => ['en' => 'Length (m)',     'ar' => 'الطول (متر)'],
          //  'classification'=> ['en' => 'Classification', 'ar' => 'التصنيف'],
        ];
        $variantItem = function (string $key, $value) use ($titleMap, $locale) {
            if ($value === null || $value === '') return null;
            $en = $titleMap[$key]['en'] ?? \Illuminate\Support\Str::headline(str_replace('_',' ', $key));
            $ar = $titleMap[$key]['ar'] ?? $en;
            return [
                'key'       => $key,
                'title'     => $locale === 'ar' ? $ar : $en, // عنوان حسب اللغة الحالية
                'title_en'  => $en,
                'title_ar'  => $ar,
                'value'     => $value,
            ];
        };

        // ----- اكتشاف Accessories موحّد من الموديل -----
        $isAccessory = method_exists($product, 'isAccessories') ? $product->isAccessories() : false;

        // ----- Gallery -----
        $images = $product->images->sortBy('sort')->values()->map(function ($img) use ($toStorageUrl) {
            $path = $img->image ?? $img->path;
            return [
                'id'   => (int) $img->id,
                'path' => $path,
                'url'  => $toStorageUrl($path),
                'sort' => (int) ($img->sort ?? 0),
            ];
        });

        // ----- Main image (URL كامل) -----
        $mainImageUrl = $toStorageUrl($product->main_image);

        // ----- Variants (للمنتجات القياسية فقط) بعناوين محلية -----
        $varients = [];
        if (! $isAccessory) {
            $specs = [
                'volume_ml'     => $product->volume_ml,
                'thickness_mm'  => $product->thickness_mm,

                'length_m'      => $product->length_m,

               // 'classification'=> $product->classification,
            ];
            foreach ($specs as $k => $v) {
                if ($item = $variantItem($k, $v)) {
                    $varients[] = $item;
                }
            }
        }

        // ----- Payload -----
        $data = [
            'id'                  => (int) $product->id,
            'accepted_language'   => $locale,
            'name'                => (string) ($t->name ?? ''),
            'description'         => (string) ($t->description ?? ''),
            'usage'               => (string) ($t->usage ?? ''),
            'product_category_id' => $product->product_category_id,
            'category_name'       => (string) (optional($product->category->translate($locale) ?: $product->category->translate('en'))->name ?? ''),
            'product_standard_id' => $product->product_standard_id,
            'standard_name'       => optional($product->standard)->name ?? null,
            'product_color_id'    => $product->product_color_id,
            'color_name'          => optional($product->color)->name ?? null,

            // URL كامل
            'main_image'          => $mainImageUrl,
            'main_image_url'      => $mainImageUrl,

            'images'              => $images,
            // خليه كائن فاضي {} بدل [] عشان الـ front ما يتلخبط
            'meta'                => $product->meta ?? new \stdClass(),

            'created_at'          => optional($product->created_at)->toISOString(),
            'updated_at'          => optional($product->updated_at)->toISOString(),

            'type'                => $isAccessory ? 'accessory' : 'standard',
            // varients منسّقة بعناوين (مش كائن خام)
            'varients'            => $isAccessory ? [] : $varients,

            'notes'               => $product->notes,
            'variants'            => [], // لو عندك relation أخرى للـ variants
        ];

        // ----- Sizes: فقط للإكسسوارات -----
        $data['sizes'] = $isAccessory
            ? $product->sizes->sortBy('sort')->values()->map(function (\App\Models\Size $s) use ($locale, $toStorageUrl) {
                $imgUrl = isset($s->image) ? $toStorageUrl($s->image) : null;
                return [
                    'id'        => (int) $s->id,
                    'code'      => (string) ($s->code ?? ''),
                    'label'     => $locale === 'ar'
                        ? ($s->label_ar ?: $s->label_en ?? $s->name ?? $s->code)
                        : ($s->label_en ?: $s->label_ar ?? $s->name ?? $s->code),
                    'image_url' => $imgUrl,
                    'system'    => $s->system ? [
                        'id'   => (int) ($s->system->id ?? 0),
                        'code' => strtoupper((string) ($s->system->code ?? '')),
                        'name' => $locale === 'ar'
                            ? ($s->system->name_ar ?? $s->system->name ?? $s->system->code)
                            : ($s->system->name_en ?? $s->system->name ?? $s->system->code),
                    ] : null,
                ];
            })
            : collect();

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function showssols($id)
    {
        $product = \App\Models\Product::with([
            'translations',
            'category.parent.translations',
            'images',
            'standard',
            'color',
            'sizes.system',
            'variants', // <-- needed
        ])->findOrFail($id);

        // --- Locale
        $locale = app()->getLocale();
        $t = optional($product->translate($locale) ?: $product->translate('en'));

        // --- Helpers ---
        $toStorageUrl = function (?string $path): ?string {
            if (!$path) return null;
            return \Illuminate\Support\Str::startsWith($path, ['http://','https://'])
                ? $path
                : \Storage::disk('public')->url(ltrim($path, '/'));
        };

        // Titles dictionary for EN/AR (edit freely)
        $titleMap = [
            // Base specs
            'length_m'          => ['en' => 'Length (m)',          'ar' => 'الطول (متر)'],
            'thickness_mm'      => ['en' => 'Thickness (mm)',      'ar' => 'السُّمك (ملليمتر)'],
            'volume_ml'         => ['en' => 'Volume (ml)',         'ar' => 'السعة (ملليلتر)'],
            'classification'    => ['en' => 'Classification',      'ar' => 'التصنيف'],

            // ProductVariant fields
            'catalog_code'       => ['en' => 'Catalog code',        'ar' => 'كود الكتالوج'],
            'outer_diameter_mm'  => ['en' => 'Outer diameter (mm)', 'ar' => 'القطر الخارجي (مم)'],
            'wall_thickness_mm'  => ['en' => 'Wall thickness (mm)', 'ar' => 'سُمك الجدار (مم)'],
            'insertion_depth_mm' => ['en' => 'Insertion depth (mm)','ar' => 'عمق الإدخال (مم)'],
            'weight_kg_per_m'    => ['en' => 'Weight (kg/m)',       'ar' => 'الوزن (كجم/م)'],
            'pressure_class'     => ['en' => 'Pressure class',      'ar' => 'فئة الضغط'],
            'width_w_mm'         => ['en' => 'Width W (mm)',        'ar' => 'العرض W (مم)'],
            'height_l_mm'        => ['en' => 'Height L (mm)',       'ar' => 'الارتفاع L (مم)'],
            'depth_h_mm'         => ['en' => 'Depth H (mm)',        'ar' => 'العمق H (مم)'],
            'depth_h1_mm'        => ['en' => 'Depth H1 (mm)',       'ar' => 'العمق H1 (مم)'],
            'depth_h2_mm'        => ['en' => 'Depth H2 (mm)',       'ar' => 'العمق H2 (مم)'],
            'depth_h3_mm'        => ['en' => 'Depth H3 (mm)',       'ar' => 'العمق H3 (مم)'],
            'd1_mm'              => ['en' => 'D1 (mm)',             'ar' => 'D1 (مم)'],
            'd2_mm'              => ['en' => 'D2 (mm)',             'ar' => 'D2 (مم)'],
            'd3_mm'              => ['en' => 'D3 (mm)',             'ar' => 'D3 (مم)'],
            'd4_mm'              => ['en' => 'D4 (mm)',             'ar' => 'D4 (مم)'],
        ];

        $makeVarItem = function (string $key, $value, string $locale, ?string $suffix = null) use ($titleMap) {
            if ($value === null || $value === '') return null;
            $en = $titleMap[$key]['en'] ?? \Illuminate\Support\Str::headline(str_replace('_',' ', $key));
            $ar = $titleMap[$key]['ar'] ?? $en;

            if ($suffix) {
                $en .= " {$suffix}";
                $ar .= " {$suffix}";
            }

            return [
                'key'       => $key,
                'title'     => $locale === 'ar' ? $ar : $en,
                'title_en'  => $en,
                'title_ar'  => $ar,
                'value'     => $value,
            ];
        };

        // --- Determine Accessories
        $isAccessory = method_exists($product, 'isAccessories')
            ? (bool) $product->isAccessories()
            : (function () use ($product, $locale) {
                $slug = strtolower($product->category->slug ?? '');
                if (in_array($slug, ['accessories','accessory','acc'], true)) return true;
                $cname = mb_strtolower(optional($product->category->translate($locale) ?: $product->category->translate('en'))->name ?? '');
                return str_contains($cname, 'ملحق') || str_contains($cname, 'accessor');
            })();

        // --- Gallery
        $images = $product->images->sortBy('sort')->values()->map(function ($img) use ($toStorageUrl) {
            $path = $img->image ?? $img->path;
            return [
                'id'   => (int) $img->id,
                'path' => $path,
                'url'  => $toStorageUrl($path),
                'sort' => (int) ($img->sort ?? 0),
            ];
        });

        // --- Main image
        $mainImageUrl = $toStorageUrl($product->main_image ?: $product->main_image_url);

        // --- Catalog (Image + PDF)
        $catalogImage = [
            'path' => $product->catalog_image_path ?? null,
            'url'  => $toStorageUrl($product->catalog_image_path ?? null),
            'mime' => $product->catalog_image_mime ?? null,
            'size' => $product->catalog_image_size ?? null,
        ];
        $catalogPdf = [
            'path' => $product->catalog_pdf_path ?? null,
            'url'  => $toStorageUrl($product->catalog_pdf_path ?? null),
            'mime' => $product->catalog_pdf_mime ?? null,
            'size' => $product->catalog_pdf_size ?? null,
        ];

        // --- Build `varients`
        $varients = [];

        if (! $isAccessory) {
            $baseSpecs = [
                'length_m'       => $product->length_m,
                'thickness_mm'   => $product->thickness_mm,
                'volume_ml'      => $product->volume_ml,
                'classification' => $product->classification,
            ];
            foreach ($baseSpecs as $k => $v) {
                if ($item = $makeVarItem($k, $v, $locale)) $varients[] = $item;
            }
        }

        if ($product->relationLoaded('variants') && $product->variants->count()) {
            $i = 0;
            foreach ($product->variants as $v) {
                $i++;
                if ($v->catalog_code) {
                    if ($item = $makeVarItem('catalog_code', $v->catalog_code, $locale, "({$i})")) {
                        $varients[] = $item;
                    }
                }

                $vf = [
                    'outer_diameter_mm'  => $v->outer_diameter_mm,
                    'wall_thickness_mm'  => $v->wall_thickness_mm,
                    'insertion_depth_mm' => $v->insertion_depth_mm,
                    'weight_kg_per_m'    => $v->weight_kg_per_m,
                    'pressure_class'     => $v->pressure_class,
                    'width_w_mm'         => $v->width_w_mm,
                    'height_l_mm'        => $v->height_l_mm,
                    'depth_h_mm'         => $v->depth_h_mm,
                    'depth_h1_mm'        => $v->depth_h1_mm,
                    'depth_h2_mm'        => $v->depth_h2_mm,
                    'depth_h3_mm'        => $v->depth_h3_mm,
                    'd1_mm'              => $v->d1_mm,
                    'd2_mm'              => $v->d2_mm,
                    'd3_mm'              => $v->d3_mm,
                    'd4_mm'              => $v->d4_mm,
                ];
                $suffix = "(Variant {$i}" . ($v->catalog_code ? " / {$v->catalog_code}" : "") . ")";
                foreach ($vf as $k => $val) {
                    if ($item = $makeVarItem($k, $val, $locale, "{$suffix}")) {
                        $varients[] = $item;
                    }
                }
            }
        }

        // --- Raw variants array
        $productVariants = $product->relationLoaded('variants')
            ? $product->variants->map(function (\App\Models\ProductVariant $v) {
                return [
                    'id'                 => (int) $v->id,
                    'catalog_code'       => $v->catalog_code,
                    'outer_diameter_mm'  => $v->outer_diameter_mm,
                    'wall_thickness_mm'  => $v->wall_thickness_mm,
                    'insertion_depth_mm' => $v->insertion_depth_mm,
                    'weight_kg_per_m'    => $v->weight_kg_per_m,
                    'pressure_class'     => $v->pressure_class,
                    'width_w_mm'         => $v->width_w_mm,
                    'height_l_mm'        => $v->height_l_mm,
                    'depth_h_mm'         => $v->depth_h_mm,
                    'depth_h1_mm'        => $v->depth_h1_mm,
                    'depth_h2_mm'        => $v->depth_h2_mm,
                    'depth_h3_mm'        => $v->depth_h3_mm,
                    'd1_mm'              => $v->d1_mm,
                    'd2_mm'              => $v->d2_mm,
                    'd3_mm'              => $v->d3_mm,
                    'd4_mm'              => $v->d4_mm,
                    'extra'              => $v->extra,
                ];
            })->values()
            : collect();

        // --- Sizes (only for accessories)
        $sizes = $isAccessory
            ? $product->sizes->sortBy('sort')->values()->map(function (\App\Models\Size $s) use ($locale, $toStorageUrl) {
                $imgUrl = isset($s->image) ? $toStorageUrl($s->image) : null;
                return [
                    'id'        => (int) $s->id,
                    'code'      => (string) ($s->code ?? ''),
                    'label'     => $locale === 'ar'
                        ? ($s->label_ar ?? $s->name_ar ?? $s->name ?? $s->code)
                        : ($s->label_en ?? $s->name_en ?? $s->name ?? $s->code),
                    'image_url' => $imgUrl,
                    'system'    => $s->system ? [
                        'id'   => (int) ($s->system->id ?? 0),
                        'code' => strtoupper((string) ($s->system->code ?? '')),
                        'name' => $locale === 'ar'
                            ? ($s->system->name_ar ?? $s->system->name ?? $s->system->code)
                            : ($s->system->name_en ?? $s->system->name ?? $s->system->code),
                    ] : null,
                ];
            })->values()
            : collect();

        // --- Build response payload
        $data = [
            'status'              => true,
            'data' => [
                'id'                  => (int) $product->id,
                'accepted_language'   => $locale,
                'name'                => (string) ($t->name ?? ''),
                'description'         => (string) ($t->description ?? ''),
                'usage'               => (string) ($t->usage ?? ''),
                'product_category_id' => $product->product_category_id,
                'category_name'       => (string) (optional($product->category->translate($locale) ?: $product->category->translate('en'))->name ?? ''),
                'product_standard_id' => $product->product_standard_id,
                'standard_name'       => optional($product->standard)->name ?? null,
                'product_color_id'    => $product->product_color_id,
                'color_name'          => optional($product->color)->name ?? null,

                'main_image'          => $mainImageUrl,
                'main_image_url'      => $mainImageUrl,

                // ✅ Catalog section
                'catalog' => [
                    'image' => $catalogImage,                 // {path,url,mime,size}
                    'pdf'   => $catalogPdf,                   // {path,url,mime,size}
                    'has_image' => (bool) $catalogImage['path'],
                    'has_pdf'   => (bool) $catalogPdf['path'],
                    // convenience flat urls (optional for FE)
                    'image_url' => $catalogImage['url'],
                    'pdf_url'   => $catalogPdf['url'],
                ],

                'images'              => $images,
                'meta'                => $product->meta ?? new \stdClass(),

                'created_at'          => optional($product->created_at)->toISOString(),
                'updated_at'          => optional($product->updated_at)->toISOString(),

                'type'                => $isAccessory ? 'accessory' : 'standard',

                // unified, localized list
                'varients'            => $isAccessory ? [] : $varients,

                // raw variants array
                'variants'            => $productVariants,

                'notes'               => $product->notes,

                // sizes only if accessory
                'sizes'               => $sizes,
            ],
        ];

        return response()->json($data);
    }

    public function show($id)
    {
        $product = \App\Models\Product::with([
            'translations',
            'category.parent.translations',
            'images',
            'standard',
            'color',
            'sizes.system',
            'variants',
        ])->findOrFail($id);

        // --- Locale (الموبايل يرسل Accept-Language للميــدلوير/أب لاحقاً؛ هنا نستخدم القيمة الحالية) ---
        $locale = app()->getLocale();
        $t      = optional($product->translate($locale) ?: $product->translate('en'));

        // --- Helpers ---
        $toStorageUrl = function (?string $path): ?string {
            if (!$path) return null;
            return \Illuminate\Support\Str::startsWith($path, ['http://','https://'])
                ? $path
                : \Storage::disk('public')->url(ltrim($path, '/'));
        };

        // منظِّف HTML خفيف: يزيل dir="" والخصائص، ويسمح بمجموعة وسوم شائعة فقط
        $cleanHtml = function (?string $html): ?string {
            if (!$html) return null;
            // شيل dir="rtl|ltr"
            $html = preg_replace('/\sdir=("|\')(rtl|ltr)\1/i', '', $html);
            // اسمح ببعض الوسوم (بدون خصائص) واحذف الباقي
            $allowed = '<p><br><hr><ul><ol><li><strong><b><em><i><u><h2><h3><h4><h5><h6><a>';
            $html    = strip_tags($html, $allowed);
            // بسّط المسافات والأسطر
            $html    = preg_replace('/\s+/', ' ', $html);
            $html    = preg_replace('/\s*(<\/?(?:p|li|h2|h3|h4|h5|h6|br)\b[^>]*>)+\s*/i', '$1', $html);
            return trim($html);
        };

        $toText = function (?string $html): ?string {
            if (!$html) return null;
            $txt = strip_tags($html);
            $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return \Illuminate\Support\Str::of($txt)->squish()->toString();
        };

        // --- Build specs titles ---
        $titleMap = [
            // Base specs
            'length_m'          => ['en' => 'Length (m)',          'ar' => 'الطول (متر)'],
            'thickness_mm'      => ['en' => 'Thickness (mm)',      'ar' => 'السُّمك (ملليمتر)'],
            'volume_ml'         => ['en' => 'Volume (ml)',         'ar' => 'السعة (ملليلتر)'],
            'classification'    => ['en' => 'Classification',      'ar' => 'التصنيف'],
            // ProductVariant fields
            'catalog_code'       => ['en' => 'Catalog code',        'ar' => 'كود الكتالوج'],
            'outer_diameter_mm'  => ['en' => 'Outer diameter (mm)', 'ar' => 'القطر الخارجي (مم)'],
            'wall_thickness_mm'  => ['en' => 'Wall thickness (mm)', 'ar' => 'سُمك الجدار (مم)'],
            'insertion_depth_mm' => ['en' => 'Insertion depth (mm)','ar' => 'عمق الإدخال (مم)'],
            'weight_kg_per_m'    => ['en' => 'Weight (kg/m)',       'ar' => 'الوزن (كجم/م)'],
            'pressure_class'     => ['en' => 'Pressure class',      'ar' => 'فئة الضغط'],
            'width_w_mm'         => ['en' => 'Width W (mm)',        'ar' => 'العرض W (مم)'],
            'height_l_mm'        => ['en' => 'Height L (mm)',       'ar' => 'الارتفاع L (مم)'],
            'depth_h_mm'         => ['en' => 'Depth H (mm)',        'ar' => 'العمق H (مم)'],
            'depth_h1_mm'        => ['en' => 'Depth H1 (mm)',       'ar' => 'العمق H1 (مم)'],
            'depth_h2_mm'        => ['en' => 'Depth H2 (mm)',       'ar' => 'العمق H2 (مم)'],
            'depth_h3_mm'        => ['en' => 'Depth H3 (mm)',       'ar' => 'العمق H3 (مم)'],
            'd1_mm'              => ['en' => 'D1 (mm)',             'ar' => 'D1 (مم)'],
            'd2_mm'              => ['en' => 'D2 (mm)',             'ar' => 'D2 (مم)'],
            'd3_mm'              => ['en' => 'D3 (mm)',             'ar' => 'D3 (مم)'],
            'd4_mm'              => ['en' => 'D4 (mm)',             'ar' => 'D4 (مم)'],
        ];

        $makeVarItem = function (string $key, $value, string $locale, ?string $suffix = null) use ($titleMap) {
            if ($value === null || $value === '') return null;
            $en = $titleMap[$key]['en'] ?? \Illuminate\Support\Str::headline(str_replace('_',' ', $key));
            $ar = $titleMap[$key]['ar'] ?? $en;
            if ($suffix) { $en .= " {$suffix}"; $ar .= " {$suffix}"; }
            return [
                'key'      => $key,
                'title'    => $locale === 'ar' ? $ar : $en,
                'title_en' => $en,
                'title_ar' => $ar,
                'value'    => $value,
            ];
        };

        // --- Determine Accessories
        $isAccessory = method_exists($product, 'isAccessories')
            ? (bool) $product->isAccessories()
            : (function () use ($product, $locale) {
                $slug = strtolower($product->category->slug ?? '');
                if (in_array($slug, ['accessories','accessory','acc'], true)) return true;
                $cname = mb_strtolower(optional($product->category->translate($locale) ?: $product->category->translate('en'))->name ?? '');
                return str_contains($cname, 'ملحق') || str_contains($cname, 'accessor');
            })();

        // --- Gallery
        $images = $product->images->sortBy('sort')->values()->map(function ($img) use ($toStorageUrl) {
            $path = $img->image ?? $img->path;
            return [
                'id'   => (int) $img->id,
                'path' => $path,
                'url'  => $toStorageUrl($path),
                'sort' => (int) ($img->sort ?? 0),
            ];
        });

        // --- Main image
        $mainImageUrl = $toStorageUrl($product->main_image ?: $product->main_image_url);

        // --- Catalog (Image + PDF)
        $catalogImage = [
            'path' => $product->catalog_image_path ?? null,
            'url'  => $toStorageUrl($product->catalog_image_path ?? null),
            'mime' => $product->catalog_image_mime ?? null,
            'size' => $product->catalog_image_size ?? null,
        ];
        $catalogPdf = [
            'path' => $product->catalog_pdf_path ?? null,
            'url'  => $toStorageUrl($product->catalog_pdf_path ?? null),
            'mime' => $product->catalog_pdf_mime ?? null,
            'size' => $product->catalog_pdf_size ?? null,
        ];

        // --- Build varients (قائمة موحَّدة)
        $varients = [];
        if (! $isAccessory) {
            $baseSpecs = [
                'length_m'       => $product->length_m,
                'thickness_mm'   => $product->thickness_mm,
                'volume_ml'      => $product->volume_ml,
                'classification' => $product->classification,
            ];
            foreach ($baseSpecs as $k => $v) {
                if ($item = $makeVarItem($k, $v, $locale)) $varients[] = $item;
            }
        }
        if ($product->relationLoaded('variants') && $product->variants->count()) {
            $i = 0;
            foreach ($product->variants as $v) {
                $i++;
                if ($v->catalog_code) {
                    if ($item = $makeVarItem('catalog_code', $v->catalog_code, $locale, "({$i})")) {
                        $varients[] = $item;
                    }
                }
                $vf = [
                    'outer_diameter_mm'  => $v->outer_diameter_mm,
                    'wall_thickness_mm'  => $v->wall_thickness_mm,
                    'insertion_depth_mm' => $v->insertion_depth_mm,
                    'weight_kg_per_m'    => $v->weight_kg_per_m,
                    'pressure_class'     => $v->pressure_class,
                    'width_w_mm'         => $v->width_w_mm,
                    'height_l_mm'        => $v->height_l_mm,
                    'depth_h_mm'         => $v->depth_h_mm,
                    'depth_h1_mm'        => $v->depth_h1_mm,
                    'depth_h2_mm'        => $v->depth_h2_mm,
                    'depth_h3_mm'        => $v->depth_h3_mm,
                    'd1_mm'              => $v->d1_mm,
                    'd2_mm'              => $v->d2_mm,
                    'd3_mm'              => $v->d3_mm,
                    'd4_mm'              => $v->d4_mm,
                ];
                $suffix = "(Variant {$i}" . ($v->catalog_code ? " / {$v->catalog_code}" : "") . ")";
                foreach ($vf as $k => $val) {
                    if ($item = $makeVarItem($k, $val, $locale, "{$suffix}")) {
                        $varients[] = $item;
                    }
                }
            }
        }

        // --- Raw variants array
        $productVariants = $product->relationLoaded('variants')
            ? $product->variants->map(function (\App\Models\ProductVariant $v) {
                return [
                    'id'                 => (int) $v->id,
                    'catalog_code'       => $v->catalog_code,
                    'outer_diameter_mm'  => $v->outer_diameter_mm,
                    'wall_thickness_mm'  => $v->wall_thickness_mm,
                    'insertion_depth_mm' => $v->insertion_depth_mm,
                    'weight_kg_per_m'    => $v->weight_kg_per_m,
                    'pressure_class'     => $v->pressure_class,
                    'width_w_mm'         => $v->width_w_mm,
                    'height_l_mm'        => $v->height_l_mm,
                    'depth_h_mm'         => $v->depth_h_mm,
                    'depth_h1_mm'        => $v->depth_h1_mm,
                    'depth_h2_mm'        => $v->depth_h2_mm,
                    'depth_h3_mm'        => $v->depth_h3_mm,
                    'd1_mm'              => $v->d1_mm,
                    'd2_mm'              => $v->d2_mm,
                    'd3_mm'              => $v->d3_mm,
                    'd4_mm'              => $v->d4_mm,
                    'extra'              => $v->extra,
                ];
            })->values()
            : collect();

        // --- Sizes (only for accessories)
        $sizes = $isAccessory
            ? $product->sizes->sortBy('sort')->values()->map(function (\App\Models\Size $s) use ($locale, $toStorageUrl) {
                $imgUrl = isset($s->image) ? $toStorageUrl($s->image) : null;
                return [
                    'id'        => (int) $s->id,
                    'code'      => (string) ($s->code ?? ''),
                    'label'     => $locale === 'ar'
                        ? ($s->label_ar ?? $s->name_ar ?? $s->name ?? $s->code)
                        : ($s->label_en ?? $s->name_en ?? $s->name ?? $s->code),
                    'image_url' => $imgUrl,
                    'system'    => $s->system ? [
                        'id'   => (int) ($s->system->id ?? 0),
                        'code' => strtoupper((string) ($s->system->code ?? '')),
                        'name' => $locale === 'ar'
                            ? ($s->system->name_ar ?? $s->system->name ?? $s->system->code)
                            : ($s->system->name_en ?? $s->system->name ?? $s->system->code),
                    ] : null,
                ];
            })->values()
            : collect();

        // --- HTML/Naked text for description & usage ---
        $descRaw   = (string) ($t->description ?? '');
        $usageRaw  = (string) ($t->usage ?? '');

        $descHtml  = $cleanHtml($descRaw);
        $usageHtml = $cleanHtml($usageRaw);

        $descText  = $toText($descHtml);
        $usageText = $toText($usageHtml);

        // --- Build response payload ---
        $data = [
            'status' => true,
            'data'   => [
                'id'                  => (int) $product->id,
                'accepted_language'   => $locale,
                'name'                => (string) ($t->name ?? ''),

                // نص صِرف (للموبايل الافتراضي)
                'description'         => $descText,
                'usage'               => $usageText,

                // HTML نظيف (لو هتعمل رندر Rich Text)
                'description_html'    => $descHtml,
                'usage_html'          => $usageHtml,

                'product_category_id' => $product->product_category_id,
                'category_name'       => (string) (optional($product->category->translate($locale) ?: $product->category->translate('en'))->name ?? ''),
                'product_standard_id' => $product->product_standard_id,
                'standard_name'       => optional($product->standard)->name ?? null,
                'product_color_id'    => $product->product_color_id,
                'color_name'          => optional($product->color)->name ?? null,

                'main_image'          => $mainImageUrl,
                'main_image_url'      => $mainImageUrl,

                'catalog' => [
                    'image'     => $catalogImage, // {path,url,mime,size}
                    'pdf'       => $catalogPdf,   // {path,url,mime,size}
                    'has_image' => (bool) $catalogImage['path'],
                    'has_pdf'   => (bool) $catalogPdf['path'],
                    'image_url' => $catalogImage['url'],
                    'pdf_url'   => $catalogPdf['url'],
                ],

                'images'              => $images,
                'meta'                => $product->meta ?? new \stdClass(),

                'created_at'          => optional($product->created_at)->toISOString(),
                'updated_at'          => optional($product->updated_at)->toISOString(),

                'type'                => $isAccessory ? 'accessory' : 'standard',
                'varients'            => $isAccessory ? [] : $varients,  // الموحدة
                'variants'            => $productVariants,               // الخام
                'notes'               => $product->notes,
                'sizes'               => $sizes,                         // للإكسسوارات فقط
            ],
        ];

        return response()->json($data);
    }




    // ---------------- WRITE (ADMIN) ----------------

    /** POST /v1/products */
    public function store(Request $request)
    {
        if ($resp = $this->ensureAdmin()) return $resp;

        $request->validate([
            'product_category_id' => ['required','exists:product_categories,id'],
            'product_standard_id' => ['nullable','exists:product_standards,id'],
            'product_color_id'    => ['nullable','exists:product_colors,id'],

            'length_m'            => ['nullable','numeric'],
            'thickness_mm'        => ['nullable','numeric'],
            'volume_ml'           => ['nullable','numeric'],
            'classification'      => ['nullable','string','max:50'],
            'notes'               => ['nullable','string'],
            'meta'                => ['nullable','array'],

            // main image = file OR existing string path OR null
            'main_image'          => ['nullable'],

            // translations
            'name_en'             => ['required','string','max:255'],
            'description_en'      => ['nullable','string'],
            'usage_en'            => ['nullable','string'],
            'name_ar'             => ['required','string','max:255'],
            'description_ar'      => ['nullable','string'],
            'usage_ar'            => ['nullable','string'],

            // optional gallery images
            'images'              => ['sometimes','array'],
            'images.*'            => ['image','max:4096'],
        ]);

        $mainPath = null;
        if ($request->hasFile('main_image')) {
            $mainPath = $request->file('main_image')->store('products/main', 'public');
        } elseif (is_string($request->input('main_image'))) {
            $mainPath = $request->input('main_image'); // keep existing path
        }

        $product = DB::transaction(function () use ($request, $mainPath) {
            $p = Product::create([
                'product_category_id' => $request->integer('product_category_id'),
                'product_standard_id' => $request->input('product_standard_id'),
                'product_color_id'    => $request->input('product_color_id'),
                'length_m'            => $request->input('length_m'),
                'thickness_mm'        => $request->input('thickness_mm'),
                'volume_ml'           => $request->input('volume_ml'),
                'classification'      => $request->input('classification'),
                'notes'               => $request->input('notes'),
                'main_image'          => $mainPath,
                'meta'                => $request->input('meta'),
            ]);

            // translations
            $p->translateOrNew('en')->name        = $request->input('name_en');
            $p->translateOrNew('en')->description = $request->input('description_en', '');
            $p->translateOrNew('en')->usage       = $request->input('usage_en', '');

            $p->translateOrNew('ar')->name        = $request->input('name_ar');
            $p->translateOrNew('ar')->description = $request->input('description_ar', '');
            $p->translateOrNew('ar')->usage       = $request->input('usage_ar', '');

            $p->save();

            // optional gallery
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $idx => $file) {
                    $path = $file->store('products/gallery', 'public');
                    ProductImage::create([
                        'product_id' => $p->id,
                        'image'      => $path,
                        'sort'       => $idx,
                    ]);
                }
            }

            return $p->refresh();
        });

        $product->load(['translations','images','variants','color','standard','category.translations']);

        $lang = $this->language($request);
        return response()->json([
            'status'  => true,
            'message' => 'Product created',
            'data'    => new ProductResource($product),
        ], 201)->header('Content-Language', $lang);
    }

    /** PUT /v1/products/{id} */
    public function update(Request $request, $id)
    {
        if ($resp = $this->ensureAdmin()) return $resp;

        $product = Product::with(['translations','images'])->find($id);
        if (! $product) return response()->json(['status'=>false,'message'=>'Product not found'], 404);

        $request->validate([
            'product_category_id' => ['sometimes','exists:product_categories,id'],
            'product_standard_id' => ['sometimes','nullable','exists:product_standards,id'],
            'product_color_id'    => ['sometimes','nullable','exists:product_colors,id'],

            'length_m'            => ['sometimes','nullable','numeric'],
            'thickness_mm'        => ['sometimes','nullable','numeric'],
            'volume_ml'           => ['sometimes','nullable','numeric'],
            'classification'      => ['sometimes','nullable','string','max:50'],
            'notes'               => ['sometimes','nullable','string'],
            'meta'                => ['sometimes','nullable','array'],

            // file | string path | null
            'main_image'          => ['sometimes','nullable'],

            // translations
            'name_en'             => ['sometimes','string','max:255'],
            'description_en'      => ['sometimes','nullable','string'],
            'usage_en'            => ['sometimes','nullable','string'],
            'name_ar'             => ['sometimes','string','max:255'],
            'description_ar'      => ['sometimes','nullable','string'],
            'usage_ar'            => ['sometimes','nullable','string'],
        ]);

        DB::transaction(function () use ($request, $product) {
            foreach ([
                         'product_category_id','product_standard_id','product_color_id',
                         'length_m','thickness_mm','volume_ml','classification','notes','meta'
                     ] as $f) {
                if ($request->has($f)) {
                    $product->{$f} = $request->input($f);
                }
            }

            // main image behavior
            if ($request->hasFile('main_image')) {
                if ($product->main_image && str_starts_with($product->main_image, 'products/')) {
                    Storage::disk('public')->delete($product->main_image);
                }
                $product->main_image = $request->file('main_image')->store('products/main', 'public');
            } elseif ($request->has('main_image')) {
                $val = $request->input('main_image'); // string path or null
                if ($val === null && $product->main_image && str_starts_with($product->main_image, 'products/')) {
                    Storage::disk('public')->delete($product->main_image);
                }
                $product->main_image = $val;
            }

            // translations only if provided
            if ($request->hasAny(['name_en','description_en','usage_en'])) {
                $product->translateOrNew('en')->name        = $request->input('name_en',        $product->translate('en')->name ?? '');
                $product->translateOrNew('en')->description = $request->input('description_en', $product->translate('en')->description ?? '');
                $product->translateOrNew('en')->usage       = $request->input('usage_en',       $product->translate('en')->usage ?? '');
            }
            if ($request->hasAny(['name_ar','description_ar','usage_ar'])) {
                $product->translateOrNew('ar')->name        = $request->input('name_ar',        $product->translate('ar')->name ?? '');
                $product->translateOrNew('ar')->description = $request->input('description_ar', $product->translate('ar')->description ?? '');
                $product->translateOrNew('ar')->usage       = $request->input('usage_ar',       $product->translate('ar')->usage ?? '');
            }

            $product->save();
        });

        $product->load(['translations','images','variants','color','standard','category.translations']);

        $lang = $this->language($request);
        return response()->json([
            'status'  => true,
            'message' => 'Product updated',
            'data'    => new ProductResource($product),
        ])->header('Content-Language', $lang);
    }

    /** DELETE /v1/products/{id} */
    public function destroy($id)
    {
        if ($resp = $this->ensureAdmin()) return $resp;

        $product = Product::with('images')->find($id);
        if (! $product) return response()->json(['status'=>false,'message'=>'Product not found'], 404);

        DB::transaction(function () use ($product) {
            foreach ($product->images as $img) {
                if ($img->image && str_starts_with($img->image, 'products/')) {
                    Storage::disk('public')->delete($img->image);
                }
                $img->delete();
            }
            if ($product->main_image && str_starts_with($product->main_image, 'products/')) {
                Storage::disk('public')->delete($product->main_image);
            }
            $product->delete();
        });

        return response()->json(['status'=>true,'message'=>'Product deleted']);
    }

    /** POST /v1/products/{id}/images */
    public function addImages(Request $request, $id)
    {
        if ($resp = $this->ensureAdmin()) return $resp;

        $product = Product::find($id);
        if (! $product) return response()->json(['status'=>false,'message'=>'Product not found'], 404);

        $request->validate([
            'images'   => ['required','array','min:1'],
            'images.*' => ['required','image','max:4096'],
        ]);

        DB::transaction(function () use ($request, $product) {
            $startSort = (int) ($product->images()->max('sort') ?? 0);
            foreach ($request->file('images', []) as $i => $file) {
                $path = $file->store('products/gallery', 'public');
                ProductImage::create([
                    'product_id' => $product->id,
                    'image'      => $path,
                    'sort'       => $startSort + $i + 1,
                ]);
            }
        });

        $product->load(['images']);

        return response()->json([
            'status'=>true,
            'message'=>'Images added',
            'data'=>[
                'images'=>$product->images->sortBy('sort')->values()->map(fn($img)=>[
                    'id'=>$img->id,
                    'url'=> $img->image ? Storage::disk('public')->url($img->image) : null,
                    'sort'=>$img->sort
                ])
            ]
        ], 201);
    }

    /** DELETE /v1/products/{id}/images/{imageId} */
    public function deleteImage($id, $imageId)
    {
        if ($resp = $this->ensureAdmin()) return $resp;

        $product = Product::find($id);
        if (! $product) return response()->json(['status'=>false,'message'=>'Product not found'], 404);

        $img = $product->images()->whereKey($imageId)->first();
        if (! $img) return response()->json(['status'=>false,'message'=>'Image not found'], 404);

        if ($img->image && str_starts_with($img->image, 'products/')) {
            Storage::disk('public')->delete($img->image);
        }
        $img->delete();

        return response()->json(['status'=>true,'message'=>'Image deleted']);
    }
}
