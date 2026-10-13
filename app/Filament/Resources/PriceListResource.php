<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\PriceListResource\Pages;
use App\Models\FactoryCustomer;
use App\Models\PriceList;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceListResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = PriceList::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'قوائم الأسعار';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'قائمة أسعار';

    protected static ?string $pluralModelLabel = 'قوائم الأسعار';

    public static function getNavigationLabel(): string
    {
        return 'قوائم الأسعار';
    }

    public static function getNavigationBadge(): ?string
    {
        $drafts = PriceList::query()->where('status', 'draft')->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('factory_customer_id')
                            ->label('عميل المصنع')
                            ->relationship('customer', 'company_name')
                            ->getOptionLabelFromRecordUsing(fn (FactoryCustomer $record) => $record->optionLabel())
                            ->searchable(['company_name', 'contact_name', 'phone'])
                            ->preload()
                            ->native(false)
                            ->placeholder('ابحث بالشركة أو الهاتف، أو أضف عميلاً جديداً')
                            ->createOptionModalHeading('عميل مصنع جديد')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('company_name')
                                    ->label('اسم الشركة أو العميل')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('contact_name')
                                    ->label('مسؤول التواصل')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('الهاتف')
                                    ->tel()
                                    ->maxLength(40),
                            ])
                            ->createOptionUsing(fn (array $data) => FactoryCustomer::query()->create($data)->getKey())
                            ->helperText('عملاء المصنع منفصلون عن مستخدمي النظام.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('title')
                            ->label('عنوان العرض')
                            ->placeholder('قائمة أسعار الملحقات (السوبر)')
                            ->required()
                            ->maxLength(255)
                            ->default('قائمة أسعار'),
                        Forms\Components\TextInput::make('subtitle')
                            ->label('السطر الثاني')
                            ->placeholder('حسب النظام الأوروبي')
                            ->maxLength(255),
                    ])
                    ->columnSpan(8),
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\DatePicker::make('issued_on')
                            ->label('التاريخ')
                            ->default(now())
                            ->required()
                            ->native(false),
                        Forms\Components\DatePicker::make('valid_until')
                            ->label('صالح حتى')
                            ->native(false),
                        Forms\Components\Select::make('status')
                            ->label('الحالة')
                            ->options([
                                'draft' => 'مسودة',
                                'final' => 'نهائي',
                            ])
                            ->default('draft')
                            ->native(false)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظة')
                            ->rows(2)
                            ->placeholder('تظهر أسفل الجدول'),
                    ])
                    ->columnSpan(4),
            ]),

            Forms\Components\Section::make('الأصناف')
                ->description('اختَر نطاق العرض: كل المنتجات، أو تصنيف كامل، أو قسم واحد، أو جزء من المنتجات. الطباعة تطلع مقسّمة.')
                ->schema([
                    Forms\Components\View::make('filament.forms.components.price-list-sheet')
                        ->viewData(function ($livewire): array {
                            return [
                                'quoteLines' => $livewire->quoteLines,
                                'parents' => $livewire->quoteParents(),
                                'children' => $livewire->quoteChildren(),
                                'quoteIsNew' => $livewire->quoteIsNew,
                                'quoteScopeParentId' => $livewire->quoteScopeParentId,
                                'quoteScopeCategoryId' => $livewire->quoteScopeCategoryId,
                            ];
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('الرقم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('العميل')
                    ->formatStateUsing(fn (PriceList $record) => $record->customerLabel() ?: '—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->counts('items')
                    ->label('أصناف')
                    ->badge(),
                Tables\Columns\TextColumn::make('issued_on')
                    ->label('التاريخ')
                    ->date('Y/m/d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'final' ? 'نهائي' : 'مسودة')
                    ->color(fn (string $state) => $state === 'final' ? 'success' : 'warning'),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->url(fn (PriceList $record) => route('admin.price-lists.print', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()->label('تعديل'),
            ])
            ->emptyStateHeading('لا توجد عروض أسعار')
            ->emptyStateDescription('ابحث عن عميل المصنع، أضف المنتجات، ثم اطبع أو أرسل الرابط.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'edit' => Pages\EditPriceList::route('/{record}/edit'),
        ];
    }
}
