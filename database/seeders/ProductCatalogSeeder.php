<?php
// database/seeders/ProductCatalogSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\ProductCategory;
use App\Models\ProductStandard;
use App\Models\ProductColor;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // -------- Admin user (creator/owner of all products) --------
        $admin = User::firstOrCreate(
            ['phone' => '+201000000000'], // unique key
            [
                'name'              => 'Site Admin',
                'email'             => 'admin@example.com',
                'role'              => 'admin',  // make sure your app recognizes this role
                'country_id'        => 1,
                'city_id'           => 1,
                'password'          => Hash::make('password'),
                'remember_token'    => Str::random(10),
                'is_phone_verified' => true,
            ]
        );

        // -------- Standards --------
        $std114 = ProductStandard::firstOrCreate(
            ['code' => '114'],
            ['name_en' => 'American System (114)', 'name_ar' => 'النظام الأمريكي (114)']
        );
        $std110 = ProductStandard::firstOrCreate(
            ['code' => '110'],
            ['name_en' => 'European System (110)', 'name_ar' => 'النظام الأوروبي (110)']
        );

        // -------- Colors --------
        $orange = ProductColor::firstOrCreate(['name' => 'Orange']);
        $gray   = ProductColor::firstOrCreate(['name' => 'Gray']);
        $white  = ProductColor::firstOrCreate(['name' => 'White']);

        // -------- Categories --------
        $catAmerican = ProductCategory::firstOrCreate(['slug' => 'american-114'], ['image' => 'categories/american-114.jpg']);
        $catAmerican->translateOrNew('en')->name = 'Sewerage System - American (114)';
        $catAmerican->translateOrNew('en')->description = 'Pipes and accessories per USA/ASME.';
        $catAmerican->translateOrNew('ar')->name = 'أنظمة الصرف الصحي - النظام الأمريكي (114)';
        $catAmerican->translateOrNew('ar')->description = 'أنابيب وملحقات طبقًا للمواصفات الأمريكية.';
        $catAmerican->save();

        $catEuropean = ProductCategory::firstOrCreate(['slug' => 'european-110'], ['image' => 'categories/european-110.jpg']);
        $catEuropean->translateOrNew('en')->name = 'Sewerage System - European (110)';
        $catEuropean->translateOrNew('en')->description = 'Pipes and accessories per EU/DIN.';
        $catEuropean->translateOrNew('ar')->name = 'أنظمة الصرف الصحي - النظام الأوروبي (110)';
        $catEuropean->translateOrNew('ar')->description = 'أنابيب وملحقات طبقًا للمواصفات الأوروبية.';
        $catEuropean->save();

        $catExternal = ProductCategory::firstOrCreate(['slug' => 'external-sewer'], ['image' => 'categories/external-sewer.jpg']);
        $catExternal->translateOrNew('en')->name = 'External Sewer Pipes & Infrastructure';
        $catExternal->translateOrNew('ar')->name = 'أنابيب الصرف الصحي الخارجي والبنية التحتية';
        $catExternal->save();

        $catHighPressure = ProductCategory::firstOrCreate(['slug' => 'high-pressure'], ['image' => 'categories/high-pressure.jpg']);
        $catHighPressure->translateOrNew('en')->name = 'High Pressure Irrigation Pipes';
        $catHighPressure->translateOrNew('ar')->name = 'أنابيب الضغوط العالية للري';
        $catHighPressure->save();

        $catWellCasing = ProductCategory::firstOrCreate(['slug' => 'well-casing'], ['image' => 'categories/well-casing.jpg']);
        $catWellCasing->translateOrNew('en')->name = 'Well Casing & Cable Protection';
        $catWellCasing->translateOrNew('ar')->name = 'تغليف الآبار وحماية الكوابل';
        $catWellCasing->save();

        $catAccessories = ProductCategory::firstOrCreate(['slug' => 'accessories'], ['image' => 'categories/accessories.jpg']);
        $catAccessories->translateOrNew('en')->name = 'Accessories (Elbows, Tees, Couplings, etc.)';
        $catAccessories->translateOrNew('ar')->name = 'الملحقات (أكواع، تي، وصلات...)';
        $catAccessories->save();

        // -------- Example product: External Sewer Pipe --------
        $p1 = Product::create([
            'user_id'              => $admin->id,            // << owner is admin
            'product_category_id'  => $catExternal->id,
            'product_standard_id'  => $std110->id,
            'product_color_id'     => $orange->id,
            'length_m'             => 6.00,
            'main_image'           => 'products/external-sewer/main.jpg',
            'meta'                 => ['note' => 'According to EU (110) & Libyan specs'],
        ]);
        $p1->translateOrNew('en')->name = 'External Sewer Pipe (Orange)';
        $p1->translateOrNew('en')->description = 'Outside diameter and wall thickness according to catalog tables.';
        $p1->translateOrNew('ar')->name = 'ماسورة صرف خارجي (برتقالي)';
        $p1->translateOrNew('ar')->description = 'أقطار وسماكات وفق الجداول.';
        $p1->save();

        ProductImage::create(['product_id' => $p1->id, 'image' => 'products/external-sewer/1.jpg', 'sort' => 1]);
        ProductImage::create(['product_id' => $p1->id, 'image' => 'products/external-sewer/2.jpg', 'sort' => 2]);

        ProductVariant::create([
            'product_id' => $p1->id,
            'outer_diameter_mm' => 110,
            'wall_thickness_mm' => 3.0,
            'insertion_depth_mm' => 115,
            'weight_kg_per_m' => 1.449
        ]);
        ProductVariant::create([
            'product_id' => $p1->id,
            'outer_diameter_mm' => 160,
            'wall_thickness_mm' => 3.6,
            'insertion_depth_mm' => 132,
            'weight_kg_per_m' => 2.529
        ]);

        // -------- Example product: High Pressure Pipe --------
        $p2 = Product::create([
            'user_id'             => $admin->id,            // << owner is admin
            'product_category_id' => $catHighPressure->id,
            'product_standard_id' => $std110->id,
            'product_color_id'    => $gray->id,
            'length_m'            => 6.00,
            'main_image'          => 'products/high-pressure/main.jpg',
        ]);
        $p2->translateOrNew('en')->name = 'High Pressure Pipe';
        $p2->translateOrNew('ar')->name = 'ماسورة ضغط عالي';
        $p2->save();

        ProductVariant::create([
            'product_id' => $p2->id,
            'outer_diameter_mm' => 110,
            'wall_thickness_mm' => 3.2,
            'pressure_class'    => 'Pn6'
        ]);
        ProductVariant::create([
            'product_id' => $p2->id,
            'outer_diameter_mm' => 110,
            'wall_thickness_mm' => 5.3,
            'pressure_class'    => 'Pn10'
        ]);
        ProductVariant::create([
            'product_id' => $p2->id,
            'outer_diameter_mm' => 110,
            'wall_thickness_mm' => 8.1,
            'pressure_class'    => 'Pn16'
        ]);

        // -------- Accessories: Elbow 90° --------
        $elbow90 = Product::create([
            'user_id'             => $admin->id,            // << owner is admin
            'product_category_id' => $catAccessories->id,
            'product_color_id'    => $white->id,
            'main_image'          => 'products/accessories/elbow90.jpg',
        ]);
        $elbow90->translateOrNew('en')->name = 'Elbow 90°';
        $elbow90->translateOrNew('ar')->name = 'كوع 90°';
        $elbow90->save();

        ProductVariant::create([
            'product_id'       => $elbow90->id,
            'catalog_code'     => '00095',
            'width_w_mm'       => 3.0,
            'height_l_mm'      => 5.0,
            'depth_h_mm'       => 0.28,
            'outer_diameter_mm'=> 50.0,
            'wall_thickness_mm'=> 1.0
        ]);
        ProductVariant::create([
            'product_id'       => $elbow90->id,
            'catalog_code'     => '00174',
            'width_w_mm'       => 8.5,
            'height_l_mm'      => 8.5,
            'depth_h_mm'       => 0.47,
            'outer_diameter_mm'=> 90.0,
            'wall_thickness_mm'=> 5.0
        ]);

        // -------- Accessories: Coupling --------
        $coupling = Product::create([
            'user_id'             => $admin->id,            // << owner is admin
            'product_category_id' => $catAccessories->id,
            'product_color_id'    => $white->id,
            'main_image'          => 'products/accessories/coupling.jpg',
        ]);
        $coupling->translateOrNew('en')->name = 'Coupling';
        $coupling->translateOrNew('ar')->name = 'وصلة';
        $coupling->save();

        ProductVariant::create([
            'product_id'       => $coupling->id,
            'catalog_code'     => '00093',
            'width_w_mm'       => 6.0,
            'height_l_mm'      => 6.0,
            'depth_h_mm'       => 0.30,
            'outer_diameter_mm'=> 50.0,
            'wall_thickness_mm'=> 1.0
        ]);
    }
}
