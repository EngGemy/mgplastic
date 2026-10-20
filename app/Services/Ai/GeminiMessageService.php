<?php

namespace App\Services\Ai;

use App\Models\AiMessageCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiMessageService
{
    public function compose(string $kind, array $facts, array $options = []): ?string
    {
        $cacheKey = hash('sha256', $kind.'|'.json_encode($facts, JSON_UNESCAPED_UNICODE));
        $cached = AiMessageCache::query()
            ->where('key', $cacheKey)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
        if ($cached) {
            return $cached->text;
        }

        $budgetKey = 'ai:daily_calls:'.now()->toDateString();
        $used = (int) Cache::get($budgetKey, 0);
        $limit = (int) config('services.gemini.daily_limit', 300);

        if ($used >= $limit || ! filled(config('services.gemini.key'))) {
            return $this->fallback($kind, $facts);
        }

        try {
            $text = $this->callGemini($kind, $facts);
            Cache::increment($budgetKey);
            Cache::put($budgetKey, (int) Cache::get($budgetKey, 0), now()->endOfDay());

            if (! $this->validate($text, $facts)) {
                return $this->fallback($kind, $facts);
            }

            AiMessageCache::query()->updateOrCreate(
                ['key' => $cacheKey],
                ['kind' => $kind, 'text' => $text, 'created_at' => now(), 'expires_at' => now()->addDays(7)]
            );

            return $text;
        } catch (\Throwable $e) {
            Log::warning('[Gemini] compose failed', ['kind' => $kind, 'error' => $e->getMessage()]);

            return $this->fallback($kind, $facts);
        }
    }

    protected function callGemini(string $kind, array $facts): string
    {
        $key = (string) config('services.gemini.key');
        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $timeout = min(8, (int) config('services.gemini.timeout', 8));

        $system = 'اكتب رسالة واتساب عربية ليبية مبسّطة ودودة، 2-4 أسطر فقط. '
            .'بدون مبالغة ولا وعود مالية. لا تخترع أرقامًا — استخدم الأرقام الواردة حرفيًا. '
            .'إيموجي معتدل (1-2). سطر ختامي قصير. بدون روابط.';

        $user = "النوع: {$kind}\nالحقائق:\n".json_encode($facts, JSON_UNESCAPED_UNICODE);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $response = Http::timeout($timeout)
            ->retry(1, 200, throw: false)
            ->post($url, [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $system."\n\n".$user]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.6,
                    'maxOutputTokens' => 256,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');

        return trim($text);
    }

    protected function validate(?string $text, array $facts): bool
    {
        if (! filled($text) || mb_strlen($text) > 400) {
            return false;
        }
        if (preg_match('#https?://#i', $text)) {
            return false;
        }
        $numbers = [];
        array_walk_recursive($facts, function ($v) use (&$numbers) {
            if (is_numeric($v)) {
                $numbers[] = (string) $v;
            }
        });
        preg_match_all('/\d[\d,]*/u', $text, $m);
        foreach ($m[0] as $num) {
            $plain = str_replace(',', '', $num);
            $ok = false;
            foreach ($numbers as $allowed) {
                if ($plain === str_replace(',', '', (string) $allowed) || str_contains((string) $allowed, $plain)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok && $plain !== '') {
                return false;
            }
        }

        return true;
    }

    public function fallback(string $kind, array $facts): string
    {
        $templates = (array) config('reminder_templates.'.$kind, config('reminder_templates.daily_plumber', []));
        if ($templates === []) {
            return 'افتح تطبيق MG لمتابعة نشاطك.';
        }
        $idx = ((int) now()->format('z') + strlen($kind)) % count($templates);
        $tpl = $templates[$idx];
        $map = [];
        foreach ($facts as $k => $v) {
            $map['{'.$k.'}'] = is_scalar($v) ? (string) $v : '';
        }

        return strtr($tpl, $map);
    }
}
