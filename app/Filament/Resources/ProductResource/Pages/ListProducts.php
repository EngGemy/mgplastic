<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ProductSheetService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('download_product_template')
                    ->label('تنزيل النموذج')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function () {
                        $path = app(ProductSheetService::class)->templatePath();

                        return response()->download($path, 'products-template.xlsx')->deleteFileAfterSend(true);
                    }),

                Actions\Action::make('export_products_sheet')
                    ->label('تصدير الشيت')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function () {
                        $path = app(ProductSheetService::class)->exportPath($this->getFilteredTableQuery());

                        return response()->download($path, 'products-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend(true);
                    }),

                Actions\Action::make('import_products_sheet')
                    ->label('رفع الشيت')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->modalHeading('رفع شيت المنتجات')
                    ->modalDescription('ارفع ملف النموذج بعد تعبئته. الصف الذي فيه رقم منتج يتحدّث، والصف بدون رقم يُضيف منتجاً جديداً. عمود «عدد الوحدات في الصندوق» وأسعار القطعة والصندوق تتحدث من نفس الشيت.')
                    ->modalSubmitActionLabel('استيراد')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('ملف الإكسل')
                            ->disk('local')
                            ->directory('imports/products')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                                'application/csv',
                            ])
                            ->maxSize(20480)
                            ->required()
                            ->helperText('ورقة «التصنيفات» تعرض رقم كل تصنيف وصورته. انسخ الرقم إلى عمود «رقم التصنيف».'),
                    ])
                    ->action(function (array $data) {
                        $stored = is_array($data['file']) ? ($data['file'][0] ?? null) : $data['file'];
                        if (! is_string($stored) || $stored === '') {
                            Notification::make()->danger()->title('لم يتم اختيار ملف')->send();

                            return;
                        }

                        $absolute = Storage::disk('local')->path($stored);

                        try {
                            $result = app(ProductSheetService::class)->import($absolute);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('تعذّر الاستيراد')->body($e->getMessage())->send();

                            return;
                        } finally {
                            Storage::disk('local')->delete($stored);
                        }

                        $summary = 'أُضيف '.$result['created'].' منتج، وتحدّث '.$result['updated'].'.';
                        $details = array_slice(array_merge($result['errors'], $result['warnings']), 0, 6);
                        $extra = count($result['errors']) + count($result['warnings']) - count($details);
                        $body = $summary;
                        if ($details !== []) {
                            $body .= ' '.implode(' | ', $details);
                            if ($extra > 0) {
                                $body .= ' | و'.$extra.' ملاحظة أخرى';
                            }
                        }

                        $notification = Notification::make()->title('تم استيراد الشيت')->body($body);
                        if ($result['created'] + $result['updated'] === 0) {
                            $notification->warning()->title('لم يُستورد أي منتج');
                        } elseif ($result['errors'] !== []) {
                            $notification->warning();
                        } else {
                            $notification->success();
                        }
                        $notification->send();
                    }),
            ])
                ->label('شيت المنتجات')
                ->icon('heroicon-o-table-cells')
                ->button(),

            Actions\Action::make('points_report')
                ->label('تقرير النقاط')
                ->icon('heroicon-o-presentation-chart-bar')
                ->color('info')
                ->url(fn () => \App\Filament\Pages\ProductsReport::getUrl()),

            Actions\CreateAction::make()
                ->label('إضافة منتج')
                ->url(fn () => ProductResource::getUrl('create')),
        ];
    }
}
