<?php

namespace App\Services\Testimonials;

use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestimonialGeneratorService
{
    /**
     * Generate and persist N new testimonials (Gemini → fallback templates).
     *
     * @return list<Testimonial>
     */
    public function generateDaily(?int $count = null): array
    {
        if (! config('testimonials.enabled', true)) {
            return [];
        }

        $count = $count ?? (int) config('testimonials.daily_count', 2);
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $persona = $this->pickPersona();
            $product = $this->pickProductHint();
            $body = $this->composeBody($persona, $product);

            $created[] = Testimonial::query()->create([
                'name_ar' => $persona['name'],
                'role_key' => $persona['role_key'],
                'role_label_ar' => $persona['role_label'],
                'city_ar' => $persona['city'],
                'body_ar' => $body['text'],
                'rating' => 5,
                'product_hint' => $product,
                'avatar_initials' => $this->initials($persona['name']),
                'source' => $body['source'],
                'is_active' => true,
                'is_featured' => $i === 0,
                'sort_order' => 0,
                'published_at' => now(),
            ]);
        }

        return $created;
    }

    /** @return array{name:string,role_key:string,role_label:string,city:string} */
    public function pickPersona(): array
    {
        $names = (array) config('testimonials.names', []);
        $cities = (array) config('testimonials.cities', []);
        $roles = (array) config('testimonials.roles', []);
        $roleKey = (string) array_rand($roles);

        return [
            'name' => $names[array_rand($names)] ?? 'أحمد الترهوني',
            'role_key' => $roleKey,
            'role_label' => (string) ($roles[$roleKey] ?? 'متعامل'),
            'city' => $cities[array_rand($cities)] ?? 'طرابلس',
        ];
    }

    public function pickProductHint(): string
    {
        try {
            $name = Product::query()
                ->with('translations')
                ->inRandomOrder()
                ->limit(20)
                ->get()
                ->map(fn (Product $p) => optional($p->translate('ar'))->name)
                ->filter()
                ->first();
            if (filled($name)) {
                return (string) $name;
            }
        } catch (\Throwable) {
        }

        $fallback = [
            'أنابيب PVC ضغط', 'وصلات UPVC', 'محابس نحاس', 'سيفون أرضي', 'خزان مياه بلاستيك',
            'مواسير صرف صحي', 'كوع 90 درجة', 'تيه صحي', 'صمام عدم رجوع',
        ];

        return $fallback[array_rand($fallback)];
    }

    /**
     * @param  array{name:string,role_key:string,role_label:string,city:string}  $persona
     * @return array{text:string,source:string}
     */
    public function composeBody(array $persona, string $product): array
    {
        $facts = [
            'name' => $persona['name'],
            'role' => $persona['role_label'],
            'city' => $persona['city'],
            'product' => $product,
            'company' => 'MG للصناعات البلاستيكية',
            'rating' => 5,
        ];

        $ai = $this->callGemini($facts);
        if ($ai && $this->validateBody($ai, $facts)) {
            return ['text' => $ai, 'source' => 'gemini'];
        }

        return ['text' => $this->fallbackBody($facts), 'source' => 'seed'];
    }

    /** @param  array<string, mixed>  $facts */
    protected function callGemini(array $facts): ?string
    {
        $key = (string) config('services.gemini.key');
        if ($key === '') {
            return null;
        }

        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $timeout = min(8, (int) config('services.gemini.timeout', 8));

        $system = 'اكتب شهادة عميل عربية ليبية مبسّطة وإيجابية عن منتجات شركة MG للصناعات البلاستيكية. '
            .'سطران إلى أربعة أسطر. تقييم خمس نجوم ضمنيًا. اذكر المنتج المعطى حرفيًا. '
            .'بدون أرقام أسعار أو هواتف أو وعود مالية. بدون روابط. نبرة مهنية ودودة.';

        $user = 'الاسم: '.$facts['name']."\n"
            .'الصفة: '.$facts['role']."\n"
            .'المدينة: '.$facts['city']."\n"
            .'المنتج: '.$facts['product']."\n"
            .'الشركة: '.$facts['company'];

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
            $response = Http::timeout($timeout)
                ->retry(1, 200, throw: false)
                ->post($url, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $system."\n\n".$user]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.85,
                        'maxOutputTokens' => 280,
                    ],
                ]);

            if (! $response->successful()) {
                return null;
            }

            return trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
        } catch (\Throwable $e) {
            Log::warning('[Testimonials] Gemini failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param  array<string, mixed>  $facts */
    protected function validateBody(?string $text, array $facts): bool
    {
        if (! filled($text) || mb_strlen($text) > 500 || mb_strlen($text) < 40) {
            return false;
        }
        if (preg_match('#https?://#i', $text)) {
            return false;
        }
        // Product name should appear (soft check)
        $product = (string) ($facts['product'] ?? '');
        if ($product !== '' && ! str_contains($text, $product) && ! str_contains($text, 'MG')) {
            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $facts */
    protected function fallbackBody(array $facts): string
    {
        $templates = [
            'من يوم ما بدّلت لـ {product} من {company} الشغل صار أنظف وأسرع. جودة ثابتة وتوريد منتظم — أنصح أي {role} في {city}.',
            'تعاملت مع {company} على {product}. الخامة متينة والقياسات مضبوطة. تقييم خمس نجوم عن تجربة حقيقية في {city}.',
            'كـ{role} يعتمد على البضاعة كل يوم، لقيت في {product} من MG حل عملي وسعر مناسب للشبكة. شكراً للفريق.',
            '{product} من {company} غيّر جودة التركيبات عندنا. توصيل واضح وخدمة محترمة — يستاهلوا الخمس نجوم.',
            'جربت أكثر من ماركة، وبقيت مع {company}. {product} يتحمّل الضغط وما يسبب لي مشاكل في الموقع.',
        ];
        $tpl = $templates[array_rand($templates)];
        $map = [];
        foreach ($facts as $k => $v) {
            $map['{'.$k.'}'] = is_scalar($v) ? (string) $v : '';
        }

        return strtr($tpl, $map);
    }

    protected function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = collect($parts)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

        return mb_strtoupper($letters ?: mb_substr($name, 0, 2));
    }
}
