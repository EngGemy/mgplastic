<?php

namespace App\Filament\Trader\Resources;

use App\Filament\Trader\Resources\TraderPlumberResource\Pages;
use App\Models\User;
use App\Services\PlumberRetailLinkService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TraderPlumberResource extends Resource
{
    protected static ?string $slug = 'plumbers';

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'السباكون';

    protected static ?string $navigationGroup = 'شبكة المحل';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'سباك';
    }

    public static function getPluralModelLabel(): string
    {
        return 'السباكون';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', User::ROLE_PLUMBER)
            ->where('parent_distributor_id', auth()->id());
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isRetailTrader();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            Forms\Components\TextInput::make('phone')
                ->label('الهاتف')
                ->tel()
                ->required()
                ->maxLength(50)
                ->rule(fn (?User $record) => static::phoneIsFree($record?->id)),
            Forms\Components\TextInput::make('email')->label('البريد')->email()->maxLength(255),
            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->minLength(6),
            Forms\Components\Textarea::make('address')->label('العنوان')->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('phone')->label('الهاتف')->searchable(),
                Tables\Columns\TextColumn::make('network_code')->label('الرقم الموحّد')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('linkExisting')
                    ->label('ربط سباك مسجّل')
                    ->icon('heroicon-o-link')
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label('الرقم الموحّد أو الهاتف')
                            ->placeholder('MG-P-000012')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $result = app(PlumberRetailLinkService::class)->linkByCode(
                                auth()->user(),
                                (string) $data['code'],
                            );

                            Notification::make()
                                ->success()
                                ->title($result['created_link'] ? 'تمت الإضافة لشبكتك' : 'موجود في شبكتك')
                                ->body($result['message'])
                                ->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title('تعذّر الربط')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('لا يوجد سباكون في شبكتك')
            ->emptyStateDescription('أضف سباكاً جديداً، أو اربط سباكاً مسجّلاً برقمه. السباك غير المرتبط ينضم أيضاً عند أول طلب يرسله إليك.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTraderPlumbers::route('/'),
            'create' => Pages\CreateTraderPlumber::route('/create'),
            'edit' => Pages\EditTraderPlumber::route('/{record}/edit'),
        ];
    }

    public static function phoneIsFree(?int $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
            $existing = User::findByPhone((string) $value);

            if ($existing && (int) $existing->id !== (int) $ignoreId) {
                $fail('هذا الهاتف مسجّل. استخدم «ربط سباك مسجّل».');
            }
        };
    }
}
