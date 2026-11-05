<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeProductCategoryNamesCommand extends Command
{
    protected $signature = 'categories:normalize-names {--dry-run : Show changes without saving}';

    protected $description = 'Fix Arabic category names/blurbs (الاوروربي، حسب، -ملحقات، مسافات زائدة)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fixed = 0;

        $rows = DB::table('product_category_translations')
            ->where('locale', 'ar')
            ->get(['id', 'product_category_id', 'name', 'description']);

        foreach ($rows as $row) {
            $updates = [];

            $originalName = (string) ($row->name ?? '');
            if ($originalName !== '') {
                $name = ProductCategory::normalizeArabicCatalogLabel($originalName);
                if ($name !== $originalName) {
                    $updates['name'] = $name;
                    $this->line("#{$row->product_category_id} name: «{$originalName}» → «{$name}»");
                }
            }

            $originalDesc = (string) ($row->description ?? '');
            if ($originalDesc !== '') {
                $desc = ProductCategory::normalizeArabicCatalogLabel(
                    trim(preg_replace('/\s+/u', ' ', strip_tags($originalDesc)) ?? '')
                );
                if ($desc !== $originalDesc) {
                    $updates['description'] = $desc;
                    $this->line("#{$row->product_category_id} description: typo/noise cleaned");
                }
            }

            if ($updates === []) {
                continue;
            }

            $fixed++;
            if (! $dry) {
                DB::table('product_category_translations')
                    ->where('id', $row->id)
                    ->update($updates);
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."Normalized {$fixed} category translation row(s).");

        return self::SUCCESS;
    }
}
