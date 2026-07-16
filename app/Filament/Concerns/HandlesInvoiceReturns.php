<?php

namespace App\Filament\Concerns;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Services\InvoiceReturnService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\HtmlString;

trait HandlesInvoiceReturns
{
    protected function resolveReturnInvoice(): Invoice
    {
        if (isset($this->record) && $this->record instanceof Invoice) {
            return $this->record;
        }

        if (method_exists($this, 'getOwnerRecord')) {
            $owner = $this->getOwnerRecord();
            if ($owner instanceof Invoice) {
                return $owner;
            }
        }

        throw new \RuntimeException('لا يمكن تحديد الفاتورة للمرتجع');
    }

    protected function invoiceReturnAction(): Actions\Action
    {
        return Actions\Action::make('return_invoice')
            ->label('مرتجع على الفاتورة')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn () => $this->canReturnThisInvoice())
            ->modalHeading('مرتجع بضاعة ونقاط')
            ->modalDescription(null)
            ->modalSubmitActionLabel('تأكيد المرتجع')
            ->modalCancelActionLabel('إلغاء')
            ->modalWidth('3xl')
            ->form(fn () => $this->returnFormSchema())
            ->action(fn (array $data) => $this->executeInvoiceReturn($data));
    }

    protected function invoiceReturnTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('return_invoice')
            ->label('تسجيل مرتجع')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn () => $this->canReturnThisInvoice())
            ->modalHeading('مرتجع بضاعة ونقاط')
            ->modalDescription(null)
            ->modalSubmitActionLabel('تأكيد المرتجع')
            ->modalCancelActionLabel('إلغاء')
            ->modalWidth('3xl')
            ->form(fn () => $this->returnFormSchema())
            ->action(fn (array $data) => $this->executeInvoiceReturn($data));
    }

    protected function executeInvoiceReturn(array $data): void
    {
        try {
            $invoice = $this->resolveReturnInvoice();

            $lines = collect($data['items'] ?? [])
                ->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)
                ->map(fn ($row) => [
                    'invoice_item_id' => (int) $row['invoice_item_id'],
                    'quantity' => (int) $row['quantity'],
                ])
                ->values()
                ->all();

            if ($lines === []) {
                Notification::make()->warning()->title('لم تُحدَّد أي كمية للإرجاع')->send();

                return;
            }

            $ret = app(InvoiceReturnService::class)
                ->returnOutgoingInvoice($invoice, $lines, auth()->user(), $data['note'] ?? null);

            Notification::make()
                ->success()
                ->title('تم تسجيل المرتجع ✓')
                ->body("رقم {$ret->return_number} — أُرجع {$ret->total_quantity} وحدة / {$ret->total_points} نقطة وخُصمت من صافي الفاتورة")
                ->persistent()
                ->send();

            $invoice->refresh()->load([
                'returns.items.product.translations',
                'sourceDistribution.items.invoiceItem.product.translations',
                'items.product.translations',
            ]);

            if (property_exists($this, 'record') && $this->record instanceof Invoice) {
                $this->record = $invoice;
            }
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر المرتجع')->body($e->getMessage())->send();
        }
    }

    protected function canReturnThisInvoice(): bool
    {
        $invoice = $this->resolveReturnInvoice();
        $user = auth()->user();

        if (! $user || ! $invoice->isWholesalePos() || ! $invoice->isOutgoing()) {
            return false;
        }

        $distribution = $invoice->sourceDistribution;
        if (! $distribution || ! in_array($distribution->status, ['confirmed', 'points_awarded'], true)) {
            return false;
        }

        $returnable = app(InvoiceReturnService::class)->returnableLines($distribution);
        if ($returnable === []) {
            return false;
        }

        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return true;
        }

        return in_array((int) $user->id, [
            (int) $distribution->from_user_id,
            (int) $distribution->to_user_id,
        ], true);
    }

    protected function returnFormSchema(): array
    {
        $invoice = $this->resolveReturnInvoice();
        $distribution = $invoice->sourceDistribution;
        $lines = $distribution
            ? app(InvoiceReturnService::class)->returnableLines($distribution)
            : [];

        $defaults = collect($lines)->map(fn ($line) => [
            'invoice_item_id' => $line['invoice_item_id'],
            'product_name' => $line['product_name'],
            'returnable' => $line['returnable'],
            'points_per_unit' => $line['points_per_unit'],
            'quantity' => 0,
        ])->values()->all();

        return [
            Forms\Components\ViewField::make('return_shell')
                ->view('filament.forms.invoice-return-shell')
                ->dehydrated(false)
                ->columnSpanFull(),

            Forms\Components\Repeater::make('items')
                ->label(new HtmlString('<span style="font-family:Cairo,sans-serif;font-weight:900;font-size:13px;color:#0f172a">بنود المرتجع</span>'))
                ->schema([
                    Forms\Components\Hidden::make('invoice_item_id'),
                    Forms\Components\Hidden::make('returnable'),
                    Forms\Components\Hidden::make('points_per_unit'),
                    Forms\Components\Hidden::make('product_name'),

                    Forms\Components\ViewField::make('line_meta')
                        ->view('filament.forms.invoice-return-line-meta')
                        ->dehydrated(false)
                        ->columnSpanFull(),

                    Forms\Components\Grid::make(12)
                        ->schema([
                            Forms\Components\TextInput::make('quantity')
                                ->label('كمية المرتجع')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required()
                                ->live(onBlur: false)
                                ->extraFieldWrapperAttributes(['class' => 'ret-qty-wrap'])
                                ->rule(function (Forms\Get $get) {
                                    return function (string $attribute, $value, $fail) use ($get) {
                                        $max = (int) $get('returnable');
                                        if ((int) $value > $max) {
                                            $fail("الحد الأقصى للإرجاع هو {$max}");
                                        }
                                    };
                                })
                                ->columnSpan(8),

                            Forms\Components\ViewField::make('line_points')
                                ->view('filament.forms.invoice-return-line-points')
                                ->dehydrated(false)
                                ->columnSpan(4),
                        ])
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->default($defaults)
                ->dehydrated()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->collapsible(false)
                ->extraAttributes(['class' => 'ret-shell'])
                ->columnSpanFull(),

            Forms\Components\ViewField::make('totals_preview')
                ->view('filament.forms.invoice-return-totals')
                ->dehydrated(false)
                ->columnSpanFull(),

            Forms\Components\Placeholder::make('note_label')
                ->label('')
                ->content(new HtmlString('<span class="ret-note-label">ملاحظة المرتجع (اختياري)</span>')),

            Forms\Components\Textarea::make('note')
                ->label('')
                ->placeholder('سبب الإرجاع أو أي تفاصيل...')
                ->rows(2)
                ->extraAttributes(['class' => 'ret-shell']),
        ];
    }

    protected function resolveReturnDistribution(): ?InvoiceDistribution
    {
        return $this->resolveReturnInvoice()->sourceDistribution;
    }
}
