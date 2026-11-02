<?php

namespace App\Console\Commands;

use App\Services\Ocr\ProductCatalogAttributeDeriver;
use Illuminate\Console\Command;

class ProductsDeriveAttributesCommand extends Command
{
    protected $signature = 'products:derive-attributes
        {--report : Print parse report only (default mode)}
        {--apply : Write derived columns (off by default / dry-run)}';

    protected $description = 'Derive OCR catalog slots (family/size/system) from product names. Never changes names, categories, or prices.';

    public function handle(ProductCatalogAttributeDeriver $deriver): int
    {
        $this->info('Known data issues (report only — not auto-fixed):');
        foreach ((array) config('ocr_catalog.known_data_issues', []) as $issue) {
            $this->line(' - id '.($issue['id'] ?? 'n/a').': '.$issue['note']);
        }
        $this->newLine();

        $report = $deriver->report();
        $this->info("Parse report: {$report['parsed']}/{$report['total']} names parse cleanly.");

        if ($report['failed'] !== []) {
            $this->warn('Unparsable / incomplete rows:');
            foreach (array_slice($report['failed'], 0, 50) as $row) {
                $this->line(sprintf(
                    '  #%d %s | family=%s size=%s notes=%s',
                    $row['id'],
                    $row['name'],
                    $row['family'] ?? 'null',
                    $row['size'] ?? 'null',
                    implode(',', $row['notes'] ?? [])
                ));
            }
            if (count($report['failed']) > 50) {
                $this->line('  … '.(count($report['failed']) - 50).' more');
            }
        }

        $this->newLine();
        $this->info('Sample parsed slots:');
        foreach ($report['samples'] as $s) {
            $this->line(sprintf(
                '  #%d %s => family=%s angle=%s size=%s%s system=%s ok=%s',
                $s['id'],
                $s['name'],
                $s['family'] ?? '-',
                $s['angle'] ?? '-',
                $s['size'] ?? '-',
                $s['unit'] ? ' '.$s['unit'] : '',
                $s['system'] ?? 'null',
                $s['ok'] ? 'yes' : 'no'
            ));
        }

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $dry = $deriver->apply(dryRun: true);
            $this->newLine();
            $this->comment("Dry-run: would update {$dry['updated']}, skip {$dry['skipped']}, fail {$dry['failed']}.");
            $this->comment('Re-run with --apply to write catalog_* columns.');

            return self::SUCCESS;
        }

        $result = $deriver->apply(dryRun: false);
        $this->info("Applied: updated {$result['updated']}, skipped {$result['skipped']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
