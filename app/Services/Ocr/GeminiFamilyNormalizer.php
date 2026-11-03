<?php

namespace App\Services\Ocr;

use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optional text-only Gemini fallback for unknown tokens.
 * OFF by default (ocr_lexicon.gemini_normalizer.enabled).
 * Never auto-accepts — stores pending aliases only.
 */
class GeminiFamilyNormalizer
{
    /**
     * @return array{family:?string, confidence:float, source:string}|null
     */
    public function normalizeToken(string $token): ?array
    {
        if (! config('ocr_lexicon.gemini_normalizer.enabled')) {
            return null;
        }

        $token = ArabicTextNormalizer::normalize($token);
        if ($token === '' || mb_strlen($token) < 2) {
            return null;
        }

        $cacheDays = max(1, (int) config('ocr_lexicon.gemini_normalizer.cache_days', 30));
        $cacheKey = 'ocr:gemini_norm:v1:'.$token;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $dayKey = 'ocr:gemini_norm:daily:'.now()->toDateString();
        $used = (int) Cache::get($dayKey, 0);
        $cap = (int) config('ocr_lexicon.gemini_normalizer.daily_cap', 50);
        if ($used >= $cap) {
            Log::channel('ocr')->info('[OCR] gemini_normalizer_capped', ['used' => $used]);

            return null;
        }

        $families = array_keys((array) config('ocr_lexicon.families', []));
        $families[] = 'unknown';
        $key = (string) config('services.gemini.key');
        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
        if ($key === '') {
            return null;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';
        $prompt = 'Map this plumbing trade token to exactly one family key from the enum, or unknown. Token: '.$token;

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $key,
                'Content-Type' => 'application/json',
            ])
                ->timeout(20)
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'family' => ['type' => 'STRING', 'enum' => $families],
                                'confidence' => ['type' => 'NUMBER'],
                            ],
                            'required' => ['family', 'confidence'],
                        ],
                    ],
                ]);

            Cache::put($dayKey, $used + 1, now()->endOfDay());
            Log::channel('ocr')->info('[OCR] gemini_normalizer_call', [
                'token_len' => mb_strlen($token),
                'status' => $response->status(),
            ]);

            if (! $response->successful()) {
                return null;
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            $decoded = json_decode((string) $text, true);
            if (! is_array($decoded)) {
                return null;
            }

            $family = (string) ($decoded['family'] ?? 'unknown');
            $confidence = (float) ($decoded['confidence'] ?? 0);
            $result = [
                'family' => $family === 'unknown' ? null : $family,
                'confidence' => $confidence,
                'source' => 'gemini',
            ];

            Cache::put($cacheKey, $result, now()->addDays($cacheDays));

            if ($result['family']) {
                app(OcrTermAliasService::class)->upsertPending(
                    $token,
                    $result['family'],
                    null,
                    $token,
                    'gemini'
                );
            }

            return $result;
        } catch (\Throwable $e) {
            Log::channel('ocr')->warning('[OCR] gemini_normalizer_error', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
