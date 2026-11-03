<?php

namespace App\Services\Ocr;

use App\Models\OcrTermAlias;
use App\Models\Product;
use App\Models\User;
use App\Services\Ocr\Lexicon\ArabicMorphology;
use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Facades\Log;

class OcrTermAliasService
{
    public function __construct(protected ArabicMorphology $morphology) {}

    /**
     * When a user corrects a line, align raw tokens with the chosen product's family terms
     * and upsert pending aliases for differing tokens.
     */
    public function learnFromCorrection(
        string $rawText,
        Product $product,
        ?User $user = null,
    ): void {
        $family = (string) ($product->catalog_family ?? '');
        if ($family === '') {
            return;
        }

        $lexFamily = $this->lexiconFamilyFromCatalog($family);
        $catalogName = ArabicTextNormalizer::normalize(
            (string) (config("ocr_lexicon.families.{$lexFamily}.catalog_name") ?? '')
        );

        $normalized = ArabicTextNormalizer::normalize($rawText);
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));

        $known = $this->knownTermsForFamily($lexFamily);
        if ($catalogName !== '') {
            $known[$catalogName] = true;
        }

        foreach ($tokens as $token) {
            if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/u', $token)) {
                continue;
            }
            if ($this->isUnitOrSizeWord($token)) {
                continue;
            }

            $lemmas = $this->morphology->lemmas($token);
            $alreadyKnown = false;
            foreach ($lemmas as $lemma) {
                if (isset($known[$lemma])) {
                    $alreadyKnown = true;
                    break;
                }
            }
            if ($alreadyKnown) {
                continue;
            }

            $this->upsertPending($token, $lexFamily, $user, $rawText, 'user');
        }
    }

    public function upsertPending(
        string $term,
        string $familyKey,
        ?User $user,
        ?string $exampleRaw,
        string $source = 'user',
    ): OcrTermAlias {
        $term = ArabicTextNormalizer::normalize($term);
        $alias = OcrTermAlias::query()->firstOrNew([
            'term_normalized' => $term,
            'family_key' => $familyKey,
        ]);

        if (! $alias->exists) {
            $alias->source = $source;
            $alias->status = 'pending';
            $alias->hits = 0;
            $alias->distinct_users = 0;
            $alias->user_ids = [];
        }

        if ($alias->status === 'rejected') {
            return $alias;
        }

        $alias->hits = (int) $alias->hits + 1;
        $alias->last_seen_at = now();
        if ($exampleRaw) {
            $alias->example_raw = mb_substr($exampleRaw, 0, 255);
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($alias->user_ids ?? []))));
        if ($user?->id && ! in_array((int) $user->id, $ids, true)) {
            $ids[] = (int) $user->id;
            $alias->user_ids = $ids;
            $alias->distinct_users = count($ids);
        }

        $alias->save();
        $this->maybePromote($alias);

        return $alias->fresh();
    }

    public function maybePromote(OcrTermAlias $alias): void
    {
        if ($alias->status !== 'pending') {
            return;
        }

        $minHits = (int) config('ocr_lexicon.alias_promotion.min_hits', 3);
        $minUsers = (int) config('ocr_lexicon.alias_promotion.min_distinct_users', 2);

        if ((int) $alias->hits >= $minHits && (int) $alias->distinct_users >= $minUsers) {
            $alias->status = 'approved';
            $alias->reviewed_at = now();
            $alias->save();
            Log::channel('ocr')->info('[OCR] term_alias_promoted', [
                'term' => $alias->term_normalized,
                'family' => $alias->family_key,
                'hits' => $alias->hits,
                'users' => $alias->distinct_users,
            ]);
        }
    }

    public function approve(OcrTermAlias $alias, User $admin): void
    {
        $alias->forceFill([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'source' => $alias->source === 'gemini' ? 'admin' : $alias->source,
        ])->save();
    }

    public function reject(OcrTermAlias $alias, User $admin): void
    {
        $alias->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * Approved aliases may suggest; only approved can contribute to auto via lexicon merge.
     *
     * @return list<array{term:string, family:string, confirmed:bool}>
     */
    public function approvedEntries(): array
    {
        return OcrTermAlias::query()
            ->where('status', 'approved')
            ->get()
            ->map(fn (OcrTermAlias $a) => [
                'term' => $a->term_normalized,
                'family' => $a->family_key,
                'confirmed' => true,
            ])
            ->all();
    }

    /**
     * Pending aliases — suggestions only.
     *
     * @return list<array{term:string, family:string, confirmed:bool}>
     */
    public function pendingEntries(): array
    {
        return OcrTermAlias::query()
            ->where('status', 'pending')
            ->get()
            ->map(fn (OcrTermAlias $a) => [
                'term' => $a->term_normalized,
                'family' => $a->family_key,
                'confirmed' => false,
            ])
            ->all();
    }

    protected function lexiconFamilyFromCatalog(string $catalogFamily): string
    {
        $map = (array) config('ocr_lexicon.catalog_family_map', []);
        $flip = array_flip($map);
        if ($catalogFamily === 'elbow_90' || $catalogFamily === 'elbow_45') {
            return 'elbow';
        }

        return (string) ($flip[$catalogFamily] ?? $catalogFamily);
    }

    /** @return array<string,bool> */
    protected function knownTermsForFamily(string $lexFamily): array
    {
        $known = [];
        $meta = (array) config("ocr_lexicon.families.{$lexFamily}", []);
        foreach ((array) ($meta['synonyms'] ?? []) as $terms) {
            foreach ((array) $terms as $row) {
                $t = ArabicTextNormalizer::normalize((string) ($row['term'] ?? ''));
                if ($t !== '') {
                    $known[$t] = true;
                }
            }
        }

        return $known;
    }

    protected function isUnitOrSizeWord(string $token): bool
    {
        foreach ((array) config('ocr_lexicon.units', []) as $words) {
            foreach ((array) $words as $w) {
                if ($token === ArabicTextNormalizer::normalize((string) $w)) {
                    return true;
                }
            }
        }
        foreach (['half_add', 'half', 'quarter_add'] as $key) {
            foreach ((array) config("ocr_lexicon.size_words.{$key}", []) as $w) {
                if ($token === ArabicTextNormalizer::normalize((string) $w)) {
                    return true;
                }
            }
        }

        return false;
    }
}
