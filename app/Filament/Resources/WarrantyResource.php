<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\WarrantyResource\Pages;
use App\Models\Warranty;
use App\Services\Warranty\WarrantyService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class WarrantyResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Warranty::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 43;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'الضمانات';
    }

    public static function getModelLabel(): string
    {
        return 'ضمان';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات الضمان')
                ->icon('heroicon-o-shield-check')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('الكود')
                        ->disabled()
                        ->columnSpan(4),
                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'active' => 'ساري',
                            'expired' => 'منتهٍ',
                            'revoked' => 'ملغى',
                            'claimed' => 'مطالبة',
                        ])
                        ->disabled()
                        ->columnSpan(4),
                    Forms\Components\Placeholder::make('plumber')
                        ->label('السباك')
                        ->content(fn (?Warranty $record) => $record?->plumber?->name ?? '—')
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('customer_name')
                        ->label('العميل')
                        ->disabled()
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('customer_phone')
                        ->label('هاتف العميل')
                        ->disabled()
                        ->columnSpan(6),
                    Forms\Components\Textarea::make('revoked_reason')
                        ->label('سبب الإلغاء')
                        ->visible(fn (?Warranty $record) => $record?->status === 'revoked')
                        ->disabled()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('الكود')->searchable()->copyable()->weight('bold'),
                Tables\Columns\TextColumn::make('plumber.name')->label('السباك')->searchable(),
                Tables\Columns\TextColumn::make('customer_name')->label('العميل')->searchable(),
                Tables\Columns\TextColumn::make('customer_phone')->label('هاتف')->searchable()->formatStateUsing(fn ($state, Warranty $r) => $r->maskedPhone()),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'expired' => 'منتهٍ',
                        'revoked' => 'ملغى',
                        'claimed' => 'مطالبة',
                        default => 'ساري',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'active' => 'success',
                        'expired' => 'gray',
                        'revoked' => 'danger',
                        'claimed' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('issued_at')->label('الإصدار')->date(),
                Tables\Columns\TextColumn::make('expires_at')->label('الانتهاء')->date(),
            ])
            ->emptyStateHeading('لا ضمانات صادرة بعد')
            ->emptyStateDescription('الضمانات تظهر هنا تلقائياً عندما يصدر سباك مؤهّل ضماناً من التطبيق. اضبط العتبات من استوديو السمعة والضمان.')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateActions([
                Tables\Actions\Action::make('policy')
                    ->label('سياسة الإصدار')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn () => \App\Filament\Pages\ReputationWarrantySettings::getUrl()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'active' => 'ساري', 'expired' => 'منتهٍ', 'revoked' => 'ملغى', 'claimed' => 'مطالبة',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->url(fn (Warranty $r) => $r->pdfUrl(), true)
                    ->visible(fn (Warranty $r) => filled($r->pdf_path)),
                Tables\Actions\Action::make('resend')
                    ->label('إعادة واتساب')
                    ->requiresConfirmation()
                    ->action(function (Warranty $r) {
                        app(WarrantyService::class)->resend($r);
                    }),
                Tables\Actions\Action::make('revoke')
                    ->label('إلغاء')
                    ->color('danger')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('السبب')->required(),
                    ])
                    ->action(function (Warranty $r, array $data) {
                        $r->update(['status' => 'revoked', 'revoked_reason' => $data['reason']]);
                    })
                    ->visible(fn (Warranty $r) => $r->status === 'active'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('تصدير CSV')
                    ->action(function () {
                        $rows = Warranty::query()->with('plumber')->latest()->limit(2000)->get();
                        $csv = "code,plumber,customer,status,issued_at,expires_at\n";
                        foreach ($rows as $r) {
                            $csv .= implode(',', [
                                $r->code,
                                '"'.str_replace('"', '', (string) $r->plumber?->name).'"',
                                '"'.str_replace('"', '', $r->customer_name).'"',
                                $r->status,
                                optional($r->issued_at)->toDateString(),
                                optional($r->expires_at)->toDateString(),
                            ])."\n";
                        }
                        $path = 'exports/warranties-'.now()->format('YmdHis').'.csv';
                        Storage::disk('public')->put($path, $csv);

                        return redirect(url('storage/'.$path));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarranties::route('/'),
            'view' => Pages\ViewWarranty::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
