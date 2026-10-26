<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use Illuminate\Console\Command;

class FixProductCategoryParentCyclesCommand extends Command
{
    protected $signature = 'categories:fix-parent-cycles';

    protected $description = 'Break product_categories parent_id cycles that hang POS / catalog walks';

    public function handle(): int
    {
        $fixed = ProductCategory::breakParentCycles();
        $this->info($fixed > 0
            ? "تم فك {$fixed} ربط أب يسبب حلقة."
            : 'لا توجد حلقات parent في التصنيفات.');

        return self::SUCCESS;
    }
}
