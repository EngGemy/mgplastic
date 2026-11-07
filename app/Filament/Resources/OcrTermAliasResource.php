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
use Illuminate\Validation\Rules\Unique;

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
            Forms\Components\Section::make('الكلمة ومسار العائلة')
                ->description('أضف مرادفاً يظهر في فواتير OCR ويُربَط بعائلة المنتج (مثل كوع، تي، ماسورة…).')
                ->schema([
                    Forms\Components\TextInput::make('term_normalized')
                        ->label('الكلمة / المرادف')
                        ->helperText('مثال: مثلث، واي، كوع ٩٠ — تُطبَّع تلقائياً عند الحفظ.')
                        ->required()
                        ->maxLength(120)
                        ->unique(
                            table: 'ocr_term_aliases',
                            column: 'term_normalized',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule
                                ->where('family_key', (string) $get('family_key')),
                        ),
                    Forms\Components\Select::make('family_key')
                        ->label('مسار العائلة')
                        ->helperText('العائلة التي تُفسَّر بها هذه الكلمة داخل OCR.')
                        ->options($families)
                        ->required()
                        ->searchable()
                        ->live(),
                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'pending' => 'معلّق (اقتراح فقط)',
                            'approved' => 'معتمد (يدخل المطابقة)',
                            'rejected' => 'مرفوض',
                        ])
                        ->default('approved')
                        ->required(),
                    Forms\Components\Textarea::make('example_raw')
                        ->label('مثال سطر (اختياري)')
                        ->rows(2)
                        ->maxLength(255)
                        ->helperText('سطر فاتورة نموذجي يساعد المراجعة لاحقاً.'),
                ])
                ->columns(2),
            Forms\Components\Section::make('بيانات النظام')
                ->collapsed()
                ->schema([
                    Forms\Components\Select::make('source')
                        ->label('المصدر')
                        ->options([
                            'seed' => 'seed',
                            'user' => 'user',
                            'admin' => 'admin',
                            'gemini' => 'gemini',
                        ])
                        ->default('admin')
                        ->disabled()
                        ->dehydrated(),
                    Forms\Components\TextInput::make('hits')->label('مرات الظهور')->numeric()->default(0)->disabled()->dehydrated(),
                    Forms\Components\TextInput::make('distinct_users')->label('مستخدمون مميزون')->numeric()->default(0)->disabled()->dehydrated(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term_normalized')->label('الكلمة')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('family_key')->label('مسار العائلة')->badge()->sortable(),
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
                    'pending' => 'معلّق',
                    'approved' => 'معتمد',
                    'rejected' => 'مرفوض',
                ]),
                Tables\Filters\SelectFilter::make('source')->options([
                    'user' => 'user',
                    'admin' => 'admin',
                    'gemini' => 'gemini',
                    'seed' => 'seed',
                ]),
                Tables\Filters\SelectFilter::make('family_key')
                    ->label('مسار العائلة')
                    ->options(
                        collect((array) config('ocr_lexicon.families', []))
                            ->mapWithKeys(fn ($meta, $key) => [$key => ($meta['catalog_name'] ?? $key)])
                            ->all()
                    ),
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
                Tables\Actions\EditAction::make()->label('تعديل'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOcrTermAliases::route('/'),
            'create' => Pages\CreateOcrTermAlias::route('/create'),
            'edit' => Pages\EditOcrTermAlias::route('/{record}/edit'),
        ];
    }
}
