<?php

namespace App\Services\Ocr;

use App\Services\Ai\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvoiceOcrPipeline
{
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

        $cacheKey = 'ocr:draft:v2:'.$prepared['sha256'];
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

        $catalog = $this->matcher->catalogNamesForPrompt(
            (int) config('services.gemini.ocr_catalog_prompt_limit', 80)
        );

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

        $draft = $this->matcher->enrich($result['draft']);
        $draft = $this->validator->annotate($draft);
        $draft = $this->secondPassLowConfidence($draft, $prepared['path'], $prepared['mime']);
        $draft = $this->matcher->enrich($draft);
        $draft = $this->validator->annotate($draft);
        $draft['image_sha256'] = $prepared['sha256'];

        Cache::put($cacheKey, [
            'ok' => true,
            'draft' => $draft,
            'model' => $result['model'] ?? null,
        ], $ttl);

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
    protected function secondPassLowConfidence(array $draft, string $path, string $mime): array
    {
        $lines = [];
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $confidence = (float) ($line['confidence'] ?? 0);
            $needs = (array) ($line['needs_review'] ?? []);
            $weakMatch = in_array($line['match_decision'] ?? 'manual', ['manual', 'suggest'], true);

            if ($confidence >= 0.6 && ! in_array('low_confidence', $needs, true) && ! $weakMatch) {
                $lines[] = $line;

                continue;
            }

            $hint = (string) ($line['raw_text'] ?? $line['name'] ?? '');
            if ($hint === '') {
                $lines[] = $line;

                continue;
            }

            $refined = $this->gemini->refineInvoiceLine($path, $mime, $hint);
            if (($refined['ok'] ?? false) && is_array($refined['line'] ?? null)) {
                $line = array_merge($line, $refined['line']);
            }

            $lines[] = $line;
        }

        $draft['lines'] = $lines;

        return $draft;
    }
}
