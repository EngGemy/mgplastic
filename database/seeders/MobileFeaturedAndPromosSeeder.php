<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds data for mobile home endpoints:
 * - GET /api/v1/mobile/featured-wholesalers
 * - GET /api/v1/mobile/product-promotions
 */
class MobileFeaturedAndPromosSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::query()->first();

        $this->seedFeaturedWholesalers($city?->id, $city?->country_id);
        $this->seedProductPromotions();

        $this->command?->info('MobileFeaturedAndPromosSeeder: featured wholesalers + product promotions ready.');
    }

    protected function seedFeaturedWholesalers(?int $cityId, ?int $countryId): void
    {
        $wholesalers = [
            [
                'email' => 'featured.wholesaler1@mgplastic.com',
                'phone' => '0912001001',
                'name' => 'موزع طرابلس المركزي',
                'brand_name' => 'MG طرابلس',
                'featured_sort' => 1,
                'color' => [13, 45, 110],
            ],
            [
                'email' => 'featured.wholesaler2@mgplastic.com',
                'phone' => '0912001002',
                'name' => 'موزع بنغازي',
                'brand_name' => 'MG بنغازي',
                'featured_sort' => 2,
                'color' => [5, 150, 105],
            ],
            [
                'email' => 'featured.wholesaler3@mgplastic.com',
                'phone' => '0912001003',
                'name' => 'موزع مصراتة',
                'brand_name' => 'MG مصراتة',
                'featured_sort' => 3,
                'color' => [8, 145, 178],
            ],
        ];

        foreach ($wholesalers as $row) {
            $logoPath = $this->makePlaceholderImage(
                'brand-logos/featured-'.md5($row['email']).'.png',
                $row['brand_name'],
                $row['color']
            );

            $user = User::query()->firstOrNew(['email' => $row['email']]);
            $user->fill([
                'name' => $row['name'],
                'phone' => $row['phone'],
                'role' => 'wholesale_distributor',
                'brand_name' => $row['brand_name'],
                'brand_logo' => $logoPath,
                'city_id' => $cityId,
                'country_id' => $countryId,
                'address' => 'ليبيا',
                'store_description' => 'موزع جملة معتمد — '.$row['brand_name'],
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
                'is_featured_wholesaler' => true,
                'featured_sort' => $row['featured_sort'],
            ]);

            if (! $user->exists || blank($user->password)) {
                $user->password = Hash::make('password');
            }

            $user->save();
        }

        // Also flag any existing demo wholesaler if present
        User::query()
            ->where('role', 'wholesale_distributor')
            ->where('email', 'wholesaler@mgplastic.com')
            ->update([
                'is_featured_wholesaler' => true,
                'featured_sort' => 0,
                'brand_name' => User::query()
                    ->where('email', 'wholesaler@mgplastic.com')
                    ->value('brand_name') ?: 'موزع الجملة — خالد',
            ]);
    }

    protected function seedProductPromotions(): void
    {
        if (ProductPromotion::query()->where('is_active', true)->exists()) {
            $this->command?->info('Product promotions already exist — skipping create, ensuring active rows.');
        }

        $products = Product::query()->orderBy('id')->limit(3)->get();
        $productIds = $products->pluck('id')->values();

        $promos = [
            [
                'title' => 'أنابيب MG — جودة مصنعية',
                'subtitle' => 'تحمل الضغط وسهولة التركيب',
                'body' => 'منتجات MG البلاستيكية مصممة لمشاريع السباكة في ليبيا بمعايير عالية.',
                'media_type' => 'image',
                'sort_order' => 1,
                'color' => [13, 45, 110],
            ],
            [
                'title' => 'مقارنة: MG مقابل التقليدي',
                'subtitle' => 'عمر أطول وصيانة أقل',
                'body' => 'قارن بنفسك بين أنابيب MG والحلول التقليدية من حيث المتانة والسعر.',
                'media_type' => 'comparison',
                'sort_order' => 2,
                'color' => [180, 83, 9],
            ],
            [
                'title' => 'عروض الجملة هذا الأسبوع',
                'subtitle' => 'للموزعين والتجار المعتمدين',
                'body' => 'اطلب عبر التطبيق من المصنع أو المخزن المركزي واستفد من نقاط الشبكة.',
                'media_type' => 'image',
                'sort_order' => 3,
                'color' => [5, 150, 105],
            ],
        ];

        foreach ($promos as $i => $promo) {
            $title = $promo['title'];
            $existing = ProductPromotion::query()->where('title', $title)->first();

            $imagePath = $this->makePlaceholderImage(
                'product-promotions/seed-'.($i + 1).'.png',
                $title,
                $promo['color']
            );

            $comparisonPath = null;
            if ($promo['media_type'] === 'comparison') {
                $comparisonPath = $this->makePlaceholderImage(
                    'product-promotions/seed-'.($i + 1).'-compare.png',
                    'مقارنة',
                    [100, 100, 100]
                );
            }

            $payload = [
                'subtitle' => $promo['subtitle'],
                'body' => $promo['body'],
                'media_type' => $promo['media_type'],
                'image_path' => $imagePath,
                'comparison_image_path' => $comparisonPath,
                'video_url' => null,
                'product_id' => $productIds->get($i),
                'product_url' => null,
                'sort_order' => $promo['sort_order'],
                'is_active' => true,
            ];

            if ($existing) {
                $existing->fill($payload)->save();
            } else {
                ProductPromotion::query()->create([
                    'title' => $title,
                    ...$payload,
                ]);
            }
        }
    }

    /**
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    protected function makePlaceholderImage(string $relativePath, string $label, array $rgb): string
    {
        if (Storage::disk('public')->exists($relativePath)) {
            return $relativePath;
        }

        $width = 800;
        $height = 450;
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        $white = imagecolorallocate($img, 255, 255, 255);
        $text = mb_substr(preg_replace('/\s+/', ' ', $label) ?: 'MG', 0, 28);
        // Built-in font (no TTF dependency)
        imagestring($img, 5, 24, (int) ($height / 2 - 8), $text, $white);

        ob_start();
        imagepng($img);
        $binary = ob_get_clean();
        imagedestroy($img);

        Storage::disk('public')->put($relativePath, $binary ?: '');

        return $relativePath;
    }
}
