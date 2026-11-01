<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
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

    public function dailyBudgetRemaining(): int
    {
        $limit = max(0, (int) config('services.gemini.daily_limit', 300));
        $used = (int) Cache::get($this->dailyBudgetKey(), 0);

        return max(0, $limit - $used);
    }

    protected function dailyBudgetKey(): string
    {
        return 'ai:daily_calls:'.now()->toDateString();
    }

    protected function hitDailyBudget(): void
    {
        $key = $this->dailyBudgetKey();
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key);
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

        $system = 'أنت مستشار قرارات تشغيلية داخل غرفة صناعة القرار لنظام MG Plastic '
            ."(شبكة توزيع بلاستيك في ليبيا: مصنع → جملة → قطاعي → سباك).\n"
            ."الجمهور: صانع قرار إداري — جاوب بالعربية الفصحى المبسّطة، باختصار حاد وواضح.\n"
            ."اعتمد فقط على بيانات التقرير المعطاة. إن نقصت معلومة قل ذلك صراحة بلا تخمين.\n"
            ."صيغة الإجابة المفضّلة:\n"
            ."1) الخلاصة في سطرين\n"
            ."2) القرارات المقترحة (1–5) كل قرار: ماذا / لماذا / خطوة أولى\n"
            .'3) مخاطر أو ما يجب مراقبته إن وُجد';

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

    /**
     * Extract structured invoice fields from an image (handwritten / thermal / printed).
     *
     * @return array{ok:bool, draft:?array, error:?string, model:string, elapsed_ms:int}
     */
    public function extractInvoiceFromImage(string $absolutePath, string $mimeType = 'image/jpeg'): array
    {
        $started = microtime(true);
        $model = $this->model();

        if (! $this->enabled()) {
            return [
                'ok' => false,
                'draft' => null,
                'error' => 'Gemini غير مفعّل — أضف GEMINI_API_KEY ثم أعد تحميل الإعدادات.',
                'model' => $model,
                'elapsed_ms' => 0,
            ];
        }

        if ($this->dailyBudgetRemaining() <= 0) {
            return [
                'ok' => false,
                'draft' => null,
                'error' => 'تم استهلاك حد طلبات الذكاء اليومي — حاول غداً.',
                'model' => $model,
                'elapsed_ms' => 0,
            ];
        }

        if (! is_readable($absolutePath)) {
            return [
                'ok' => false,
                'draft' => null,
                'error' => 'تعذّر قراءة صورة الفاتورة من التخزين.',
                'model' => $model,
                'elapsed_ms' => 0,
            ];
        }

        $binary = file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            return [
                'ok' => false,
                'draft' => null,
                'error' => 'ملف الصورة فارغ أو غير قابل للقراءة.',
                'model' => $model,
                'elapsed_ms' => 0,
            ];
        }

        $prompt = <<<'PROMPT'
أنت محرك OCR لفواتير عربية (ليبيا/مصر). الصورة قد تكون: خط يد، إيصال حراري، أو مطبوعة.
استخرج الحقول بدقة دون اختراع بنود غير ظاهرة.
أرجع JSON فقط بهذا الشكل (بدون markdown):
{
  "vendor": "string|null",
  "number": "string|null",
  "date": "YYYY-MM-DD|null",
  "currency": "LYD|EGP|string|null",
  "subtotal": number|null,
  "tax": number|null,
  "total": number|null,
  "lines": [
    {"name":"string","qty":number,"unit_price":number|null,"line_total":number|null,"confidence":0.0}
  ],
  "overall_confidence": 0.0,
  "source_type": "handwritten|thermal|printed|unknown"
}
الأرقام بالعربية أو اللاتينية مقبولة — حوّلها لأرقام لاتينية في JSON.
إن لم يتضح حقل فاجعله null. confidence من 0 إلى 1.
PROMPT;

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => base64_encode($binary),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 2048,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent';

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout(max(15, (int) config('services.gemini.ocr_timeout', 60)))
                ->post($url, $payload);

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);

            if (! $response->successful()) {
                Log::warning('[Gemini] OCR request failed', [
                    'status' => $response->status(),
                    'elapsed_ms' => $elapsedMs,
                ]);

                return [
                    'ok' => false,
                    'draft' => null,
                    'error' => 'تعذّر الاتصال بـ Gemini ('.$response->status().')',
                    'model' => $model,
                    'elapsed_ms' => $elapsedMs,
                ];
            }

            $this->hitDailyBudget();

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($text) || trim($text) === '') {
                return [
                    'ok' => false,
                    'draft' => null,
                    'error' => 'Gemini لم يُرجع بيانات فاتورة صالحة',
                    'model' => $model,
                    'elapsed_ms' => $elapsedMs,
                ];
            }

            $draft = $this->parseInvoiceDraftJson($text);
            if ($draft === null) {
                return [
                    'ok' => false,
                    'draft' => null,
                    'error' => 'تعذّر تفسير نتيجة قراءة الفاتورة',
                    'model' => $model,
                    'elapsed_ms' => $elapsedMs,
                ];
            }

            return [
                'ok' => true,
                'draft' => $draft,
                'error' => null,
                'model' => $model,
                'elapsed_ms' => $elapsedMs,
            ];
        } catch (\Throwable $e) {
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            Log::warning('[Gemini] OCR exception', [
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);

            return [
                'ok' => false,
                'draft' => null,
                'error' => 'خطأ أثناء قراءة الفاتورة: '.$e->getMessage(),
                'model' => $model,
                'elapsed_ms' => $elapsedMs,
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseInvoiceDraftJson(string $text): ?array
    {
        $clean = trim($text);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        }

        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            return null;
        }

        $lines = [];
        foreach ((array) ($decoded['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $name = trim((string) ($line['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $lines[] = [
                'name' => $name,
                'qty' => $this->toFloat($line['qty'] ?? 1) ?? 1.0,
                'unit_price' => $this->toFloat($line['unit_price'] ?? null),
                'line_total' => $this->toFloat($line['line_total'] ?? null),
                'confidence' => max(0, min(1, (float) ($line['confidence'] ?? 0.5))),
            ];
        }

        $source = (string) ($decoded['source_type'] ?? 'unknown');
        if (! in_array($source, ['handwritten', 'thermal', 'printed', 'unknown'], true)) {
            $source = 'unknown';
        }

        return [
            'vendor' => $this->nullableString($decoded['vendor'] ?? null),
            'number' => $this->nullableString($decoded['number'] ?? null),
            'date' => $this->nullableString($decoded['date'] ?? null),
            'currency' => $this->nullableString($decoded['currency'] ?? null) ?? 'LYD',
            'subtotal' => $this->toFloat($decoded['subtotal'] ?? null),
            'tax' => $this->toFloat($decoded['tax'] ?? null),
            'total' => $this->toFloat($decoded['total'] ?? null),
            'lines' => $lines,
            'overall_confidence' => max(0, min(1, (float) ($decoded['overall_confidence'] ?? 0.5))),
            'source_type' => $source,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 3);
        }
        $normalized = str_replace(['٬', ',', ' '], ['', '.', ''], (string) $value);
        $normalized = preg_replace('/[^\d.\-]/', '', $normalized) ?? '';
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
    }
}
