<?php

namespace App\Filament\Resources\OcrTermAliasResource\Pages;

use App\Filament\Resources\OcrTermAliasResource;
use App\Services\Ocr\Lexicon\OcrLexicon;
use App\Support\ArabicTextNormalizer;
use Filament\Resources\Pages\EditRecord;

class EditOcrTermAlias extends EditRecord
{
    protected static string $resource = OcrTermAliasResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['term_normalized'])) {
            $data['term_normalized'] = ArabicTextNormalizer::normalize((string) $data['term_normalized']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        OcrLexicon::clearIndexCache();
    }
}
