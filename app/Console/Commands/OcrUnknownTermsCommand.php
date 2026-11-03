<?php

namespace App\Console\Commands;

use App\Services\Ocr\CatalogSlotParser;
use App\Services\Ocr\Lexicon\ArabicMorphology;
use App\Support\ArabicTextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OcrUnknownTermsCommand extends Command
{
    protected $signature = 'ocr:unknown-terms {--days=30} {--min=2}';

    protected $description = 'List frequent unmatched OCR tokens (no personal data) to seed the lexicon';

    public function handle(CatalogSlotParser $parser, ArabicMorphology $morphology): int
    {
        $days = max(1, (int) $this->option('days'));
        $min = max(1, (int) $this->option('min'));

        if (! Schema::hasTable('ocr_corrections')) {
            $this->warn('ocr_corrections table missing.');

            return self::FAILURE;
        }

        $rows = DB::table('ocr_corrections')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['raw_text']);

        $counts = [];
        $examples = [];

        foreach ($rows as $row) {
            $raw = (string) $row->raw_text;
            $slots = $parser->parse($raw);
            if (($slots['family'] ?? null) !== null) {
                continue;
            }
            $normalized = ArabicTextNormalizer::normalize($raw);
            foreach (preg_split('/\s+/u', $normalized) ?: [] as $token) {
                if ($token === '' || preg_match('/^[0-9]+(?:\.[0-9]+)?$/u', $token)) {
                    continue;
                }
                // Skip known lemmas.
                $known = false;
                foreach ($morphology->lemmas($token) as $lemma) {
                    if ($this->isKnownLemma($lemma)) {
                        $known = true;
                        break;
                    }
                }
                if ($known) {
                    continue;
                }
                $counts[$token] = ($counts[$token] ?? 0) + 1;
                if (! isset($examples[$token])) {
                    $examples[$token] = mb_substr($raw, 0, 80);
                }
            }
        }

        arsort($counts);
        $table = [];
        foreach ($counts as $term => $n) {
            if ($n < $min) {
                continue;
            }
            $table[] = [$term, $n, $examples[$term] ?? ''];
        }

        if ($table === []) {
            $this->info('No unknown terms above threshold.');

            return self::SUCCESS;
        }

        $this->table(['term', 'count', 'example_raw'], array_slice($table, 0, 50));

        return self::SUCCESS;
    }

    protected function isKnownLemma(string $lemma): bool
    {
        static $known = null;
        if ($known === null) {
            $known = [];
            foreach ((array) config('ocr_lexicon.families', []) as $meta) {
                foreach ((array) ($meta['synonyms'] ?? []) as $terms) {
                    foreach ((array) $terms as $row) {
                        $t = ArabicTextNormalizer::normalize((string) ($row['term'] ?? ''));
                        if ($t !== '') {
                            $known[$t] = true;
                        }
                    }
                }
                $cn = ArabicTextNormalizer::normalize((string) ($meta['catalog_name'] ?? ''));
                if ($cn !== '') {
                    $known[$cn] = true;
                }
            }
        }

        return isset($known[$lemma]);
    }
}
