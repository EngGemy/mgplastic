<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\PointRule;
use App\Models\PointsEntry;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getTabs(): array
    {
        if (! auth()->user()?->isWholesaleDistributor()) {
            return [];
        }

        return [
            'incoming' => Tab::make('الوارد — من المصنع')
                ->icon('heroicon-o-arrow-down-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('invoice_flow', 'incoming')),

            'outgoing' => Tab::make('الصادر — للقطاعي')
                ->icon('heroicon-o-arrow-up-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('invoice_flow', 'outgoing')),

            'all' => Tab::make('الكل')
                ->icon('heroicon-o-queue-list'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return auth()->user()?->isWholesaleDistributor() ? 'incoming' : null;
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $actions = [];

        if (in_array($user?->role, ['super_admin', 'admin'], true)) {
            $actions[] = Actions\Action::make('pos_incoming')
                ->label('فاتورة وارد — مصنع')
                ->icon('heroicon-o-arrow-down-circle')
                ->color('success')
                ->url(fn () => InvoiceResource::getUrl('pos-create'));
        }

        if ($user?->isWholesaleDistributor()) {
            $actions[] = Actions\Action::make('pos_retail')
                ->label('بيع للتاجر القطاعي')
                ->icon('heroicon-o-arrow-up-circle')
                ->color('warning')
                ->url(fn () => InvoiceResource::getUrl('pos-retail'));
        }

        if ($user?->isRetailTrader()) {
            $actions[] = Actions\Action::make('pos_plumber')
                ->label('بيع للسباك')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->url(fn () => InvoiceResource::getUrl('pos-plumber'));
        }

        return $actions;
    }

    public function getTitle(): string
    {
        return 'الفواتير';
    }

    /** Approve invoice + award points */
    public static function approveInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== 'pending_review') {
            Notification::make()
                ->title(__('Already reviewed'))
                ->danger()
                ->body(__('This invoice has already been processed.'))
                ->send();
            return;
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status'          => 'approved',
                'approved_at'     => now(),
                'reviewed_by'     => auth()->id(),
                'rejection_reason'=> null,
            ]);

            $rules = PointRule::query()
                ->where(fn ($q) => $q->whereNull('vendor_store_id')
                    ->orWhere('vendor_store_id', $invoice->vendor_store_id))
                ->where('is_active', true)
                ->get();

            $bestPoints = 0;
            $bestRuleId = null;

            foreach ($rules as $r) {
                if (! $r->appliesToAmount($invoice->total_cents)) continue;
                $pts = $r->type === 'percent'
                    ? (int) floor(($r->percent_rate / 100) * ($invoice->total_cents / 100))
                    : (int) $r->fixed_points;
                if ($pts > $bestPoints) {
                    $bestPoints = $pts;
                    $bestRuleId = $r->id;
                }
            }

            if ($bestPoints > 0) {
                $wallet = $invoice->plumber->wallet($invoice->currency);
                $wallet->increment('balance_points', $bestPoints);

                PointsEntry::create([
                    'plumber_id'   => $invoice->plumber_id,
                    'points_delta' => +$bestPoints,
                    'source_type'  => Invoice::class,
                    'source_id'    => $invoice->id,
                    'meta'         => ['rule_id' => $bestRuleId],
                ]);

                $wallet->transactions()->create([
                    'type'         => 'credit',
                    'amount_cents' => 0,
                    'points_delta' => +$bestPoints,
                    'description'  => __('Points for invoice #:id', ['id' => $invoice->id]),
                    'meta'         => ['rule_id' => $bestRuleId],
                    'related_type' => Invoice::class,
                    'related_id'   => $invoice->id,
                    'created_by'   => auth()->id(),
                ]);
            }
        });

        Notification::make()
            ->title(__('Invoice Approved'))
            ->body(__('Points have been successfully awarded to the plumber’s wallet.'))
            ->success()
            ->persistent()
            ->send();
    }

    /** Reject invoice with reason */
    public static function rejectInvoice(Invoice $invoice, array $data): void
    {
        if ($invoice->status !== 'pending_review') {
            Notification::make()
                ->title(__('Already reviewed'))
                ->danger()
                ->body(__('This invoice has already been processed.'))
                ->send();
            return;
        }

        $invoice->update([
            'status'          => 'rejected',
            'reviewed_by'     => auth()->id(),
            'rejection_reason'=> [
                'en' => $data['reason_en'] ?? '',
                'ar' => $data['reason_ar'] ?? '',
            ],
        ]);

        Notification::make()
            ->title(__('Invoice Rejected'))
            ->body(__('The invoice has been rejected and reasons saved.'))
            ->danger()
            ->persistent()
            ->send();
    }
}
