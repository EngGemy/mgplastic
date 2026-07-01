<?php

namespace App\Filament\Resources\InvoiceDistributionResource\Pages;

use App\Filament\Resources\InvoiceDistributionResource;
use App\Services\DistributionService;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoiceDistribution extends ViewRecord
{
    protected static string $resource = InvoiceDistributionResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'fromUser', 'toUser', 'invoice',
            'items.invoiceItem.product.translations',
        ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('distribution_header')
                ->view('filament.infolists.distribution-profile-header')
                ->columnSpanFull(),

            Infolists\Components\Section::make('البنود والنقاط')
                ->icon('heroicon-o-shopping-bag')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product_name')
                                ->label('المنتج')
                                ->state(fn ($record) => localized_name($record->invoiceItem?->product, 'name'))
                                ->weight('bold'),

                            Infolists\Components\TextEntry::make('quantity')
                                ->label('الكمية')
                                ->badge()
                                ->color('info'),

                            Infolists\Components\TextEntry::make('invoiceItem.points_per_unit')
                                ->label('نقطة/وحدة')
                                ->badge()
                                ->color('warning'),

                            Infolists\Components\TextEntry::make('points_value')
                                ->label('إجمالي النقاط')
                                ->badge()
                                ->color('success')
                                ->weight('bold'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => $this->record->status === 'draft'),

            Actions\Action::make('confirm')
                ->label('تأكيد التوزيع')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft')
                ->requiresConfirmation()
                ->modalHeading('تأكيد التوزيع النهائي')
                ->modalDescription(
                    $this->record->tier === 3
                        ? 'سيتم منح النقاط للسباك فوراً ولا يمكن التراجع.'
                        : 'بعد التأكيد يمكن للطبقة التالية التوزيع منه.'
                )
                ->action(function () {
                    try {
                        app(DistributionService::class)->confirmDistribution($this->record);
                        Notification::make()->success()
                            ->title('تم بنجاح')
                            ->body($this->record->tier === 3 ? 'تم منح النقاط للسباك ✓' : 'التوزيع مؤكد')
                            ->send();
                        $this->refreshFormData(['status', 'confirmed_at', 'points_awarded_at']);
                    } catch (\Exception $e) {
                        Notification::make()->danger()
                            ->title('خطأ')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
