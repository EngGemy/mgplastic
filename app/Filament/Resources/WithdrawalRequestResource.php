<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Filament\Support\WithdrawalPaymentForm;
use App\Models\WithdrawalRequest;
use App\Models\SystemLabel;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class WithdrawalRequestResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = WithdrawalRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('withdrawal_requests', 'طلبات السحب');
    }

    public static function getModelLabel(): string
    {
        return SystemLabel::get('withdrawal_requests', 'طلب سحب');
    }

    public static function getPluralModelLabel(): string
    {
        return SystemLabel::get('withdrawal_requests', 'طلبات السحب');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['plumber', 'wallet', 'reviewer'])
                ->latest())
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('لا توجد طلبات سحب')
            ->emptyStateDescription('ستظهر هنا طلبات السبّاكين لسحب الرصيد')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->alignCenter()
                    ->color('gray')
                    ->width('60px'),

                Tables\Columns\TextColumn::make('plumber.name')
                    ->label('السبّاك')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-user-circle')
                    ->description(fn (WithdrawalRequest $record) => $record->plumber?->phone),

                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('المبلغ')
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success')
                    ->formatStateUsing(fn ($state, WithdrawalRequest $record) => $record->formattedAmount()),

                Tables\Columns\TextColumn::make('method')
                    ->label('طريقة الدفع')
                    ->badge()
                    ->formatStateUsing(fn ($state, WithdrawalRequest $record) => $record->methodLabel())
                    ->color(fn (WithdrawalRequest $record) => $record->method === 'bank_transfer' ? 'info' : 'warning')
                    ->icon(fn (WithdrawalRequest $record) => $record->method === 'bank_transfer'
                        ? 'heroicon-o-building-library'
                        : 'heroicon-o-device-phone-mobile'),

                Tables\Columns\TextColumn::make('payout_summary')
                    ->label('بيانات الدفع')
                    ->state(fn (WithdrawalRequest $record) => $record->payoutDetailsSummary())
                    ->wrap()
                    ->color('gray')
                    ->limit(40)
                    ->tooltip(fn (WithdrawalRequest $record) => $record->payoutDetailsSummary()),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state, WithdrawalRequest $record) => $record->statusLabel())
                    ->color(fn (WithdrawalRequest $record) => match ($record->status) {
                        'pending' => 'warning',
                        'paid' => 'success',
                        'rejected' => 'danger',
                        'approved' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (WithdrawalRequest $record) => match ($record->status) {
                        'pending' => 'heroicon-o-clock',
                        'paid' => 'heroicon-o-check-badge',
                        'rejected' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    }),

                Tables\Columns\TextColumn::make('payment_proof')
                    ->label('مرجع الدفع')
                    ->state(fn (WithdrawalRequest $record) => $record->paymentProofSummary() ?? '—')
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->description(fn (WithdrawalRequest $record) => $record->created_at?->diffForHumans())
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'قيد المراجعة',
                        'paid' => 'مدفوع',
                        'rejected' => 'مرفوض',
                    ]),

                Tables\Filters\SelectFilter::make('method')
                    ->label('طريقة الدفع')
                    ->options([
                        'bank_transfer' => 'تحويل بنكي',
                        'mobile_wallet' => 'محفظة إلكترونية',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('عرض')
                    ->icon('heroicon-o-eye'),

                Tables\Actions\Action::make('markPaid')
                    ->label('تأكيد الدفع')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WithdrawalRequest $record) => $record->isPending())
                    ->form(WithdrawalPaymentForm::confirmationFields())
                    ->modalHeading('تأكيد إتمام الدفع')
                    ->modalDescription('أدخل رقم الإيصال أو رقم التحويل. الرصيد محجوز مسبقاً من محفظة السبّاك.')
                    ->modalSubmitActionLabel('تأكيد الدفع')
                    ->action(fn (WithdrawalRequest $record, array $data) => Pages\ListWithdrawalRequests::markPaid($record, $data)),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WithdrawalRequest $record) => $record->isPending())
                    ->form([
                        Forms\Components\Textarea::make('reason_ar')
                            ->label('سبب الرفض')
                            ->required()
                            ->rows(3)
                            ->maxLength(500),
                        Forms\Components\TextInput::make('reason_en')
                            ->label('Reason (EN) — اختياري')
                            ->maxLength(255),
                    ])
                    ->modalHeading('رفض الطلب وإرجاع الرصيد')
                    ->modalDescription('سيُعاد المبلغ المحجوز إلى محفظة السبّاك.')
                    ->modalSubmitActionLabel('رفض وإرجاع')
                    ->action(fn (WithdrawalRequest $record, array $data) => Pages\ListWithdrawalRequests::rejectAndRefund($record, $data)),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolists\Infolist $infolist): Infolists\Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('withdrawal_header')
                ->view('filament.infolists.withdrawal-profile-header')
                ->columnSpanFull(),

            Infolists\Components\Section::make('تفاصيل الدفع')
                ->icon('heroicon-o-credit-card')
                ->schema([
                    Infolists\Components\Grid::make(3)->schema([
                        Infolists\Components\TextEntry::make('method')
                            ->label('طريقة الدفع')
                            ->badge()
                            ->formatStateUsing(fn ($state, WithdrawalRequest $record) => $record->methodLabel()),

                        Infolists\Components\TextEntry::make('amount_cents')
                            ->label('المبلغ')
                            ->formatStateUsing(fn ($state, WithdrawalRequest $record) => $record->formattedAmount())
                            ->weight('bold'),

                        Infolists\Components\TextEntry::make('plumber.phone')
                            ->label('هاتف السبّاك')
                            ->icon('heroicon-o-phone')
                            ->default('—')
                            ->copyable(),
                    ]),

                    Infolists\Components\TextEntry::make('payout_summary')
                        ->label('ملخص بيانات الدفع')
                        ->state(fn (WithdrawalRequest $record) => $record->payoutDetailsSummary())
                        ->columnSpanFull(),

                    Infolists\Components\KeyValueEntry::make('details')
                        ->label('بيانات الحساب / المحفظة')
                        ->columnSpanFull(),

                    Infolists\Components\ImageEntry::make('details_image_local')
                        ->label('مرفق الدفع')
                        ->hidden(fn ($record) => ! static::hasLocalDetailsImage($record))
                        ->getStateUsing(fn ($record) => static::resolveLocalDetailsImage($record))
                        ->disk('public')
                        ->height('280')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('تأكيد الدفع')
                ->icon('heroicon-o-check-badge')
                ->schema([
                    Infolists\Components\Grid::make(2)->schema([
                        Infolists\Components\TextEntry::make('receipt_number')
                            ->label('رقم الإيصال')
                            ->default('—')
                            ->copyable()
                            ->icon('heroicon-o-document-text'),

                        Infolists\Components\TextEntry::make('transfer_number')
                            ->label('رقم التحويل')
                            ->default('—')
                            ->copyable()
                            ->icon('heroicon-o-arrow-path'),
                    ]),

                    Infolists\Components\TextEntry::make('reviewer.name')
                        ->label('أكّده')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('paid_at')
                        ->label('تاريخ الدفع')
                        ->dateTime('Y/m/d H:i'),
                ])
                ->visible(fn ($record) => $record->status === 'paid'),

            Infolists\Components\Section::make('المراجعة')
                ->icon('heroicon-o-clipboard-document-check')
                ->schema([
                    Infolists\Components\TextEntry::make('reviewer.name')
                        ->label('راجعه')
                        ->default('—')
                        ->visible(fn ($record) => $record->status === 'rejected'),

                    Infolists\Components\KeyValueEntry::make('rejection_reason')
                        ->label('سبب الرفض')
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->status === 'rejected')
                ->collapsed(),
        ]);
    }

    protected static function firstDetailsImageValue($record): ?string
    {
        $d = (array) ($record->details ?? []);
        foreach (['receipt_image', 'image', 'attachment_path', 'screenshot'] as $k) {
            $v = data_get($d, $k);
            if (is_string($v) && strlen($v) > 3) {
                return $v;
            }
        }

        return null;
    }

    protected static function hasLocalDetailsImage($record): bool
    {
        $path = static::firstDetailsImageValue($record);

        return $path && ! Str::startsWith($path, ['http://', 'https://']);
    }

    protected static function resolveLocalDetailsImage($record): ?string
    {
        return static::hasLocalDetailsImage($record) ? static::firstDetailsImageValue($record) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawalRequests::route('/'),
            'view' => Pages\ViewWithdrawalRequest::route('/{record}'),
        ];
    }
}
