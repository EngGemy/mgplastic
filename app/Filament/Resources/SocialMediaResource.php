<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\SocialMediaResource\Pages;
use App\Models\SocialMedia;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SocialMediaResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = SocialMedia::class;
    protected static ?string $navigationIcon = 'heroicon-o-share';
    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string { return 'الإعدادات'; }
    public static function getNavigationLabel(): string { return 'وسائل التواصل'; }
    public static function getModelLabel(): string { return 'وسائل التواصل'; }
    public static function getPluralModelLabel(): string { return 'وسائل التواصل'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('رابط التواصل')
                ->description('يظهر في إعدادات التطبيق وصفحة التواصل.')
                ->icon('heroicon-o-share')
                ->columns(12)
                ->schema([
                    Forms\Components\Select::make('platform')
                        ->label('المنصة')
                        ->options([
                            'facebook' => 'Facebook',
                            'instagram' => 'Instagram',
                            'x' => 'X (Twitter)',
                            'tiktok' => 'TikTok',
                            'youtube' => 'YouTube',
                            'linkedin' => 'LinkedIn',
                            'whatsapp' => 'WhatsApp',
                            'website' => 'الموقع',
                            'other' => 'أخرى',
                        ])
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('url')
                        ->label('الرابط')
                        ->url()
                        ->required()
                        ->maxLength(2048)
                        ->prefixIcon('heroicon-o-link')
                        ->columnSpan(8),

                    Forms\Components\TextInput::make('name_ar')
                        ->label('الاسم بالعربية')
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->columnSpan(6),

                    Forms\Components\TextInput::make('name_en')
                        ->label('الاسم بالإنجليزية')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(6),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->label('المنصة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'facebook' => 'Facebook',
                        'instagram' => 'Instagram',
                        'x' => 'X',
                        'tiktok' => 'TikTok',
                        'youtube' => 'YouTube',
                        'linkedin' => 'LinkedIn',
                        'whatsapp' => 'WhatsApp',
                        'website' => 'الموقع',
                        'other' => 'أخرى',
                        default => ucfirst((string) $state),
                    })
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name_ar')
                    ->label('الاسم')
                    ->state(fn ($record) => optional($record?->translate('ar'))->name)
                    ->wrap()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('url')
                    ->label('الرابط')
                    ->url(fn ($state) => $state, true)
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSocialMedia::route('/'),
            'create' => Pages\CreateSocialMedia::route('/create'),
            'edit'   => Pages\EditSocialMedia::route('/{record}/edit'),
        ];
    }
}
