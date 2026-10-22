<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeProductCategoryNamesCommand extends Command
{
    protected $signature = 'categories:normalize-names {--dry-run : Show changes without saving}';

    protected $description = 'Fix typos/extra spaces in product category Arabic names (الاوروربي، مسافات زائدة، …)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = DB::table('product_category_translations')
            ->where('locale', 'ar')
            ->whereNotNull('name')
            ->get(['id', 'product_category_id', 'name']);

        $fixed = 0;

        foreach ($rows as $row) {
            $original = (string) $row->name;
            $name = preg_replace('/\s+/u', ' ', $original) ?? $original;
            $name = str_replace(['الاوروربي', 'الأوروربي'], 'الاوروبي', $name);
            $name = trim($name);

            if ($name === $original) {
                continue;
            }

            $this->line("#{$row->product_category_id}: «{$original}» → «{$name}»");
            $fixed++;

            if (! $dry) {
                DB::table('product_category_translations')
                    ->where('id', $row->id)
                    ->update(['name' => $name]);
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."Normalized {$fixed} category name(s).");

        return self::SUCCESS;
    }
}
