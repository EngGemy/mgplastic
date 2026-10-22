<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\DatabaseNotificationResource\Pages;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Support\UserRoles;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DatabaseNotificationResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = DatabaseNotification::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'الإشعارات';
    }

    public static function getNavigationLabel(): string
    {
        return 'سجل الإشعارات';
    }

    public static function getModelLabel(): string
    {
        return 'إشعار';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل الإشعارات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['notifiable'])
            ->latest('created_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notifiable_id')
                    ->label('المستلم')
                    ->getStateUsing(fn (DatabaseNotification $record) => $record->notifiableName())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHasMorph('notifiable', [User::class], function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('data.title')
                    ->label('العنوان')
                    ->getStateUsing(fn (DatabaseNotification $record) => $record->title())
                    ->weight('bold')
                    ->limit(36)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('data->title', 'like', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('data.body')
                    ->label('المحتوى')
                    ->getStateUsing(fn (DatabaseNotification $record) => $record->body())
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('data.status')
                    ->label('النوع')
                    ->badge()
                    ->getStateUsing(fn (DatabaseNotification $record) => match ($record->status()) {
                        'success' => 'نجاح',
                        'warning' => 'تحذير',
                        'danger' => 'تنبيه',
                        default => 'معلومة',
                    })
                    ->color(fn (DatabaseNotification $record) => match ($record->status()) {
                        'success' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('data.entity_type')
                    ->label('الكيان')
                    ->getStateUsing(fn (DatabaseNotification $record) => $record->entityType() ?: '—')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('read_at')
                    ->label('مقروء')
                    ->boolean()
                    ->getStateUsing(fn (DatabaseNotification $record) => $record->read_at !== null),
                Tables\Columns\TextColumn::make('notifiable.role')
                    ->label('الدور')
                    ->formatStateUsing(fn ($state) => UserRoles::label($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('notifiable_role')
                    ->label('دور المستلم')
                    ->options(UserRoles::selectOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $role = $data['value'] ?? null;
                        if (! $role) {
                            return $query;
                        }

                        return $query->whereHasMorph('notifiable', [User::class], fn (Builder $q) => $q->where('role', $role));
                    }),
                Tables\Filters\TernaryFilter::make('read')
                    ->label('مقروء')
                    ->nullable()
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('read_at'),
                        false: fn (Builder $q) => $q->whereNull('read_at'),
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\Filter::make('entity')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('entity_type')->label('نوع الكيان')->placeholder('order'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! filled($data['entity_type'] ?? null)) {
                            return $query;
                        }

                        return $query->where(function (Builder $q) use ($data) {
                            $q->where('data->entity_type', $data['entity_type'])
                                ->orWhere('data->type', $data['entity_type']);
                        });
                    }),
                Tables\Filters\Filter::make('created')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('من'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('تفاصيل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('الإشعار')
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('المعرّف'),
                    Infolists\Components\TextEntry::make('created_at')->label('أُنشئ')->dateTime('Y-m-d H:i:s'),
                    Infolists\Components\TextEntry::make('read_at')->label('قُرئ')->dateTime('Y-m-d H:i:s')->placeholder('غير مقروء'),
                    Infolists\Components\TextEntry::make('recipient')
                        ->label('المستلم')
                        ->getStateUsing(fn (DatabaseNotification $record) => $record->notifiableName()),
                    Infolists\Components\TextEntry::make('title')
                        ->label('العنوان')
                        ->getStateUsing(fn (DatabaseNotification $record) => $record->title()),
                    Infolists\Components\TextEntry::make('body')
                        ->label('المحتوى')
                        ->getStateUsing(fn (DatabaseNotification $record) => $record->body() ?: '—')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('status')
                        ->label('الحالة')
                        ->getStateUsing(fn (DatabaseNotification $record) => $record->status()),
                    Infolists\Components\TextEntry::make('type')->label('صنف النظام')->limit(60),
                ])
                ->columns(2),
            Infolists\Components\Section::make('البيانات الكاملة')
                ->schema([
                    Infolists\Components\TextEntry::make('data')
                        ->label('JSON')
                        ->formatStateUsing(function ($state) {
                            if (is_string($state)) {
                                return $state;
                            }

                            return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '—';
                        })
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDatabaseNotifications::route('/'),
            'view' => Pages\ViewDatabaseNotification::route('/{record}'),
        ];
    }
}
