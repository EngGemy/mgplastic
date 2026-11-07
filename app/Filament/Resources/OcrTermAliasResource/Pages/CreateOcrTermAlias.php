<?php

namespace App\Filament\Resources\OcrTermAliasResource\Pages;

use App\Filament\Resources\OcrTermAliasResource;
use App\Services\Ocr\Lexicon\OcrLexicon;
use App\Support\ArabicTextNormalizer;
use Filament\Resources\Pages\CreateRecord;

class CreateOcrTermAlias extends CreateRecord
{
    protected static string $resource = OcrTermAliasResource::class;

    public function getTitle(): string
    {
        return 'إضافة كلمة / مرادف OCR';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $term = ArabicTextNormalizer::normalize((string) ($data['term_normalized'] ?? ''));
        $data['term_normalized'] = $term;
        $data['source'] = 'admin';
        $data['status'] = $data['status'] ?? 'approved';
        $data['hits'] = (int) ($data['hits'] ?? 0);
        $data['distinct_users'] = (int) ($data['distinct_users'] ?? 0);
        $data['reviewed_by'] = auth()->id();
        $data['reviewed_at'] = now();
        $data['last_seen_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        OcrLexicon::clearIndexCache();
    }
}
