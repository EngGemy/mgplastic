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
     * @param  list<string>  $catalogNames
     * @return array{ok:bool, draft:?array, error:?string, model:string, elapsed_ms:int, finish_reason:?string, cached?:bool}
     */
    public function extractInvoiceFromImage(
        string $absolutePath,
        string $mimeType = 'image/jpeg',
        array $catalogNames = [],
    ): array {
        $started = microtime(true);
        $model = $this->model();

        if (! $this->enabled()) {
            return $this->ocrFailure('disabled', 'Gemini غير مفعّل — أضف GEMINI_API_KEY ثم أعد تحميل الإعدادات.', $model, 0);
        }

        if ($this->dailyBudgetRemaining() <= 0) {
            return $this->ocrFailure('budget', 'تم استهلاك حد طلبات الذكاء اليومي — حاول غداً.', $model, 0);
        }

        if (! is_readable($absolutePath)) {
            return $this->ocrFailure('unreadable', 'تعذّر قراءة صورة الفاتورة من التخزين.', $model, 0);
        }

        $binary = file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            return $this->ocrFailure('empty', 'ملف الصورة فارغ أو غير قابل للقراءة.', $model, 0);
        }

        $prompt = $this->ocrPrompt($catalogNames);
        $generationConfig = [
            'temperature' => 0.1,
            'maxOutputTokens' => max(1024, (int) config('services.gemini.ocr_max_output_tokens', 8192)),
            'responseMimeType' => 'application/json',
            'responseSchema' => $this->ocrResponseSchema(),
        ];

        $includeThinking = $this->shouldSendThinkingConfig($model);
        if ($includeThinking) {
            $generationConfig['thinkingConfig'] = [
                'thinkingBudget' => (int) (config('services.gemini.ocr_thinking_budget') ?? 0),
            ];
        }

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
            'generationConfig' => $generationConfig,
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent';

        try {
            $response = $this->postOcr($url, $payload, $includeThinking);

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $json = $response->json();
            $finishReason = data_get($json, 'candidates.0.finishReason');
            $usage = data_get($json, 'usageMetadata');

            if (! $response->successful()) {
                $mapped = $this->mapHttpFailure($response->status());
                $this->ocrLog('warning', $mapped['code'], [
                    'status' => $response->status(),
                    'elapsed_ms' => $elapsedMs,
                    'model' => $model,
                    'finish_reason' => $finishReason,
                ]);

                return $this->ocrFailure($mapped['code'], $mapped['message'], $model, $elapsedMs, $finishReason);
            }

            if ($finishReason === 'MAX_TOKENS') {
                $this->ocrLog('warning', 'max_tokens', [
                    'elapsed_ms' => $elapsedMs,
                    'model' => $model,
                    'finish_reason' => $finishReason,
                    'usage' => $usage,
                ]);

                return $this->ocrFailure('max_tokens', 'انقطع الرد لطول الفاتورة — صغّر الصورة أو قلّل البنود الظاهرة ثم أعد المحاولة.', $model, $elapsedMs, $finishReason);
            }

            if ($finishReason === 'SAFETY') {
                $this->ocrLog('warning', 'safety', [
                    'elapsed_ms' => $elapsedMs,
                    'model' => $model,
                    'finish_reason' => $finishReason,
                ]);

                return $this->ocrFailure('safety', 'رفض النموذج قراءة الصورة لأسباب أمان — جرّب صورة أوضح بدون محتوى محجوب.', $model, $elapsedMs, $finishReason);
            }

            $this->hitDailyBudget();

            $text = data_get($json, 'candidates.0.content.parts.0.text');
            if (! is_string($text) || trim($text) === '') {
                $this->ocrLog('warning', 'empty_text', [
                    'elapsed_ms' => $elapsedMs,
                    'model' => $model,
                    'finish_reason' => $finishReason,
                    'usage' => $usage,
                ]);

                return $this->ocrFailure('empty_text', 'Gemini لم يُرجع بيانات فاتورة صالحة', $model, $elapsedMs, $finishReason);
            }

            $draft = $this->parseInvoiceDraftJson($text);
            if ($draft === null) {
                $this->ocrLog('warning', 'invalid_json', [
                    'elapsed_ms' => $elapsedMs,
                    'model' => $model,
                    'finish_reason' => $finishReason,
                ]);

                return $this->ocrFailure('invalid_json', 'تعذّر تفسير نتيجة قراءة الفاتورة (JSON غير صالح)', $model, $elapsedMs, $finishReason);
            }

            $this->ocrLog('info', 'ok', [
                'elapsed_ms' => $elapsedMs,
                'model' => $model,
                'finish_reason' => $finishReason,
                'usage' => $usage,
                'raw_line_count' => $draft['raw_line_count'] ?? null,
                'returned_line_count' => $draft['returned_line_count'] ?? count($draft['lines'] ?? []),
                'dropped_reasons' => $draft['dropped_reasons'] ?? [],
            ]);

            return [
                'ok' => true,
                'draft' => $draft,
                'error' => null,
                'model' => $model,
                'elapsed_ms' => $elapsedMs,
                'finish_reason' => is_string($finishReason) ? $finishReason : null,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $this->ocrLog('warning', 'timeout', [
                'elapsed_ms' => $elapsedMs,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return $this->ocrFailure('timeout', 'انتهت مهلة الاتصال بـ Gemini — أعد المحاولة.', $model, $elapsedMs);
        } catch (\Throwable $e) {
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $lower = strtolower($e->getMessage());
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'curl error 28')) {
                $this->ocrLog('warning', 'timeout', [
                    'elapsed_ms' => $elapsedMs,
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                return $this->ocrFailure('timeout', 'انتهت مهلة الاتصال بـ Gemini — أعد المحاولة.', $model, $elapsedMs);
            }

            $this->ocrLog('error', 'exception', [
                'elapsed_ms' => $elapsedMs,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return $this->ocrFailure('exception', 'خطأ أثناء قراءة الفاتورة', $model, $elapsedMs);
        }
    }

    /**
     * Narrow second-pass for a single low-confidence line (no full re-OCR).
     *
     * @return array{ok:bool, line:?array, error:?string, model:string, elapsed_ms:int}
     */
    public function refineInvoiceLine(string $absolutePath, string $mimeType, string $hintText): array
    {
        $started = microtime(true);
        $model = $this->model();
        if (! $this->enabled() || ! is_readable($absolutePath)) {
            return ['ok' => false, 'line' => null, 'error' => 'غير جاهز لإعادة القراءة', 'model' => $model, 'elapsed_ms' => 0];
        }

        $binary = file_get_contents($absolutePath);
        if ($binary === false) {
            return ['ok' => false, 'line' => null, 'error' => 'تعذّر قراءة الصورة', 'model' => $model, 'elapsed_ms' => 0];
        }

        $prompt = 'من صورة الفاتورة، أعد فقط JSON لبند واحد مرتبط بهذا النص الظاهر: '
            .json_encode($hintText, JSON_UNESCAPED_UNICODE)
            .' بالشكل {"raw_text":"...","name":"...","qty":1,"unit_price":null,"line_total":null,"confidence":0.0}';

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout(max(10, (int) config('services.gemini.ocr_timeout', 60)))
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent',
                    [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                                ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binary)]],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 512,
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            if (! $response->successful()) {
                return ['ok' => false, 'line' => null, 'error' => $this->mapHttpFailure($response->status())['message'], 'model' => $model, 'elapsed_ms' => $elapsedMs];
            }

            $this->hitDailyBudget();
            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            $decoded = is_string($text) ? json_decode(trim($text), true) : null;
            if (! is_array($decoded)) {
                return ['ok' => false, 'line' => null, 'error' => 'تعذّر تفسير إعادة القراءة', 'model' => $model, 'elapsed_ms' => $elapsedMs];
            }

            // Reject full-draft payloads accidentally returned by the model/fake.
            if (isset($decoded['lines']) && is_array($decoded['lines'])) {
                return ['ok' => false, 'line' => null, 'error' => 'إعادة القراءة أعادت مسودة كاملة بدل بند', 'model' => $model, 'elapsed_ms' => $elapsedMs];
            }

            return [
                'ok' => true,
                'line' => [
                    'raw_text' => $this->nullableString($decoded['raw_text'] ?? $decoded['name'] ?? $hintText),
                    'name' => $this->nullableString($decoded['name'] ?? $decoded['raw_text'] ?? $hintText) ?? $hintText,
                    'qty' => $this->toFloat($decoded['qty'] ?? null),
                    'unit_price' => $this->toFloat($decoded['unit_price'] ?? null),
                    'line_total' => $this->toFloat($decoded['line_total'] ?? null),
                    'confidence' => $this->normalizeConfidence($decoded['confidence'] ?? null),
                ],
                'error' => null,
                'model' => $model,
                'elapsed_ms' => $elapsedMs,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'line' => null, 'error' => 'فشل إعادة قراءة البند', 'model' => $model, 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
        }
    }

    /**
     * Completeness pass: ask only for lines when the first pass under-transcribed a numbered list.
     *
     * @return array{ok:bool, lines:list<array<string,mixed>>, error:?string}
     */
    public function extractLinesOnly(string $absolutePath, string $mimeType = 'image/jpeg'): array
    {
        if (! $this->enabled() || ! is_readable($absolutePath)) {
            return ['ok' => false, 'lines' => [], 'error' => 'غير جاهز'];
        }
        $binary = file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            return ['ok' => false, 'lines' => [], 'error' => 'صورة فارغة'];
        }

        $prompt = 'This is a handwritten Arabic numbered list. Transcribe EVERY visible line top to bottom, '
            .'one entry per number, do not skip, merge or stop early. '
            .'Return JSON {"lines":[{"raw_text":"...","name":"...","qty":number|null,"confidence":0.0}]}. '
            .'Quantity-only lines are valid (unit_price/line_total may be omitted). '
            .'Do not invent products. Convert Arabic-Indic digits to Latin in qty.';

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout(max(10, (int) config('services.gemini.ocr_timeout', 60)))
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($this->model()).':generateContent',
                    [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                                ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binary)]],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => max(1024, (int) config('services.gemini.ocr_max_output_tokens', 8192)),
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );

            if (! $response->successful()) {
                return ['ok' => false, 'lines' => [], 'error' => $this->mapHttpFailure($response->status())['message']];
            }

            $this->hitDailyBudget();
            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            $draft = is_string($text) ? $this->parseInvoiceDraftJson($text) : null;
            if ($draft === null) {
                return ['ok' => false, 'lines' => [], 'error' => 'تعذّر تفسير تمريرة الاكتمال'];
            }

            return ['ok' => true, 'lines' => $draft['lines'] ?? [], 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'lines' => [], 'error' => 'فشل تمريرة الاكتمال'];
        }
    }

    /** @param  list<string>  $catalogNames */
    protected function ocrPrompt(array $catalogNames): string
    {
        $base = <<<'PROMPT'
This is a handwritten / thermal / printed Arabic invoice or numbered list (Libya/Egypt).
Transcribe EVERY visible line top to bottom, one entry per number/row — do not skip, merge, or stop early.
Quantity-only lists are valid: unit_price and line_total may be null/omitted.
For each line return raw_text (as written), name (cleaned, without leading list index), qty, optional unit_price/line_total, confidence 0..1, optional brand (null if unmarked; never invent MG).
Convert Arabic-Indic digits to Latin in qty. Unknown fields = null. Do not invent lines.
PROMPT;

        if ($catalogNames !== []) {
            $base .= "\n\nOptional catalog hints (do NOT drop lines that are absent from this list):\n"
                .implode(' | ', array_slice($catalogNames, 0, (int) config('services.gemini.ocr_catalog_prompt_limit', 80)));
        }

        return $base;
    }

    /** @return array<string, mixed> */
    protected function ocrResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'vendor' => ['type' => 'STRING', 'nullable' => true],
                'number' => ['type' => 'STRING', 'nullable' => true],
                'date' => ['type' => 'STRING', 'nullable' => true],
                'currency' => ['type' => 'STRING', 'nullable' => true],
                'subtotal' => ['type' => 'NUMBER', 'nullable' => true],
                'tax' => ['type' => 'NUMBER', 'nullable' => true],
                'total' => ['type' => 'NUMBER', 'nullable' => true],
                'overall_confidence' => ['type' => 'NUMBER', 'nullable' => true],
                'source_type' => ['type' => 'STRING'],
                'lines' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'raw_text' => ['type' => 'STRING'],
                            'name' => ['type' => 'STRING', 'nullable' => true],
                            'qty' => ['type' => 'NUMBER', 'nullable' => true],
                            'unit_price' => ['type' => 'NUMBER', 'nullable' => true],
                            'line_total' => ['type' => 'NUMBER', 'nullable' => true],
                            'confidence' => ['type' => 'NUMBER', 'nullable' => true],
                            'brand' => ['type' => 'STRING', 'nullable' => true],
                        ],
                        // Quantity-only lists: do not require prices or confidence.
                        'required' => ['raw_text'],
                    ],
                ],
            ],
            'required' => ['lines', 'source_type'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postOcr(string $url, array $payload, bool $sentThinking): \Illuminate\Http\Client\Response
    {
        $response = Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(max(15, (int) config('services.gemini.ocr_timeout', 60)))
            ->post($url, $payload);

        // If thinkingConfig is rejected, retry once without it.
        if ($sentThinking && in_array($response->status(), [400, 404], true)) {
            $this->ocrLog('warning', 'thinking_config_rejected', [
                'status' => $response->status(),
                'model' => $this->model(),
            ]);
            unset($payload['generationConfig']['thinkingConfig']);

            return Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout(max(15, (int) config('services.gemini.ocr_timeout', 60)))
                ->post($url, $payload);
        }

        return $response;
    }

    protected function shouldSendThinkingConfig(string $model): bool
    {
        $budget = config('services.gemini.ocr_thinking_budget');
        if ($budget === null || $budget === '') {
            // Default low thinking for 2.5 / 3.x to cut latency; omit for classic 2.0-flash.
            if (preg_match('/gemini-3|gemini-2\.5/i', $model)) {
                return true;
            }

            return false;
        }

        return true;
    }

    /** @return array{code:string, message:string} */
    protected function mapHttpFailure(int $status): array
    {
        return match (true) {
            $status === 404 => ['code' => 'http_404', 'message' => 'نموذج Gemini غير موجود (404) — راجع GEMINI_MODEL في الإعدادات.'],
            $status === 429 => ['code' => 'http_429', 'message' => 'تم تجاوز حد طلبات Gemini مؤقتاً (429) — انتظر ثم أعد المحاولة.'],
            $status >= 500 => ['code' => 'http_5xx', 'message' => 'خدمة Gemini متوقفة مؤقتاً ('.$status.') — حاول لاحقاً.'],
            $status === 401, $status === 403 => ['code' => 'http_auth', 'message' => 'مفتاح Gemini مرفوض — تحقق من GEMINI_API_KEY.'],
            default => ['code' => 'http_'.$status, 'message' => 'تعذّر الاتصال بـ Gemini ('.$status.')'],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function ocrLog(string $level, string $reason, array $context = []): void
    {
        // Never log image bytes, API keys, or full phone numbers.
        Log::channel('ocr')->log($level, '[OCR] '.$reason, $context);
    }

    /**
     * @return array{ok:bool, draft:null, error:string, model:string, elapsed_ms:int, finish_reason:?string}
     */
    protected function ocrFailure(string $code, string $message, string $model, int $elapsedMs, ?string $finishReason = null): array
    {
        return [
            'ok' => false,
            'draft' => null,
            'error' => $message,
            'code' => $code,
            'model' => $model,
            'elapsed_ms' => $elapsedMs,
            'finish_reason' => $finishReason,
        ];
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

        $rawInputLines = (array) ($decoded['lines'] ?? []);
        $rawLineCount = count($rawInputLines);
        $lines = [];
        $droppedReasons = [];

        foreach ($rawInputLines as $index => $line) {
            if (! is_array($line)) {
                $reason = 'non_array_line';
                $droppedReasons[] = ['index' => $index, 'reason' => $reason];
                $this->ocrLog('warning', 'line_dropped', ['index' => $index, 'reason' => $reason]);

                continue;
            }

            $raw = trim((string) ($line['raw_text'] ?? $line['name'] ?? ''));
            $name = trim((string) ($line['name'] ?? $raw));
            $qty = $this->toFloat($line['qty'] ?? null);

            if ($raw === '' && $name === '' && $qty === null) {
                $reason = 'empty_text_and_qty';
                $droppedReasons[] = ['index' => $index, 'reason' => $reason];
                $this->ocrLog('warning', 'line_dropped', ['index' => $index, 'reason' => $reason]);

                continue;
            }

            $display = $name !== '' ? $name : $raw;
            [$lineNo, $cleanedName] = $this->stripLeadingListNumber($display);
            $cleanedName = $this->latinizeDigitsInText($cleanedName);

            $needsReview = [];
            if ($qty === null) {
                $needsReview[] = 'qty_missing';
            }
            if (($line['unit_price'] ?? null) === null && ($line['line_total'] ?? null) === null) {
                $needsReview[] = 'price_missing';
            }

            $lines[] = [
                'raw_text' => $raw !== '' ? $raw : $display,
                'name' => $cleanedName !== '' ? $cleanedName : ($raw !== '' ? $raw : 'بند'),
                'line_no' => $lineNo,
                'qty' => $qty ?? 1.0,
                'unit_price' => $this->toFloat($line['unit_price'] ?? null),
                'line_total' => $this->toFloat($line['line_total'] ?? null),
                'confidence' => $this->normalizeConfidence($line['confidence'] ?? null),
                'brand' => $this->nullableString($line['brand'] ?? null),
                'needs_review' => $needsReview,
            ];
        }

        $source = (string) ($decoded['source_type'] ?? 'unknown');
        if (! in_array($source, ['handwritten', 'thermal', 'printed', 'unknown'], true)) {
            $source = 'unknown';
        }

        $returned = count($lines);
        if ($returned < $rawLineCount) {
            $this->ocrLog('error', 'line_count_mismatch', [
                'raw_line_count' => $rawLineCount,
                'returned_line_count' => $returned,
                'dropped_reasons' => $droppedReasons,
            ]);
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
            'overall_confidence' => $this->normalizeConfidence($decoded['overall_confidence'] ?? null),
            'source_type' => $source,
            'raw_line_count' => $rawLineCount,
            'returned_line_count' => $returned,
            'dropped_reasons' => $droppedReasons,
        ];
    }

    protected function normalizeConfidence(mixed $value): float
    {
        // Missing confidence must NOT force a destructive second-pass (was wiping qty).
        if ($value === null || $value === '') {
            return 0.7;
        }

        return max(0, min(1, (float) $value));
    }

    /**
     * @return array{0:?int, 1:string}
     */
    protected function stripLeadingListNumber(string $text): array
    {
        $text = trim($text);
        // Arabic-Indic or Latin list index at start: "٢ وصله" / "2-" / "2."
        if (preg_match('/^([0-9٠-٩۰-۹]{1,3})[\).\-\s:]+(.+)$/u', $text, $m)) {
            $latin = $this->latinizeDigitsInText($m[1]);

            return [(int) $latin, trim($m[2])];
        }

        return [null, $text];
    }

    protected function latinizeDigitsInText(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
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
        $normalized = $this->latinizeDigitsInText((string) $value);
        $normalized = str_replace(['٬', ',', ' '], ['', '.', ''], $normalized);
        // Arabic decimal comma already handled; keep digits/dot/minus only.
        $normalized = preg_replace('/[^\d.\-]/', '', $normalized) ?? '';
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 3);
    }
}
