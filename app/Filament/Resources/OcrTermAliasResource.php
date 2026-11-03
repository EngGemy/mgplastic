<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\OcrTermAliasResource\Pages;
use App\Models\OcrTermAlias;
use App\Services\Ocr\Lexicon\OcrLexicon;
use App\Services\Ocr\OcrTermAliasService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OcrTermAliasResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = OcrTermAlias::class;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?int $navigationSort = 96;

    public static function getNavigationGroup(): ?string
    {
        return 'النظام';
    }

    public static function getNavigationLabel(): string
    {
        return 'كلمات OCR الجديدة';
    }

    public static function getModelLabel(): string
    {
        return 'كلمة OCR';
    }

    public static function getPluralModelLabel(): string
    {
        return 'كلمات OCR الجديدة';
    }

    public static function form(Form $form): Form
    {
        $families = collect((array) config('ocr_lexicon.families', []))
            ->mapWithKeys(fn ($meta, $key) => [$key => ($meta['catalog_name'] ?? $key).' ('.$key.')'])
            ->all();

        return $form->schema([
            Forms\Components\TextInput::make('term_normalized')->label('الكلمة')->required()->maxLength(120),
            Forms\Components\Select::make('family_key')->label('العائلة')->options($families)->required()->searchable(),
            Forms\Components\Select::make('source')->label('المصدر')->options([
                'seed' => 'seed',
                'user' => 'user',
                'admin' => 'admin',
                'gemini' => 'gemini',
            ])->disabled(),
            Forms\Components\Select::make('status')->label('الحالة')->options([
                'pending' => 'pending',
                'approved' => 'approved',
                'rejected' => 'rejected',
            ])->required(),
            Forms\Components\Textarea::make('example_raw')->label('مثال سطر')->rows(2)->disabled(),
            Forms\Components\TextInput::make('hits')->label('مرات الظهور')->disabled(),
            Forms\Components\TextInput::make('distinct_users')->label('مستخدمون مميزون')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term_normalized')->label('الكلمة')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('family_key')->label('العائلة')->badge()->sortable(),
                Tables\Columns\TextColumn::make('source')->label('المصدر')->badge(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('hits')->label('مرات')->sortable(),
                Tables\Columns\TextColumn::make('distinct_users')->label('مستخدمون')->sortable(),
                Tables\Columns\TextColumn::make('example_raw')->label('مثال')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('آخر ظهور')->since(),
            ])
            ->defaultSort('hits', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'pending',
                    'approved' => 'approved',
                    'rejected' => 'rejected',
                ]),
                Tables\Filters\SelectFilter::make('source')->options([
                    'user' => 'user',
                    'admin' => 'admin',
                    'gemini' => 'gemini',
                    'seed' => 'seed',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (OcrTermAlias $r) => $r->status !== 'approved')
                    ->action(function (OcrTermAlias $record): void {
                        app(OcrTermAliasService::class)->approve($record, auth()->user());
                        OcrLexicon::clearIndexCache();
                        Notification::make()->title('تم اعتماد الكلمة')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (OcrTermAlias $r) => $r->status !== 'rejected')
                    ->requiresConfirmation()
                    ->action(function (OcrTermAlias $record): void {
                        app(OcrTermAliasService::class)->reject($record, auth()->user());
                        OcrLexicon::clearIndexCache();
                        Notification::make()->title('تم رفض الكلمة')->success()->send();
                    }),
                Tables\Actions\EditAction::make()->label('تعديل/دمج'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOcrTermAliases::route('/'),
            'edit' => Pages\EditOcrTermAlias::route('/{record}/edit'),
        ];
    }
}
