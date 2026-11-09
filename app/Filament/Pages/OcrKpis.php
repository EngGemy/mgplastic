<?php

namespace App\Filament\Pages;

use App\Models\OcrAlias;
use App\Models\OcrBatch;
use App\Models\OcrImageLine;
use App\Models\OcrUsage;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OcrKpis extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.ocr-kpis';

    protected static ?int $navigationSort = 95;

    public static function getNavigationGroup(): ?string
    {
        return 'التشغيل';
    }

    public static function getNavigationLabel(): string
    {
        return 'مؤشرات OCR';
    }

    public function getTitle(): string
    {
        return 'مؤشرات OCR';
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $today = now()->toDateString();
        $batchesToday = OcrBatch::query()->whereDate('created_at', $today)->count();
        $addedToday = OcrBatch::query()->where('status', 'added')->whereDate('cart_added_at', $today)->count();
        $abandoned = OcrBatch::query()->where('status', 'abandoned')->whereDate('updated_at', $today)->count();

        $lines = OcrImageLine::query()->whereDate('created_at', $today);
        $totalLines = (clone $lines)->count();
        $autoReady = (clone $lines)->where('review_status', 'ready')->whereNotNull('resolved_product_id')->count();
        $edited = (clone $lines)->whereIn('review_status', ['approved', 'added'])->count();

        $geminiCalls = Schema::hasTable('ocr_usage')
            ? OcrUsage::query()->where('kind', 'gemini')->whereDate('created_at', $today)->count()
            : 0;
        $geminiFail = Schema::hasTable('ocr_usage')
            ? OcrUsage::query()->where('kind', 'gemini')->where('success', false)->whereDate('created_at', $today)->count()
            : 0;

        $avgMs = Schema::hasTable('ocr_usage')
            ? (int) OcrUsage::query()->where('kind', 'gemini')->whereDate('created_at', $today)->avg('duration_ms')
            : 0;

        $topUnmatched = OcrImageLine::query()
            ->whereNull('resolved_product_id')
            ->whereDate('created_at', '>=', now()->subDays(7))
            ->select('raw_fragment', DB::raw('COUNT(*) as c'))
            ->groupBy('raw_fragment')
            ->orderByDesc('c')
            ->limit(15)
            ->get();

        $aliases = OcrAlias::query()->orderByDesc('updated_at')->limit(20)->get();

        return compact(
            'batchesToday',
            'addedToday',
            'abandoned',
            'totalLines',
            'autoReady',
            'edited',
            'geminiCalls',
            'geminiFail',
            'avgMs',
            'topUnmatched',
            'aliases',
        );
    }
}
