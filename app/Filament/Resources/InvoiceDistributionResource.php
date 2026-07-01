<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesByNetworkRole;
use App\Filament\Resources\InvoiceDistributionResource\Pages;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\SystemLabel;
use App\Models\User;
use App\Services\DistributionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceDistributionResource extends Resource
{
    use ScopesByNetworkRole;

    protected static ?string $model = InvoiceDistribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-out';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('distributions', 'التوزيعات');
    }

    public static function getModelLabel(): string
    {
        return SystemLabel::get('distributions', 'توزيع');
    }

    public static function getPluralModelLabel(): string
    {
        return SystemLabel::get('distributions', 'التوزيعات');
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::scopeDistributionsForRole(parent::getEloquentQuery());
    }

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'wholesale_distributor', 'retail_trader'], true);
    }

    public static function canView($record): bool
    {
        return $record instanceof InvoiceDistribution && static::userCanAccessDistribution($record);
    }

    public static function canCreate(): bool
    {
        return static::isNetworkAdmin()
            || in_array(auth()->user()?->role, ['wholesale_distributor', 'retail_trader'], true);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات التوزيع')
                ->schema([
                    Forms\Components\Select::make('invoice_id')
                        ->label('الفاتورة')
                        ->options(Invoice::query()->pluck('number', 'id'))
                        ->searchable()
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('tier')
                        ->label('طبقة التوزيع')
                        ->options([
                            1 => '① مصنع → موزع جملة',
                            2 => '② موزع جملة → تاجر قطاعي',
                            3 => '③ تاجر قطاعي → سباك (نقاط)',
                        ])
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('from_user_id')
                        ->label('من')
                        ->options(function (Forms\Get $get) {
                            $tier = $get('tier');
                            $roleMap = [1 => 'super_admin', 2 => 'wholesale_distributor', 3 => 'retail_trader'];
                            if (! $tier || ! isset($roleMap[$tier])) {
                                return [];
                            }

                            return User::where('role', $roleMap[$tier])->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('to_user_id')
                        ->label('إلى')
                        ->options(function (Forms\Get $get) {
                            $tier = $get('tier');
                            $roleMap = [1 => 'wholesale_distributor', 2 => 'retail_trader', 3 => 'plumber'];
                            if (! $tier || ! isset($roleMap[$tier])) {
                                return [];
                            }

                            return User::where('role', $roleMap[$tier])->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('parent_distribution_id')
                        ->label('التوزيع الأب')
                        ->helperText('للطبقة 2 و3 فقط — حدد التوزيع الذي تستمد منه الكمية')
                        ->options(function (Forms\Get $get) {
                            $invoiceId = $get('invoice_id');
                            $tier = $get('tier');
                            if (! $invoiceId || ! $tier || $tier == 1) {
                                return [];
                            }

                            return InvoiceDistribution::where('invoice_id', $invoiceId)
                                ->where('tier', $tier - 1)
                                ->where('status', 'confirmed')
                                ->with('toUser')
                                ->get()
                                ->mapWithKeys(fn ($d) => [
                                    $d->id => "#{$d->id} → {$d->toUser->name}",
                                ]);
                        })
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => in_array($get('tier'), [2, 3])),
                ])->columns(2),

            Forms\Components\Section::make('البنود (المنتجات والكميات)')
                ->description('حدد كمية كل منتج في هذا التوزيع. لا يمكن تجاوز الكمية المتاحة.')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('invoice_item_id')
                                ->label('المنتج')
                                ->options(function (Forms\Get $get) {
                                    $invoiceId = $get('../../invoice_id');
                                    if (! $invoiceId) {
                                        return [];
                                    }

                                    return \App\Models\InvoiceItem::where('invoice_id', $invoiceId)
                                        ->with('product.translations')
                                        ->get()
                                        ->mapWithKeys(fn ($item) => [
                                            $item->id => ($item->product->translate(app()->getLocale())?->name
                                                ?? $item->product->translate('en')?->name
                                                ?? 'منتج')." (متاح: {$item->quantity})",
                                        ]);
                                })
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                            Forms\Components\TextInput::make('quantity')
                                ->label('الكمية')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ])
                        ->columns(2)
                        ->addActionLabel('إضافة منتج')
                        ->defaultItems(1)
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('invoice.number')
                    ->label('الفاتورة')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('tier')
                    ->label('الطبقة')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        1 => '① مصنع → موزع',
                        2 => '② موزع → تاجر',
                        3 => '③ تاجر → سباك',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        1 => 'primary',
                        2 => 'warning',
                        3 => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('fromUser.name')
                    ->label('من')
                    ->icon('heroicon-o-user')
                    ->searchable(),

                Tables\Columns\TextColumn::make('toUser.name')
                    ->label('إلى')
                    ->icon('heroicon-o-user-circle')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'draft' => 'مسودة',
                        'confirmed' => 'مؤكد',
                        'points_awarded' => 'نقاط مُمنوحة',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'draft' => 'gray',
                        'confirmed' => 'warning',
                        'points_awarded' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn ($state) => match ($state) {
                        'draft' => 'heroicon-o-pencil',
                        'confirmed' => 'heroicon-o-check',
                        'points_awarded' => 'heroicon-o-star',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('المنتجات')
                    ->counts('items')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tier')
                    ->label('الطبقة')
                    ->options([
                        1 => '① مصنع → موزع',
                        2 => '② موزع → تاجر',
                        3 => '③ تاجر → سباك',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'confirmed' => 'مؤكد',
                        'points_awarded' => 'نقاط مُمنوحة',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),

                Tables\Actions\Action::make('confirm')
                    ->label('تأكيد التوزيع')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (InvoiceDistribution $record) => $record->status === 'draft'
                        && static::userCanConfirmDistribution($record))
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد التوزيع')
                    ->modalDescription('بعد التأكيد، سيتم خصم الكميات وإن كان الطبقة 3 ستُمنح النقاط للسباك فوراً.')
                    ->modalSubmitActionLabel('نعم، تأكيد')
                    ->action(function (InvoiceDistribution $record) {
                        if (! static::userCanConfirmDistribution($record)) {
                            Notification::make()->danger()->title('غير مصرح')->send();

                            return;
                        }

                        try {
                            app(DistributionService::class)->confirmDistribution($record);
                            Notification::make()
                                ->success()
                                ->title('تم تأكيد التوزيع')
                                ->body($record->tier === 3 ? 'تم منح النقاط للسباك بنجاح ✓' : 'يمكن الآن التوزيع للطبقة التالية')
                                ->send();
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('خطأ في التوزيع')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (InvoiceDistribution $record) => $record->status === 'draft'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('لا يوجد توزيعات بعد')
            ->emptyStateDescription('ابدأ بإنشاء توزيع جديد من الفاتورة المعتمدة')
            ->emptyStateIcon('heroicon-o-arrows-pointing-out');
    }

    public static function userCanConfirmDistribution(InvoiceDistribution $record): bool
    {
        $user = static::currentPanelUser();

        if (! $user) {
            return false;
        }

        if (static::isNetworkAdmin($user)) {
            return true;
        }

        if ($user->isWholesaleDistributor()) {
            return ($record->tier === 1 && (int) $record->to_user_id === (int) $user->id)
                || ($record->tier === 2 && (int) $record->from_user_id === (int) $user->id);
        }

        if ($user->isRetailTrader()) {
            return $record->tier === 3 && (int) $record->from_user_id === (int) $user->id;
        }

        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoiceDistributions::route('/'),
            'create' => Pages\CreateInvoiceDistribution::route('/create'),
            'view' => Pages\ViewInvoiceDistribution::route('/{record}'),
            'edit' => Pages\EditInvoiceDistribution::route('/{record}/edit'),
        ];
    }
}
