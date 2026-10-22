<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wholesale home polish:
 * - Fix Arabic typos / extra spaces in category names
 * - Fill empty AR descriptions (short blurbs for system cards)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('product_category_translations')) {
            return;
        }

        $rows = DB::table('product_category_translations')
            ->where('locale', 'ar')
            ->get(['id', 'product_category_id', 'name', 'description']);

        foreach ($rows as $row) {
            $name = preg_replace('/\s+/u', ' ', trim((string) $row->name)) ?? (string) $row->name;
            $name = str_replace(['الاوروربي', 'الأوروربي'], 'الاوروبي', $name);
            $name = trim($name);

            $description = trim((string) ($row->description ?? ''));
            if ($description === '') {
                $description = $this->blurbFor($name);
            }

            $updates = [];
            if ($name !== (string) $row->name) {
                $updates['name'] = $name;
            }
            if ($description !== '' && trim((string) ($row->description ?? '')) === '') {
                $updates['description'] = $description;
            }

            if ($updates !== []) {
                DB::table('product_category_translations')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Data migration — no rollback.
    }

    protected function blurbFor(string $name): string
    {
        $blob = mb_strtolower($name);

        return match (true) {
            str_contains($blob, 'اوروب') || str_contains($blob, 'أوروب') => 'مقاسات ومواصفات · EN Standard',
            str_contains($blob, 'امريك') || str_contains($blob, 'أمريك') => 'Schedule / ASTM',
            str_contains($blob, 'ذهب') => 'مواسير النظام الذهبي',
            str_contains($blob, 'برتق') => 'نظام البرتقالي للصرف والتمديدات',
            str_contains($blob, 'رماد') => 'النظام الرمادي',
            str_contains($blob, 'ابار') || str_contains($blob, 'آبار') => 'مواسير الآبار والطلمبات',
            default => '',
        };
    }
};
