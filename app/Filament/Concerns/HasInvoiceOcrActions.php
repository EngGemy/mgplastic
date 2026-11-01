<?php

namespace App\Filament\Concerns;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Services\Ai\GeminiClient;
use App\Services\InvoiceOcrService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;

trait HasInvoiceOcrActions
{
    protected function invoiceOcrHeaderActions(): array
    {
        return [
            Actions\Action::make('ocr_upload_attachment')
                ->label('رفع صورة للـ OCR')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn () => blank($this->record->attachment_path)
                    || ! in_array(
                        strtolower(pathinfo((string) $this->record->attachment_path, PATHINFO_EXTENSION)),
                        ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                        true
                    ))
                ->form([
                    Forms\Components\FileUpload::make('attachment')
                        ->label('صورة الفاتورة')
                        ->image()
                        ->disk('public')
                        ->directory('invoices/'.date('Y/m/d'))
                        ->required()
                        ->maxSize(8192),
                ])
                ->action(function (array $data) {
                    $path = $data['attachment'] ?? null;
                    if (! filled($path)) {
                        return;
                    }
                    $this->record->forceFill([
                        'attachment_path' => $path,
                        'ocr_status' => 'idle',
                        'ocr_error' => null,
                        'ocr_draft' => null,
                        'ocr_confirmed_at' => null,
                    ])->save();
                    Notification::make()->success()->title('تم رفع الصورة')->send();
                    $this->record->refresh();
                }),

            Actions\Action::make('ocr_read_invoice')
                ->label('قراءة الفاتورة (OCR)')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->visible(fn () => filled($this->record->attachment_path)
                    && in_array(
                        strtolower(pathinfo((string) $this->record->attachment_path, PATHINFO_EXTENSION)),
                        ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                        true
                    ))
                ->disabled(fn () => ! app(GeminiClient::class)->enabled())
                ->tooltip(fn () => app(GeminiClient::class)->enabled()
                    ? 'يستخرج البنود من الصورة عبر Gemini'
                    : 'أضف GEMINI_API_KEY في .env')
                ->requiresConfirmation()
                ->modalHeading('قراءة صورة الفاتورة')
                ->modalDescription('سيتم تحليل الصورة (خط يد / حراري / مطبوع) وإرجاع مسودة قابلة للمراجعة. قد يستغرق حتى دقيقة.')
                ->modalSubmitActionLabel('ابدأ القراءة')
                ->action(function () {
                    if (! app(GeminiClient::class)->enabled()) {
                        Notification::make()->danger()->title('Gemini غير مفعّل')->body('أضف GEMINI_API_KEY')->send();

                        return;
                    }

                    // Sync for local admin trial — immediate feedback without a worker.
                    ProcessInvoiceOcrJob::dispatchSync($this->record->fresh());
                    $this->record->refresh();

                    if ($this->record->ocr_status === 'ready') {
                        Notification::make()
                            ->success()
                            ->title('تم استخراج المسودة')
                            ->body('راجع البنود وعدّلها ثم أكّد المسودة.')
                            ->send();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('فشلت القراءة')
                            ->body($this->record->ocr_error ?: 'حاول مرة أخرى')
                            ->send();
                    }
                }),

            Actions\Action::make('ocr_edit_draft')
                ->label('مراجعة مسودة OCR')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn () => in_array($this->record->ocr_status, ['ready', 'failed'], true)
                    && filled($this->record->ocr_draft))
                ->fillForm(function () {
                    $draft = (array) ($this->record->ocr_draft ?? []);

                    return [
                        'vendor' => $draft['vendor'] ?? null,
                        'number' => $draft['number'] ?? null,
                        'date' => $draft['date'] ?? null,
                        'currency' => $draft['currency'] ?? 'LYD',
                        'subtotal' => $draft['subtotal'] ?? null,
                        'tax' => $draft['tax'] ?? null,
                        'total' => $draft['total'] ?? null,
                        'source_type' => $draft['source_type'] ?? 'unknown',
                        'overall_confidence' => $draft['overall_confidence'] ?? null,
                        'lines' => $draft['lines'] ?? [],
                    ];
                })
                ->form($this->ocrDraftFormSchema())
                ->modalHeading('مراجعة بنود الفاتورة المستخرجة')
                ->modalSubmitActionLabel('حفظ المسودة')
                ->action(function (array $data) {
                    app(InvoiceOcrService::class)->saveDraft($this->record, $data);
                    $this->record->refresh();
                    Notification::make()->success()->title('تم حفظ المسودة')->send();
                }),

            Actions\Action::make('ocr_confirm_draft')
                ->label('تأكيد مسودة OCR')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $this->record->ocr_status === 'ready' && filled($this->record->ocr_draft))
                ->requiresConfirmation()
                ->modalHeading('تأكيد المسودة على الفاتورة')
                ->modalDescription('يُحدَّث رقم الفاتورة والإجماليات من المسودة. البنود تبقى في المسودة للمراجعة (بدون إنشاء منتجات تلقائياً).')
                ->action(function () {
                    try {
                        app(InvoiceOcrService::class)->confirm($this->record);
                        $this->record->refresh();
                        Notification::make()->success()->title('تم تأكيد المسودة على الفاتورة')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('تعذّر التأكيد')->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    protected function ocrDraftFormSchema(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('vendor')->label('التاجر / المحل'),
                Forms\Components\TextInput::make('number')->label('رقم الفاتورة'),
                Forms\Components\TextInput::make('date')->label('التاريخ')->placeholder('YYYY-MM-DD'),
                Forms\Components\TextInput::make('currency')->label('العملة')->default('LYD'),
                Forms\Components\TextInput::make('subtotal')->label('المجموع الفرعي')->numeric(),
                Forms\Components\TextInput::make('tax')->label('الضريبة')->numeric(),
                Forms\Components\TextInput::make('total')->label('الإجمالي')->numeric(),
                Forms\Components\Select::make('source_type')
                    ->label('نوع المصدر')
                    ->options([
                        'handwritten' => 'خط يد',
                        'thermal' => 'حراري',
                        'printed' => 'مطبوع',
                        'unknown' => 'غير معروف',
                    ]),
                Forms\Components\TextInput::make('overall_confidence')
                    ->label('ثقة عامة')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(1)
                    ->step(0.01)
                    ->disabled(),
            ]),
            Forms\Components\Repeater::make('lines')
                ->label('البنود')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('الصنف')->required()->columnSpan(2),
                    Forms\Components\TextInput::make('qty')->label('الكمية')->numeric()->required()->default(1),
                    Forms\Components\TextInput::make('unit_price')->label('سعر الوحدة')->numeric(),
                    Forms\Components\TextInput::make('line_total')->label('الإجمالي')->numeric(),
                    Forms\Components\TextInput::make('confidence')->label('ثقة')->numeric()->minValue(0)->maxValue(1)->step(0.01),
                ])
                ->columns(5)
                ->addActionLabel('إضافة بند')
                ->reorderable()
                ->collapsible(),
        ];
    }
}
