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
        return $form->schema([
            Forms\Components\Select::make('status')->label('الحالة')->options([
                'open' => 'مفتوحة',
                'in_review' => 'قيد المراجعة',
                'resolved' => 'محلولة',
                'rejected' => 'مرفوضة',
            ])->required(),
            Forms\Components\Textarea::make('customer_note')->label('ملاحظة العميل')->disabled(),
            Forms\Components\Textarea::make('resolution_note')->label('ملاحظة المعالجة'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('warranty.code')->label('كود الضمان')->searchable(),
                Tables\Columns\TextColumn::make('warranty.plumber.name')->label('السباك'),
                Tables\Columns\BadgeColumn::make('status')->label('الحالة'),
                Tables\Columns\TextColumn::make('created_at')->label('تاريخ')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'open' => 'مفتوحة', 'in_review' => 'مراجعة', 'resolved' => 'محلولة', 'rejected' => 'مرفوضة',
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
