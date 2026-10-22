<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\WarrantyClaimResource\Pages;
use App\Models\WarrantyClaim;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WarrantyClaimResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = WarrantyClaim::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 44;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'مطالبات الضمان';
    }

    public static function getModelLabel(): string
    {
        return 'مطالبة';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات المطالبة')
                    ->icon('heroicon-o-document-text')
                    ->columns(12)
                    ->schema([
                        Forms\Components\Placeholder::make('warranty_code')
                            ->label('كود الضمان')
                            ->content(fn (?WarrantyClaim $record) => $record?->warranty?->code ?? '—')
                            ->columnSpan(4),

                        Forms\Components\Placeholder::make('plumber_name')
                            ->label('السباك')
                            ->content(fn (?WarrantyClaim $record) => $record?->warranty?->plumber?->name ?? '—')
                            ->columnSpan(4),

                        Forms\Components\Placeholder::make('opened_at')
                            ->label('تاريخ الفتح')
                            ->content(fn (?WarrantyClaim $record) => optional($record?->created_at)?->format('Y/m/d H:i') ?? '—')
                            ->columnSpan(4),

                        Forms\Components\Textarea::make('customer_note')
                            ->label('ملاحظة العميل')
                            ->disabled()
                            ->rows(4)
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('المعالجة')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->columns(12)
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('الحالة')
                            ->native(false)
                            ->required()
                            ->options([
                                'open' => 'مفتوحة',
                                'in_review' => 'قيد المراجعة',
                                'resolved' => 'محلولة',
                                'rejected' => 'مرفوضة',
                            ])
                            ->columnSpan(6),

                        Forms\Components\Textarea::make('resolution_note')
                            ->label('ملاحظة المعالجة')
                            ->rows(4)
                            ->helperText('تظهر للفريق داخليًا — اكتب قرار المعالجة بوضوح.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('warranty.code')->label('كود الضمان')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('warranty.plumber.name')->label('السباك'),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'in_review' => 'قيد المراجعة',
                        'resolved' => 'محلولة',
                        'rejected' => 'مرفوضة',
                        default => 'مفتوحة',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'resolved' => 'success',
                        'rejected' => 'danger',
                        'in_review' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('تاريخ')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'open' => 'مفتوحة',
                    'in_review' => 'مراجعة',
                    'resolved' => 'محلولة',
                    'rejected' => 'مرفوضة',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('معالجة')
                    ->after(function (WarrantyClaim $record) {
                        FilamentNotification::make()->success()->title('تم التحديث')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarrantyClaims::route('/'),
            'edit' => Pages\EditWarrantyClaim::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
