<?php

namespace App\Filament\Resources\InvoiceDistributionResource\Pages;

use App\Filament\Resources\InvoiceDistributionResource;
use App\Models\InvoiceDistribution;
use App\Services\DistributionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceDistribution extends CreateRecord
{
    protected static string $resource = InvoiceDistributionResource::class;

    public function mount(): void
    {
        parent::mount();

        $fill = array_filter([
            'invoice_id' => request()->query('invoice_id'),
            'tier' => request()->query('tier'),
            'from_user_id' => request()->query('from_user_id'),
            'to_user_id' => request()->query('to_user_id'),
        ], fn ($v) => $v !== null && $v !== '');

        if ($fill !== []) {
            $this->form->fill($fill);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function handleRecordCreation(array $data): InvoiceDistribution
    {
        $invoice = \App\Models\Invoice::findOrFail($data['invoice_id']);
        $fromUser = \App\Models\User::findOrFail($data['from_user_id']);
        $toUser = \App\Models\User::findOrFail($data['to_user_id']);

        try {
            $distribution = app(DistributionService::class)->createDistribution(
                invoice: $invoice,
                fromUser: $fromUser,
                toUser: $toUser,
                tier: (int) $data['tier'],
                items: $data['items'] ?? [],
                parentId: $data['parent_distribution_id'] ?? null,
            );

            Notification::make()->success()
                ->title('تم إنشاء التوزيع')
                ->body('يمكنك الآن مراجعته وتأكيده')
                ->send();

            return $distribution;
        } catch (\DomainException $e) {
            Notification::make()->danger()
                ->title('خطأ في البيانات')
                ->body($e->getMessage())
                ->send();

            $this->halt();
        }
    }
}
