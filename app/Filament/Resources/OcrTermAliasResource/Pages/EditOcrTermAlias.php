<?php

namespace App\Filament\Resources\OcrTermAliasResource\Pages;

use App\Filament\Resources\OcrTermAliasResource;
use App\Services\Ocr\Lexicon\OcrLexicon;
use Filament\Resources\Pages\EditRecord;

class EditOcrTermAlias extends EditRecord
{
    protected static string $resource = OcrTermAliasResource::class;

    protected function afterSave(): void
    {
        OcrLexicon::clearIndexCache();
    }
}
