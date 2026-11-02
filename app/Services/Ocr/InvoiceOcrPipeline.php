<?php

namespace App\Services\Ocr;

use App\Services\Ai\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvoiceOcrPipeline
{
    public const CACHE_PREFIX = 'ocr:draft:v3:';

    public function __construct(
        protected GeminiClient $gemini,
        protected InvoiceImagePreprocessor $preprocessor,
        protected InvoiceOcrCatalogMatcher $matcher,
        protected InvoiceOcrValidator $validator,
    ) {}

    /**
     * @return array{ok:bool, draft:?array, error:?string, model:?string, elapsed_ms:int, image_sha256:?string, preview_path:?string, cached:bool}
     */
    public function run(string $absolutePath): array
    {
        $started = microtime(true);

        try {
            $prepared = $this->preprocessor->prepare($absolutePath);
        } catch (\Throwable $e) {
            Log::channel('ocr')->warning('[OCR] preprocess_failed', ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                'draft' => null,
                'error' => $e->getMessage(),
                'model' => null,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'image_sha256' => null,
                'preview_path' => null,
                'cached' => false,
            ];
        }

        $cacheKey = self::CACHE_PREFIX.$prepared['sha256'];
        $ttl = max(60, (int) config('services.gemini.ocr_cache_ttl', 86400));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['ok'] ?? false) && is_array($cached['draft'] ?? null)) {
            Log::channel('ocr')->info('[OCR] cache_hit', ['sha256' => substr($prepared['sha256'], 0, 12)]);

            return [
                'ok' => true,
                'draft' => $cached['draft'],
                'error' => null,
                'model' => $cached['model'] ?? null,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'image_sha256' => $prepared['sha256'],
                'preview_path' => $prepared['path'],
                'cached' => true,
            ];
        }

        $catalogLimit = (int) config('services.gemini.ocr_catalog_prompt_limit', 80);
        $catalog = $catalogLimit > 0
            ? $this->matcher->catalogNamesForPrompt($catalogLimit)
            : [];

        $result = $this->gemini->extractInvoiceFromImage(
            $prepared['path'],
            $prepared['mime'],
            $catalog,
        );

        if (! ($result['ok'] ?? false) || ! is_array($result['draft'] ?? null)) {
            return [
                'ok' => false,
                'draft' => null,
                'error' => (string) ($result['error'] ?? 'تعذّرت القراءة'),
                'model' => $result['model'] ?? null,
                'elapsed_ms' => (int) ($result['elapsed_ms'] ?? round((microtime(true) - $started) * 1000)),
                'image_sha256' => $prepared['sha256'],
                'preview_path' => $prepared['path'],
                'cached' => false,
            ];
        }

        $rawLineCount = (int) ($result['draft']['raw_line_count'] ?? count($result['draft']['lines'] ?? []));
        $afterParse = count($result['draft']['lines'] ?? []);

        $draft = $this->maybeCompletenessPass($result['draft'], $prepared['path'], $prepared['mime'], $rawLineCount);
        $afterComplete = count($draft['lines'] ?? []);

        $systemDefault = isset($draft['system_default']) && in_array($draft['system_default'], ['us', 'eu'], true)
            ? $draft['system_default']
            : null;
        $customerId = isset($draft['customer_user_id']) ? (int) $draft['customer_user_id'] : null;

        $draft = $this->matcher->enrich($draft, $systemDefault, $customerId);
        $afterMatch = count($draft['lines'] ?? []) + count($draft['excluded'] ?? []);
        $draft = $this->validator->annotate($draft);
        $draft = $this->secondPassLowConfidence($draft, $prepared['path'], $prepared['mime']);
        $draft = $this->matcher->enrich($draft, $systemDefault, $customerId);
        $draft = $this->validator->annotate($draft);
        $draft['image_sha256'] = $prepared['sha256'];

        $returned = count($draft['lines'] ?? []);
        $excludedCount = count($draft['excluded'] ?? []);
        $draft['raw_line_count'] = $rawLineCount;
        $draft['returned_line_count'] = $returned;
        // Always recount after the final enrich (a second enrich on remaining lines must not zero-out exclusions).
        $accounted = $returned + $excludedCount;
        $draft['accounted_for'] = $accounted;
        if (isset($draft['match_counts']) && is_array($draft['match_counts'])) {
            $draft['match_counts']['excluded'] = $excludedCount;
        }

        $matchCounts = (array) ($draft['match_counts'] ?? []);
        Log::channel('ocr')->info('[OCR] pipeline_counts', [
            'raw_line_count' => $rawLineCount,
            'after_parse' => $afterParse,
            'after_complete' => $afterComplete,
            'after_match' => $afterMatch,
            'matched' => $matchCounts['matched'] ?? null,
            'needs_choice' => $matchCounts['needs_choice'] ?? null,
            'excluded' => $matchCounts['excluded'] ?? $excludedCount,
            'returned' => $returned,
            'accounted_for' => $accounted,
            'finish_reason' => $result['finish_reason'] ?? null,
        ]);

        if ($accounted < $rawLineCount) {
            Log::channel('ocr')->error('[OCR] line_loss', [
                'raw_line_count' => $rawLineCount,
                'returned_line_count' => $returned,
                'excluded' => $excludedCount,
                'accounted_for' => $accounted,
            ]);
        }

        $finish = (string) ($result['finish_reason'] ?? '');
        $complete = $accounted >= $rawLineCount && $finish === 'STOP' && $accounted > 0;
        if ($complete) {
            Cache::put($cacheKey, [
                'ok' => true,
                'draft' => $draft,
                'model' => $result['model'] ?? null,
            ], $ttl);
        } else {
            Log::channel('ocr')->warning('[OCR] cache_skipped_incomplete', [
                'returned' => $returned,
                'raw_line_count' => $rawLineCount,
                'finish_reason' => $finish,
            ]);
        }

        return [
            'ok' => true,
            'draft' => $draft,
            'error' => null,
            'model' => $result['model'] ?? null,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'image_sha256' => $prepared['sha256'],
            'preview_path' => $prepared['path'],
            'cached' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    protected function maybeCompletenessPass(array $draft, string $path, string $mime, int $rawLineCount): array
    {
        $lines = (array) ($draft['lines'] ?? []);
        if (count($lines) >= 3) {
            return $draft;
        }

        $looksNumbered = false;
        foreach ($lines as $line) {
            $raw = (string) ($line['raw_text'] ?? $line['name'] ?? '');
            if (preg_match('/^[0-9٠-٩۰-۹]{1,3}[\).\-\s]/u', $raw) || ! empty($line['line_no'])) {
                $looksNumbered = true;
                break;
            }
        }

        // Under-transcription of a short/empty first pass — try one lines-only pass.
        if (! $looksNumbered && $rawLineCount >= 3) {
            $looksNumbered = true;
        }
        if (count($lines) === 0) {
            $looksNumbered = true;
        }

        if (! $looksNumbered && count($lines) >= 1) {
            return $draft;
        }

        $extra = $this->gemini->extractLinesOnly($path, $mime);
        if (! ($extra['ok'] ?? false) || ($extra['lines'] ?? []) === []) {
            return $draft;
        }

        $merged = $this->mergeLinesByPosition($lines, $extra['lines']);
        $draft['lines'] = $merged;
        $draft['raw_line_count'] = max($rawLineCount, count($extra['lines']), count($merged));
        $draft['returned_line_count'] = count($merged);

        Log::channel('ocr')->info('[OCR] completeness_pass', [
            'first_count' => count($lines),
            'second_count' => count($extra['lines']),
            'merged' => count($merged),
        ]);

        return $draft;
    }

    /**
     * @param  list<array<string,mixed>>  $first
     * @param  list<array<string,mixed>>  $second
     * @return list<array<string,mixed>>
     */
    protected function mergeLinesByPosition(array $first, array $second): array
    {
        $max = max(count($first), count($second));
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $a = $first[$i] ?? null;
            $b = $second[$i] ?? null;
            if (is_array($a) && is_array($b)) {
                // Prefer first-pass qty when present and > 0; fill missing text from second.
                $merged = $a;
                if (trim((string) ($merged['name'] ?? '')) === '' && trim((string) ($b['name'] ?? '')) !== '') {
                    $merged['name'] = $b['name'];
                }
                if (trim((string) ($merged['raw_text'] ?? '')) === '' && trim((string) ($b['raw_text'] ?? '')) !== '') {
                    $merged['raw_text'] = $b['raw_text'];
                }
                if ((! isset($merged['qty']) || (float) $merged['qty'] <= 0) && isset($b['qty'])) {
                    $merged['qty'] = $b['qty'];
                }
                $out[] = $merged;
            } elseif (is_array($b)) {
                $out[] = $b;
            } elseif (is_array($a)) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    protected function secondPassLowConfidence(array $draft, string $path, string $mime): array
    {
        $lines = [];
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                Log::channel('ocr')->warning('[OCR] line_dropped', ['reason' => 'non_array_after_match']);

                continue;
            }

            $confidence = (float) ($line['confidence'] ?? 0.5);
            $needs = (array) ($line['needs_review'] ?? []);

            // Only refine clearly weak OCR text — never all unmatched manuals (that wiped qty to 1).
            $shouldRefine = $confidence < 0.55 && in_array('low_confidence', $needs, true);

            if (! $shouldRefine) {
                $lines[] = $line;

                continue;
            }

            $hint = (string) ($line['raw_text'] ?? $line['name'] ?? '');
            if ($hint === '') {
                $lines[] = $line;

                continue;
            }

            $originalQty = $line['qty'] ?? null;
            $refined = $this->gemini->refineInvoiceLine($path, $mime, $hint);
            if (($refined['ok'] ?? false) && is_array($refined['line'] ?? null)) {
                $new = $refined['line'];
                // Preserve known qty when refine returns a suspicious default/null.
                if ($originalQty !== null && ((float) $originalQty) !== 1.0) {
                    if (! isset($new['qty']) || $new['qty'] === null || (float) $new['qty'] === 1.0) {
                        $new['qty'] = $originalQty;
                    }
                }
                $line = array_merge($line, array_filter(
                    $new,
                    fn ($v) => $v !== null && $v !== ''
                ));
            }

            $lines[] = $line;
        }

        $draft['lines'] = $lines;

        return $draft;
    }
}
