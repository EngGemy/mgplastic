<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ConversionRuleResource\Pages;
use App\Models\ConversionRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConversionRuleResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ConversionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات صرف النقاط';
    }

    public static function getPluralModelLabel(): string
    {
        return 'إعدادات صرف النقاط';
    }

    public static function getModelLabel(): string
    {
        return 'إعدادات الصرف';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('قيمة النقاط — على مستوى المنتج')
                ->description('قيمة تحويل النقاط (نسبة أو ثابت) تُحدَّد لكل منتج على حدة من صفحة المنتجات — وليس من هنا.')
                ->icon('heroicon-o-cube')
                ->schema([
                    Forms\Components\Placeholder::make('product_conversion_hint')
                        ->content('اذهب إلى المنتجات ← إضافة/تعديل منتج ← خطوة «النقاط» لتحديد نقاط/وحدة ونوع التحويل (نسبة من سعر الوحدة أو قيمة ثابتة لكل نقطة).')
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('open_products_report')
                            ->label('تقرير المنتجات والنقاط')
                            ->icon('heroicon-o-presentation-chart-bar')
                            ->color('info')
                            ->url(fn () => \App\Filament\Pages\ProductsReport::getUrl()),

                        Forms\Components\Actions\Action::make('add_product')
                            ->label('إضافة منتج')
                            ->icon('heroicon-o-plus')
                            ->color('success')
                            ->url(fn () => ProductResource::getUrl('create')),
                    ])->columnSpanFull(),
                ]),

            Forms\Components\Section::make('فترة السماح بالصرف')
                ->description('حدّد متى يمكن للسباكين تحويل نقاطهم إلى رصيد مالي (اترك فارغاً = مفتوح دائماً)')
                ->icon('heroicon-o-calendar-days')
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('تفعيل الصرف')
                        ->default(true)
                        ->helperText('إيقاف هذا الخيار يمنع جميع عمليات التحويل مؤقتاً'),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('بداية فترة الصرف')
                            ->timezone('Africa/Tripoli')
                            ->seconds(false)
                            ->nullable()
                            ->helperText('اتركه فارغاً = بدون قيد بداية'),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('نهاية فترة الصرف')
                            ->timezone('Africa/Tripoli')
                            ->seconds(false)
                            ->nullable()
                            ->after('starts_at')
                            ->helperText('اتركه فارغاً = بدون قيد نهاية'),
                    ]),
                ]),

            Forms\Components\Section::make('حدود الصرف')
                ->icon('heroicon-o-adjustments-horizontal')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('min_redeem_points')
                            ->label('الحد الأدنى للنقاط')
                            ->numeric()
                            ->minValue(1)
                            ->default(100)
                            ->required()
                            ->suffix('نقطة')
                            ->helperText('أقل عدد نقاط يمكن تحويله دفعة واحدة'),

                        Forms\Components\TextInput::make('max_redeem_points')
                            ->label('الحد الأقصى للنقاط')
                            ->numeric()
                            ->minValue(1)
                            ->nullable()
                            ->suffix('نقطة')
                            ->helperText('اتركه فارغاً = بدون حد أقصى'),
                    ]),

                    Forms\Components\Hidden::make('currency')->default('LYD'),
                ]),

            Forms\Components\Section::make('الإشعارات')
                ->description('عند تحويل السباك لنقاطه، يصله إشعار بالعملية')
                ->icon('heroicon-o-bell')
                ->schema([
                    Forms\Components\Toggle::make('notify_on_conversion')
                        ->label('إرسال إشعار عند التحويل')
                        ->default(true)
                        ->live(),

                    Forms\Components\Textarea::make('notification_message_ar')
                        ->label('نص الإشعار (عربي)')
                        ->rows(3)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->default('تم تحويل نقاطك بنجاح — يمكنك طلب السحب من المحفظة.')
                        ->visible(fn (Forms\Get $get) => (bool) $get('notify_on_conversion'))
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('رسوم الصرف (اختياري)')
                ->icon('heroicon-o-banknotes')
                ->collapsed()
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('fee_percent')
                            ->label('رسوم نسبة')
                            ->numeric()
                            ->step(0.001)
                            ->default(0)
                            ->suffix('%'),

                        Forms\Components\TextInput::make('fee_fixed_dinars')
                            ->label('رسوم ثابتة')
                            ->numeric()
                            ->step(0.01)
                            ->default(0)
                            ->suffix('د.ل')
                            ->dehydrated(false)
                            ->afterStateHydrated(function ($component, $state, ?ConversionRule $record) {
                                if ($state !== null && $state !== '') {
                                    return;
                                }
                                if ($record?->fee_fixed_cents) {
                                    $component->state(number_format($record->fee_fixed_cents / 100, 2, '.', ''));
                                }
                            }),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->label('مفعّل')
                    ->boolean(),

                Tables\Columns\TextColumn::make('redemption_window')
                    ->label('فترة الصرف')
                    ->state(fn (ConversionRule $r) => $r->redemptionWindowLabel()),

                Tables\Columns\TextColumn::make('min_redeem_points')
                    ->label('حد أدنى')
                    ->suffix(' نقطة'),

                Tables\Columns\TextColumn::make('max_redeem_points')
                    ->label('حد أقصى')
                    ->default('—')
                    ->suffix(' نقطة'),

                Tables\Columns\IconColumn::make('notify_on_conversion')
                    ->label('إشعار')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل الإعدادات'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConversionRules::route('/'),
            'create' => Pages\CreateConversionRule::route('/create'),
            'edit' => Pages\EditConversionRule::route('/{record}/edit'),
        ];
    }
}
