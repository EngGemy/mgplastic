<?php

namespace App\Filament\Resources\WithdrawalRequestResource\Pages;

use App\Filament\Resources\WithdrawalRequestResource;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListWithdrawalRequests extends ListRecords
{
    protected static string $resource = WithdrawalRequestResource::class;

    protected static string $view = 'filament.resources.withdrawal-requests.pages.list-withdrawal-requests';

    #[Url(as: 'tab')]
    public string $statusTab = 'all';

    public function getTitle(): string
    {
        return 'طلبات السحب';
    }

    public function setStatusTab(string $tab): void
    {
        $this->statusTab = $tab;
        $this->resetTable();
    }

    /** @return array<string, int> */
    public function getStatusCounts(): array
    {
        $stats = WithdrawalRequestService::stats();

        return [
            'all' => $stats['pending'] + $stats['paid'] + $stats['rejected'],
            'pending' => $stats['pending'],
            'paid' => $stats['paid'],
            'rejected' => $stats['rejected'],
        ];
    }

    public function getPendingAmountLabel(): string
    {
        $cents = WithdrawalRequestService::stats()['total_amount_pending'];

        return number_format($cents / 100, 2).' د.ل';
    }

    protected function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getTableQuery();

        if ($this->statusTab !== 'all') {
            $query->where('status', $this->statusTab);
        }

        return $query;
    }

    public static function markPaid(WithdrawalRequest $req, array $data): void
    {
        try {
            WithdrawalRequestService::markPaid($req, $data);
            Notification::make()->title('تم تأكيد الدفع')->success()->send();
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function rejectAndRefund(WithdrawalRequest $req, array $data): void
    {
        try {
            WithdrawalRequestService::rejectAndRefund($req, $data);
            Notification::make()->title('تم رفض الطلب وإرجاع الرصيد')->success()->send();
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
