<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    public function enabled(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public function model(): string
    {
        return (string) config('services.gemini.model', 'gemini-2.0-flash');
    }

    /**
     * Ask Gemini a business question with structured JSON context.
     *
     * @param  array<string, mixed>  $context
     * @return array{ok:bool, answer:?string, error:?string, model:string}
     */
    public function ask(string $question, array $context = []): array
    {
        if (! $this->enabled()) {
            return [
                'ok' => false,
                'answer' => null,
                'error' => 'Gemini غير مفعّل — أضف GEMINI_API_KEY في ملف البيئة ثم أعد تحميل الإعدادات.',
                'model' => $this->model(),
            ];
        }

        $question = trim($question);
        if ($question === '') {
            return [
                'ok' => false,
                'answer' => null,
                'error' => 'اكتب سؤالاً أولاً',
                'model' => $this->model(),
            ];
        }

        $system = "أنت مستشار أعمال لنظام MG Plastic (شبكة توزيع بلاستيك في ليبيا: مصنع → جملة → قطاعي → سباك).\n"
            ."جاوب بالعربية الفصحى المبسّطة، باختصار واضح لصانع القرار.\n"
            ."اعتمد فقط على البيانات المعطاة. إن نقصت معلومة قل ذلك صراحة.\n"
            ."اقترح قرارات عملية (1–5 نقاط) عند الإمكان.";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $system."\n\n=== بيانات التقرير ===\n"
                                .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                                ."\n\n=== سؤال الإدارة ===\n".$question,
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 1024,
            ],
        ];

        $model = $this->model();
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent';

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('services.gemini.timeout', 45))
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('[Gemini] request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'answer' => null,
                    'error' => 'تعذّر الاتصال بـ Gemini ('.$response->status().') — تحقق من المفتاح والنموذج.',
                    'model' => $model,
                ];
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($text) || trim($text) === '') {
                return [
                    'ok' => false,
                    'answer' => null,
                    'error' => 'Gemini لم يُرجع نصاً صالحاً',
                    'model' => $model,
                ];
            }

            return [
                'ok' => true,
                'answer' => trim($text),
                'error' => null,
                'model' => $model,
            ];
        } catch (\Throwable $e) {
            Log::warning('[Gemini] exception', ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                'answer' => null,
                'error' => 'خطأ أثناء سؤال Gemini: '.$e->getMessage(),
                'model' => $model,
            ];
        }
    }
}
