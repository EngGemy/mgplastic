<?php

namespace App\Filament\Concerns;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Services\InvoiceReturnService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;

trait HandlesInvoiceReturns
{
    protected function invoiceReturnAction(): Actions\Action
    {
        return Actions\Action::make('return_invoice')
            ->label('مرتجع على الفاتورة')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn () => $this->canReturnThisInvoice())
            ->modalHeading('مرتجع بضاعة ونقاط')
            ->modalDescription('حدّد الكميات المراد إرجاعها. تُخصم النقاط من المستلم وتُعاد للمورّد، ويُحدَّث صافي الفاتورة فوراً.')
            ->modalSubmitActionLabel('تأكيد المرتجع')
            ->modalWidth('2xl')
            ->form(fn () => $this->returnFormSchema())
            ->action(function (array $data) {
                try {
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
                        ->returnOutgoingInvoice($this->record, $lines, auth()->user(), $data['note'] ?? null);

                    Notification::make()
                        ->success()
                        ->title('تم تسجيل المرتجع ✓')
                        ->body("رقم {$ret->return_number} — أُرجع {$ret->total_quantity} وحدة / {$ret->total_points} نقطة وخُصمت من صافي الفاتورة")
                        ->persistent()
                        ->send();

                    $this->record->refresh()->load([
                        'returns.items.product.translations',
                        'sourceDistribution.items.invoiceItem.product.translations',
                        'items.product.translations',
                    ]);
                } catch (\DomainException $e) {
                    Notification::make()->danger()->title('تعذّر المرتجع')->body($e->getMessage())->send();
                }
            });
    }

    protected function canReturnThisInvoice(): bool
    {
        /** @var Invoice $invoice */
        $invoice = $this->record;
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
        /** @var Invoice $invoice */
        $invoice = $this->record;
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
            Forms\Components\Placeholder::make('return_hint')
                ->label('')
                ->content('اترك الكمية 0 للبنود التي لا تريد إرجاعها. الصافي والنقاط يُحدَّثان تلقائياً بعد التأكيد.'),

            Forms\Components\Repeater::make('items')
                ->label('بنود المرتجع')
                ->schema([
                    Forms\Components\Hidden::make('invoice_item_id'),
                    Forms\Components\Hidden::make('returnable'),
                    Forms\Components\Hidden::make('points_per_unit'),
                    Forms\Components\Hidden::make('product_name'),

                    Forms\Components\Placeholder::make('product_label')
                        ->label('المنتج')
                        ->content(fn (Forms\Get $get) => $get('product_name') ?: '—')
                        ->columnSpan(2),

                    Forms\Components\Placeholder::make('available_label')
                        ->label('متاح للإرجاع')
                        ->content(fn (Forms\Get $get) => number_format((int) $get('returnable')).' وحدة'),

                    Forms\Components\Placeholder::make('points_label')
                        ->label('نقطة / وحدة')
                        ->content(fn (Forms\Get $get) => rtrim(rtrim(number_format((float) $get('points_per_unit'), 2), '0'), '.') ?: '0'),

                    Forms\Components\TextInput::make('quantity')
                        ->label('كمية المرتجع')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->live()
                        ->rule(function (Forms\Get $get) {
                            return function (string $attribute, $value, $fail) use ($get) {
                                $max = (int) $get('returnable');
                                if ((int) $value > $max) {
                                    $fail("الحد الأقصى للإرجاع هو {$max}");
                                }
                            };
                        }),

                    Forms\Components\Placeholder::make('line_points')
                        ->label('نقاط هذا البند')
                        ->content(fn (Forms\Get $get) => number_format(
                            (int) floor((int) $get('quantity') * (float) $get('points_per_unit'))
                        ).' نقطة'),
                ])
                ->columns(3)
                ->default($defaults)
                ->dehydrated()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->itemLabel(fn (array $state) => $state['product_name'] ?? 'بند')
                ->collapsible()
                ->collapsed(false),

            Forms\Components\Placeholder::make('totals_preview')
                ->label('إجمالي المرتجع (حسب الكميات المدخلة)')
                ->content(function (Forms\Get $get) {
                    $items = $get('items') ?? [];
                    $qty = 0;
                    $pts = 0;
                    foreach ($items as $row) {
                        $q = (int) ($row['quantity'] ?? 0);
                        $qty += $q;
                        $pts += (int) floor($q * (float) ($row['points_per_unit'] ?? 0));
                    }

                    return "{$qty} وحدة — {$pts} نقطة ستُخصم من المستلم وتُعاد للمورّد";
                }),

            Forms\Components\Textarea::make('note')
                ->label('ملاحظة المرتجع (اختياري)')
                ->placeholder('سبب الإرجاع أو أي تفاصيل...')
                ->rows(2),
        ];
    }

    protected function resolveReturnDistribution(): ?InvoiceDistribution
    {
        return $this->record->sourceDistribution;
    }
}
