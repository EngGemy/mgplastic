<?php

namespace App\Console\Commands;

use App\Models\ProductCategoryTranslation;
use App\Models\ProductTag;
use App\Models\ProductTranslation;
use App\Support\UnicodeText;
use Illuminate\Console\Command;

/**
 * One-time (and re-runnable) NFKC cleanup for catalog display names.
 * Fixes Arabic presentation-form letters that break search/match.
 */
class NormalizeCatalogUnicodeCommand extends Command
{
    protected $signature = 'catalog:normalize-unicode
                            {--dry-run : Report changes without writing}
                            {--fix : Apply even when already normalized}';

    protected $description = 'NFKC-normalize product, category, and tag names (presentation forms → standard letters)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        $changed += $this->normalizeTable(
            ProductTranslation::query()->whereNotNull('name'),
            ['name', 'description', 'usage'],
            $dry,
            'product_translations'
        );
        $changed += $this->normalizeTable(
            ProductCategoryTranslation::query()->whereNotNull('name'),
            ['name', 'description'],
            $dry,
            'product_category_translations'
        );
        $changed += $this->normalizeTable(
            ProductTag::query()->whereNotNull('name'),
            ['name'],
            $dry,
            'product_tags'
        );

        $this->info(($dry ? '[dry-run] ' : '')."Rows updated: {$changed}");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $fields
     */
    protected function normalizeTable($query, array $fields, bool $dry, string $label): int
    {
        $count = 0;
        $query->orderBy('id')->chunkById(200, function ($rows) use ($fields, $dry, &$count, $label) {
            foreach ($rows as $row) {
                $dirty = false;
                foreach ($fields as $field) {
                    $raw = (string) ($row->{$field} ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $next = UnicodeText::nfkc($raw);
                    if ($next !== $raw) {
                        $row->{$field} = $next;
                        $dirty = true;
                    }
                }
                if ($dirty) {
                    $count++;
                    if (! $dry) {
                        $row->saveQuietly();
                    }
                }
            }
            $this->line("… {$label}: {$count} so far");
        });

        return $count;
    }
}
