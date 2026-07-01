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
            'categories' => ProductCategory::with('translations')
                ->whereNull('parent_id')
                ->orderBy('id')
                ->get(),
            'products' => $this->productsPayload(),
            'socialLinks' => SocialMedia::query()->orderBy('id')->get(),
            'cities' => City::query()
                ->when($this->libyaCountryId(), fn ($q, $id) => $q->where('country_id', $id))
                ->orderBy('id')
                ->get(['id', 'name_ar', 'name_en']),
            'libyaCountryId' => $this->libyaCountryId(),
            'termsUrl' => route('terms'),
            'privacyUrl' => route('privacy'),
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

    protected function productsPayload(): array
    {
        return Product::query()
            ->with(['category.translations', 'translations', 'standard', 'color'])
            ->whereHas('category')
            ->latest('id')
            ->limit(48)
            ->get()
            ->map(function (Product $product) {
                $nameAr = optional($product->translate('ar'))->name ?? 'منتج';
                $nameEn = optional($product->translate('en'))->name ?? '';
                $desc = optional($product->translate('ar'))->description ?? '';
                $catSlug = $product->category?->slug ?? 'all';
                $catName = $product->category?->name ?? '';

                return [
                    'id' => $product->id,
                    'cat' => $catSlug,
                    'catName' => $catName,
                    'name' => $nameAr,
                    'en' => Str::upper($nameEn ?: Str::ascii($nameAr)),
                    'desc' => $desc,
                    'pts' => (float) $product->points_per_unit,
                    'image' => $product->catalog_image_url,
                    'pdf' => $product->catalog_pdf_url,
                    'initials' => $this->initials($nameAr),
                    'color' => $this->colorForCategory($catSlug),
                    'specs' => $this->specsFor($product),
                ];
            })
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
