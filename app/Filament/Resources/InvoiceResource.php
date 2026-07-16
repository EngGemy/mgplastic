<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesByNetworkRole;
use App\Filament\Resources\InvoiceResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceDistributionResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Models\SystemLabel;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class InvoiceResource extends Resource
{
    use ScopesByNetworkRole;

    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';
    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('invoices', 'الفواتير');
    }

    public static function getModelLabel(): string
    {
        return SystemLabel::get('invoices', 'فاتورة');
    }

    public static function getPluralModelLabel(): string
    {
        return SystemLabel::get('invoices', 'الفواتير');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeInvoicesForRole(parent::getEloquentQuery());
    }

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        return in_array($role, ['super_admin', 'admin', 'wholesale_distributor', 'retail_trader'], true);
    }

    public static function canView($record): bool
    {
        return $record instanceof Invoice && static::userCanAccessInvoice($record);
    }

    public static function canCreate(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public static function canEdit($record): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['plumber', 'wholesaleDistributor', 'counterparty', 'parentInvoice', 'sourceDistribution.items'])
                ->withSum('items as total_quantity', 'quantity')
                ->withSum('items as total_item_points', 'total_points'))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('#')
                    ->sortable()
                    ->searchable()
                    ->alignCenter()
                    ->width('60px')
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('number')
                    ->label('رقم الفاتورة')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('تم نسخ رقم الفاتورة')
                    ->weight('semibold')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('primary'),

                Tables\Columns\TextColumn::make('invoice_flow')
                    ->label('الاتجاه')
                    ->badge()
                    ->formatStateUsing(fn ($state, Invoice $record) => $record->flowLabel())
                    ->description(fn (Invoice $record) => $record->isOutgoing()
                        ? 'للقطاعي'
                        : ($record->isWholesalePos() ? 'من المصنع' : null))
                    ->color(fn ($state, Invoice $record) => $record->isOutgoing() ? 'warning' : 'success')
                    ->icon(fn (Invoice $record) => $record->isOutgoing()
                        ? 'heroicon-o-arrow-up-circle'
                        : 'heroicon-o-arrow-down-circle')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('invoice_type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state, Invoice $record) => match (true) {
                        $record->isOutgoing() => 'صادر — قطاعي',
                        $state === 'wholesale_pos' => 'وارد — جملة',
                        default => 'سباك',
                    })
                    ->color(fn ($state, Invoice $record) => $record->isOutgoing() ? 'warning' : ($state === 'wholesale_pos' ? 'primary' : 'gray'))
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('party_name')
                    ->label('الطرف')
                    ->state(fn (Invoice $record) => match (true) {
                        $record->isOutgoing() => $record->counterparty?->name ?? '—',
                        $record->isWholesalePos() => $record->wholesaleDistributor?->name ?? '—',
                        default => $record->plumber?->name ?? '—',
                    })
                    ->description(fn (Invoice $record) => match (true) {
                        $record->isOutgoing() => 'تاجر قطاعي',
                        $record->isWholesalePos() => 'من المصنع',
                        default => 'سباك',
                    })
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('wholesaleDistributor', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('plumber', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y/m/d')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_quantity')
                    ->label('الكمية')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->state(fn (Invoice $record) => $record->isOutgoing()
                        ? (int) ($record->sourceDistribution?->items->sum('quantity') ?? 0)
                        : (int) ($record->total_quantity ?? 0))
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->suffix(' وحدة'),

                Tables\Columns\TextColumn::make('display_points')
                    ->label('النقاط')
                    ->alignCenter()
                    ->badge()
                    ->color('warning')
                    ->state(fn (Invoice $record) => $record->isOutgoing()
                        ? (int) ($record->points_awarded ?: ($record->sourceDistribution?->items->sum('points_value') ?? 0))
                        : (int) ($record->total_item_points ?? 0))
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->suffix(' نقطة'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'معتمدة',
                        'pending_review' => 'قيد المراجعة',
                        'rejected' => 'مرفوضة',
                        default => $state,
                    })
                    ->colors([
                        'warning' => 'pending_review',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->icons([
                        'heroicon-o-clock' => 'pending_review',
                        'heroicon-o-check-circle' => 'approved',
                        'heroicon-o-x-circle' => 'rejected',
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('invoice_flow')
                    ->label('الاتجاه')
                    ->options([
                        'incoming' => 'وارد — من المصنع',
                        'outgoing' => 'صادر — للقطاعي',
                    ])
                    ->visible(fn () => auth()->user()?->isWholesaleDistributor()),

                Tables\Filters\SelectFilter::make('invoice_type')
                    ->label('نوع الفاتورة')
                    ->options([
                        'plumber_receipt' => 'إيصال سباك',
                        'wholesale_pos' => 'فاتورة جملة',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending_review' => 'قيد المراجعة',
                        'approved' => 'معتمدة',
                        'rejected' => 'مرفوضة',
                    ]),

                Tables\Filters\Filter::make('today')
                    ->label('اليوم فقط')
                    ->query(fn (Builder $query) => $query->whereDate('created_at', today()))
                    ->toggle(),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('عرض'),
                    Tables\Actions\Action::make('print')
                        ->label('طباعة')
                        ->icon('heroicon-o-printer')
                        ->url(fn (Invoice $record) => route('admin.invoices.print', $record))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('issue_sub_invoice')
                        ->label('فاتورة فرعية — تاجر قطاعي')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('warning')
                        ->visible(fn (Invoice $record) => static::canWholesalerIssueSubInvoice($record))
                        ->url(fn (Invoice $record) => static::getUrl('view', ['record' => $record]).'#sub-invoice'),
                    Tables\Actions\Action::make('approve_with_totals')
                        ->label('اعتماد')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Invoice $record) => $record->status === 'pending_review'
                            && ! $record->isWholesalePos()
                            && static::isNetworkAdmin())
                        ->form([
                            Forms\Components\TextInput::make('points_awarded')
                                ->label('النقاط الممنوحة')
                                ->numeric()
                                ->minValue(0)
                                ->default(fn (Invoice $r) => (int) ($r->items->sum('total_points') ?: $r->points_awarded ?: 0))
                                ->suffix('نقطة')
                                ->helperText('افتراضياً مجموع نقاط بنود الفاتورة')
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('اعتماد الفاتورة')
                        ->modalSubmitActionLabel('اعتماد')
                        ->action(function (Invoice $record, array $data) {
                            $record->approveByAdmin(auth()->user(), (int) $data['points_awarded']);
                        }),
                    Tables\Actions\Action::make('refuse')
                        ->label('رفض')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Invoice $record) => $record->status === 'pending_review'
                            && ! $record->isWholesalePos()
                            && static::isNetworkAdmin())
                        ->form([
                            Forms\Components\Textarea::make('reason_ar')->required()->rows(2)->label('سبب الرفض'),
                        ])
                        ->modalHeading('رفض الفاتورة')
                        ->modalSubmitActionLabel('رفض')
                        ->action(function (Invoice $record, array $data) {
                            $record->rejectByAdmin(auth()->user(), ['ar' => $data['reason_ar']]);
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('إجراءات'),
            ])
            ->emptyStateHeading('لا توجد فواتير')
            ->emptyStateDescription('أصدر فاتورة جملة من POS أو انتظر رفع سباك لإيصال')
            ->emptyStateIcon('heroicon-o-document-text')
            ->bulkActions([]);
    }

    public static function infolist(Infolists\Infolist $infolist): Infolists\Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('invoice_header')
                ->view('filament.infolists.invoice-profile-header')
                ->columnSpanFull(),

            Infolists\Components\Section::make('بنود الفاتورة')
                ->icon('heroicon-o-shopping-bag')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product_name')
                                ->label('المنتج')
                                ->state(fn ($record) => localized_name($record->product, 'name'))
                                ->weight('bold'),

                            Infolists\Components\TextEntry::make('quantity')
                                ->label('الكمية')
                                ->badge()
                                ->color('info'),

                            Infolists\Components\TextEntry::make('points_per_unit')
                                ->label('نقطة/وحدة')
                                ->badge()
                                ->color('warning')
                                ->visible(fn ($record) => (float) ($record->points_per_unit ?? 0) > 0),

                            Infolists\Components\TextEntry::make('total_points')
                                ->label('إجمالي النقاط')
                                ->badge()
                                ->color('success')
                                ->visible(fn ($record) => (int) ($record->total_points ?? 0) > 0),
                        ])
                        ->columns(4),
                ])
                ->visible(fn ($record) => $record->items->isNotEmpty()),

            Infolists\Components\Section::make('نظام توزيع النقاط')
                ->schema([
                    Infolists\Components\ViewEntry::make('distribution_panel')
                        ->view('filament.invoices.distribution-panel'),
                ])
                ->visible(fn ($record) => $record->isWholesalePos() && $record->status === 'approved')
                ->icon('heroicon-o-arrows-pointing-out'),

            Infolists\Components\Section::make('المرفق')
                ->schema([
                    Infolists\Components\ImageEntry::make('attachment_path')
                        ->label('معاينة')
                        ->disk('public')
                        ->hidden(fn ($record) => ! in_array(
                            strtolower(pathinfo((string) $record->attachment_path, PATHINFO_EXTENSION)),
                            ['jpg','jpeg','png','webp','gif']
                        ))
                        ->height('280')
                        ->url(fn ($record) => $record->attachment_path ? Storage::disk('public')->url($record->attachment_path) : null, true)
                        ->openUrlInNewTab(),

                    Infolists\Components\TextEntry::make('attachment_open')
                        ->label('رابط الملف')
                        ->visible(fn ($record) => filled($record->attachment_path) && ! in_array(
                            strtolower(pathinfo((string) $record->attachment_path, PATHINFO_EXTENSION)),
                            ['jpg','jpeg','png','webp','gif']
                        ))
                        ->formatStateUsing(fn ($state, $record) => Storage::disk('public')->url($record->attachment_path))
                        ->url(fn ($state) => $state, true)
                        ->icon('heroicon-o-paper-clip')
                        ->color('info'),
                ])
                ->visible(fn ($record) => filled($record->attachment_path))
                ->icon('heroicon-o-paper-clip')
                ->collapsed(),

            Infolists\Components\Section::make('مراجعة إيصال السباك')
                ->schema([
                    Infolists\Components\Grid::make(3)->schema([
                        Infolists\Components\TextEntry::make('reviewer.name')->label('راجعها')->default('—'),
                        Infolists\Components\TextEntry::make('approved_at')->label('تاريخ الاعتماد')->dateTime('Y/m/d H:i')->default('—'),
                        Infolists\Components\TextEntry::make('profit_percent')
                            ->label('نسبة الربح')
                            ->formatStateUsing(fn ($state) => isset($state)
                                ? rtrim(rtrim(number_format((float) $state, 2, '.', ''), '0'), '.').'%'
                                : '—'),
                        Infolists\Components\TextEntry::make('points_awarded')
                            ->label('نقاط ممنوحة')
                            ->badge()
                            ->color('success'),
                    ]),
                    Infolists\Components\KeyValueEntry::make('rejection_reason')
                        ->label('سبب الرفض')
                        ->columnSpanFull()
                        ->visible(fn ($record) => $record->status === 'rejected'),
                ])
                ->visible(fn ($record) => ! $record->isWholesalePos())
                ->icon('heroicon-o-check-badge')
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'pos-create' => Pages\CreateWholesaleInvoice::route('/pos-create'),
            'pos-retail' => Pages\CreateRetailPos::route('/pos-retail'),
            'pos-plumber' => Pages\CreatePlumberPos::route('/pos-plumber'),
            'view'  => Pages\ViewInvoice::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function canWholesalerIssueSubInvoice(Invoice $invoice): bool
    {
        $user = static::currentPanelUser();

        if (! $user?->isWholesaleDistributor()) {
            return false;
        }

        if (! $invoice->isWholesalePos() || $invoice->status !== 'approved') {
            return false;
        }

        if ((int) $invoice->wholesale_distributor_id !== (int) $user->id) {
            return false;
        }

        return static::tierOneParentForWholesaler($invoice, $user) !== null;
    }

    public static function tierOneParentForWholesaler(Invoice $invoice, ?User $wholesaler = null): ?InvoiceDistribution
    {
        $wholesaler ??= static::currentPanelUser();

        if (! $wholesaler) {
            return null;
        }

        return $invoice->distributions()
            ->where('tier', 1)
            ->where('to_user_id', $wholesaler->id)
            ->where('status', 'confirmed')
            ->latest('id')
            ->first();
    }
}
