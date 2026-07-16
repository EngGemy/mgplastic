<?php

namespace App\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Privacy;
use App\Models\Slider;
use App\Models\SocialMedia;
use App\Models\TermsCondition;
use App\Models\WebsiteService;
use App\Models\WebsiteSetting;
use App\Models\WebsiteStat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class LandingPageService
{
    public function data(): array
    {
        $settings = WebsiteSetting::instance();

        return [
            'settings' => $settings,
            'sliders' => Slider::forHome()->active()->ordered()->get(),
            'stats' => WebsiteStat::active()->ordered()->get(),
            'services' => WebsiteService::active()->ordered()->get(),
            'categories' => $this->categoriesTree(),
            'socialLinks' => SocialMedia::query()->orderBy('id')->get(),
            'cities' => City::query()
                ->when($this->libyaCountryId(), fn ($q, $id) => $q->where('country_id', $id))
                ->orderBy('id')
                ->get(['id', 'name_ar', 'name_en']),
            'libyaCountryId' => $this->libyaCountryId(),
            'termsUrl' => route('terms'),
            'privacyUrl' => route('privacy'),
            'policyUrl' => route('policy'),
            'contactUrl' => route('contact'),
        ];
    }

    public function termsContent(): ?TermsCondition
    {
        return TermsCondition::with('translations')->first();
    }

    public function privacyContent(): ?Privacy
    {
        return Privacy::with('translations')->first();
    }

    protected function libyaCountryId(): ?int
    {
        return Country::query()
            ->where('name_en', 'Libya')
            ->orWhere('name_ar', 'ليبيا')
            ->value('id');
    }

    /** @return list<array<string, mixed>> */
    public function categoriesTree(): array
    {
        $all = ProductCategory::with('translations')->orderBy('id')->get();

        return $all
            ->whereNull('parent_id')
            ->map(function (ProductCategory $parent) use ($all) {
                return [
                    'id' => $parent->id,
                    'slug' => $parent->slug,
                    'name' => $parent->name,
                    'children' => $all
                        ->where('parent_id', $parent->id)
                        ->map(fn (ProductCategory $child) => [
                            'id' => $child->id,
                            'slug' => $child->slug,
                            'name' => $child->name,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function paginatedProducts(?int $categoryId, int $page = 1, int $perPage = 24): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['category.translations', 'category.parent.translations', 'translations', 'standard', 'color'])
            ->whereHas('category');

        if ($categoryId) {
            $query->whereIn('product_category_id', $this->categoryFilterIds($categoryId));
        }

        return $query
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Product $product) => $this->productCardPayload($product));
    }

    /** @return list<int> */
    protected function categoryFilterIds(int $categoryId): array
    {
        $all = ProductCategory::query()->get(['id', 'parent_id']);

        if (! $all->contains('id', $categoryId)) {
            return [$categoryId];
        }

        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach ($all->where('parent_id', $parentId) as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    public function productCardPayload(Product $product): array
    {
        $nameAr = optional($product->translate('ar'))->name ?? 'منتج';
        $nameEn = optional($product->translate('en'))->name ?? '';
        $descAr = optional($product->translate('ar'))->description ?? '';
        $usageAr = optional($product->translate('ar'))->usage ?? '';
        $category = $product->category;
        $parentCat = $category?->parent;
        $catSlug = $category?->slug ?? 'all';
        $catName = $category?->name ?? '';
        $parentCatName = $parentCat?->name ?? '';

        $breadcrumb = collect([$parentCatName, $catName])->filter()->implode(' › ');

        return [
            'id' => $product->id,
            'cat' => $catSlug,
            'catId' => $category?->id,
            'catName' => $catName,
            'parentCatId' => $parentCat?->id,
            'parentCatName' => $parentCatName,
            'breadcrumb' => $breadcrumb,
            'name' => $nameAr,
            'en' => Str::upper($nameEn ?: Str::ascii($nameAr)),
            'desc' => $descAr,
            'usage' => $usageAr,
            'classification' => $product->classification,
            'notes' => $product->notes,
            'pts' => (float) $product->points_per_unit,
            'pointType' => $product->pointValueTypeLabel(),
            'pointConversion' => $product->pointConversionSummary(),
            'image' => $product->display_image_url,
            'pdf' => $product->catalog_pdf_url,
            'initials' => $this->initials($nameAr),
            'color' => $this->colorForCategory($catSlug),
            'specs' => $this->specsFor($product),
        ];
    }

    protected function productsPayload(): array
    {
        return Product::query()
            ->with(['category.translations', 'translations', 'standard', 'color'])
            ->whereHas('category')
            ->latest('id')
            ->limit(48)
            ->get()
            ->map(fn (Product $product) => $this->productCardPayload($product))
            ->values()
            ->all();
    }

    protected function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

        return Str::upper($letters ?: mb_substr($name, 0, 3));
    }

    protected function colorForCategory(string $slug): string
    {
        return match (true) {
            Str::contains($slug, ['pipe', 'انابيب', 'أنابيب']) => '#1a56db',
            Str::contains($slug, ['fitting', 'وصل', 'توصيل']) => '#059669',
            Str::contains($slug, ['valve', 'محبس', 'صمام']) => '#7c3aed',
            Str::contains($slug, ['drain', 'سيفون', 'صرف']) => '#78350f',
            Str::contains($slug, ['tank', 'خزان', 'مضخ']) => '#0f172a',
            default => '#0891b2',
        };
    }

    protected function specsFor(Product $product): array
    {
        $specs = [];

        if ($product->classification) {
            $specs[] = ['l' => 'التصنيف', 'v' => $product->classification];
        }
        if ($product->length_m) {
            $specs[] = ['l' => 'الطول', 'v' => $product->length_m.' متر'];
        }
        if ($product->thickness_mm) {
            $specs[] = ['l' => 'السماكة', 'v' => $product->thickness_mm.' ملم'];
        }
        if ($product->volume_ml) {
            $specs[] = ['l' => 'الحجم', 'v' => round($product->volume_ml / 1000, 2).' لتر'];
        }
        if ($product->standard) {
            $specs[] = ['l' => 'المعيار', 'v' => $product->standard->name ?? '—'];
        }
        if ($product->color) {
            $specs[] = ['l' => 'اللون', 'v' => $product->color->name ?? '—'];
        }

        return $specs;
    }
}
