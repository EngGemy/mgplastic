<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->string('tag')->nullable()->after('type');
            $table->string('title')->nullable()->after('tag');
            $table->text('description')->nullable()->after('title');
            $table->string('cta_primary_text')->nullable()->after('description');
            $table->string('cta_primary_url')->nullable()->after('cta_primary_text');
            $table->string('cta_secondary_text')->nullable()->after('cta_primary_url');
            $table->string('cta_secondary_url')->nullable()->after('cta_secondary_text');
            $table->string('background_style')->nullable()->after('cta_secondary_url');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('background_style');
            $table->boolean('is_active')->default(true)->after('sort_order');
        });

        Schema::create('website_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('value')->default(0);
            $table->string('suffix')->nullable();
            $table->string('label_ar');
            $table->string('label_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('website_services', function (Blueprint $table) {
            $table->id();
            $table->string('icon')->default('ti-package');
            $table->string('title_ar');
            $table->string('subtitle_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('website_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('MG Plastic');
            $table->string('site_domain')->default('mg-plastic.ly');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('about_eyebrow')->nullable();
            $table->string('about_title')->nullable();
            $table->string('about_subtitle')->nullable();
            $table->json('about_paragraphs')->nullable();
            $table->string('about_image')->nullable();
            $table->string('about_badge_year')->nullable();
            $table->string('about_badge_text')->nullable();
            $table->json('about_values')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_whatsapp')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_address')->nullable();
            $table->string('contact_address_detail')->nullable();
            $table->string('contact_work_days')->nullable();
            $table->string('contact_work_hours')->nullable();
            $table->decimal('map_latitude', 10, 7)->default(32.8872);
            $table->decimal('map_longitude', 10, 7)->default(13.1913);
            $table->string('points_eyebrow')->nullable();
            $table->string('points_title')->nullable();
            $table->string('points_subtitle')->nullable();
            $table->json('points_chain')->nullable();
            $table->json('points_features')->nullable();
            $table->string('footer_tagline')->nullable();
            $table->string('catalog_pdf')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_settings');
        Schema::dropIfExists('website_services');
        Schema::dropIfExists('website_stats');

        Schema::table('sliders', function (Blueprint $table) {
            $table->dropColumn([
                'tag', 'title', 'description',
                'cta_primary_text', 'cta_primary_url',
                'cta_secondary_text', 'cta_secondary_url',
                'background_style', 'sort_order', 'is_active',
            ]);
        });
    }
};
