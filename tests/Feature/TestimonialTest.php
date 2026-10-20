<?php

namespace Tests\Feature;

use App\Models\Testimonial;
use App\Services\Testimonials\TestimonialGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestimonialTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_returns_active_testimonials(): void
    {
        Testimonial::query()->create([
            'name_ar' => 'أحمد الترهوني',
            'role_key' => 'plumber',
            'role_label_ar' => 'سباك محترف',
            'city_ar' => 'طرابلس',
            'body_ar' => 'منتجات MG ممتازة وأنصح بها بقوة لكل السباكين في الشبكة.',
            'rating' => 5,
            'product_hint' => 'أنابيب PVC',
            'source' => 'seed',
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/mobile/testimonials')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.items.0.rating', 5)
            ->assertJsonPath('data.items.0.name', 'أحمد الترهوني');
    }

    public function test_generator_fallback_without_gemini_key(): void
    {
        config(['services.gemini.key' => null, 'testimonials.enabled' => true]);

        $rows = app(TestimonialGeneratorService::class)->generateDaily(1);

        $this->assertCount(1, $rows);
        $this->assertSame(5, (int) $rows[0]->rating);
        $this->assertContains($rows[0]->source, ['seed', 'gemini']);
        $this->assertDatabaseCount('testimonials', 1);
    }

    public function test_generator_uses_gemini_when_available(): void
    {
        config([
            'services.gemini.key' => 'fake',
            'services.gemini.model' => 'gemini-2.0-flash',
            'testimonials.enabled' => true,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => 'استخدمت منتجات MG في مشروعي والطريقة ممتازة. أنابيب PVC ضغط من MG تستاهل خمس نجوم عن جد.',
                    ]]],
                ]],
            ], 200),
        ]);

        $rows = app(TestimonialGeneratorService::class)->generateDaily(1);
        $this->assertCount(1, $rows);
        $this->assertSame(5, (int) $rows[0]->rating);
    }
}
