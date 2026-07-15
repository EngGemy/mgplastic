<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasStoreLocationForm;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Models\SystemLabel;
use App\Models\Country;
use App\Models\City;
use App\Support\AdminPermissions;
use App\Support\UserRoles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    use HasStoreLocationForm;

    protected static ?string $model = User::class;
    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string { return 'المستخدمون'; }
    public static function getNavigationLabel(): string { return SystemLabel::get('users', 'المستخدمون'); }
    public static function getModelLabel(): string { return SystemLabel::get('users', 'مستخدم'); }
    public static function getPluralModelLabel(): string { return SystemLabel::get('users', 'المستخدمون'); }

    /** Eager-load for table speed */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['country:id,name_en,name_ar', 'city:id,country_id,name_en,name_ar']);

        $user = auth()->user();

        if ($user && in_array($user->role, ['super_admin', 'admin'], true)) {
            return $query;
        }

        return $query->whereRaw('0 = 1');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_VIEW);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAdminPermission(AdminPermissions::USERS_MANAGE) ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! auth()->user()?->canAdminPermission(AdminPermissions::USERS_MANAGE)) {
            return false;
        }

        if (in_array($record->role, ['super_admin', 'admin'], true)) {
            return auth()->user()?->isSuperAdmin() ?? false;
        }

        return true;
    }

    public static function canDelete($record): bool
    {
        if (! auth()->user()?->canAdminPermission(AdminPermissions::USERS_DELETE)) {
            return false;
        }

        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, string> */
    protected static function assignableRoleOptions(): array
    {
        $all = UserRoles::selectOptions();

        if (auth()->user()?->isSuperAdmin()) {
            return $all;
        }

        return collect($all)->except(['super_admin', 'admin'])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Profile'))
                ->columns(3)
                ->schema([
                    Forms\Components\FileUpload::make('profile_photo')
                        ->label(__('Photo'))
                        ->disk('public')
                        ->directory('profile_photos')
                        ->image()
                        ->imageEditor()
                        ->openable()
                        ->downloadable()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label(__('Phone'))
                        ->tel()
                        ->required()
                        ->maxLength(50),

                    Forms\Components\Select::make('role')
                        ->label('الدور الوظيفي')
                        ->options(fn () => static::assignableRoleOptions())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->live(),

                    Forms\Components\CheckboxList::make('permissions')
                        ->label('صلاحيات لوحة التحكم')
                        ->helperText('تُطبَّق على دور «مدير لوحة التحكم» فقط. اتركها فارغة = صلاحيات كاملة.')
                        ->options(AdminPermissions::labels())
                        ->columns(2)
                        ->visible(fn (Get $get) => $get('role') === 'admin')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('parent_distributor_id')
                        ->label('المسؤول المباشر')
                        ->helperText('السباك: اختر تاجر القطاعي | تاجر القطاعي: اختر موزع الجملة')
                        ->options(fn (Get $get) => match ($get('role')) {
                            'retail_trader' => User::where('role', 'wholesale_distributor')->pluck('name', 'id'),
                            'plumber' => User::where('role', 'retail_trader')->pluck('name', 'id'),
                            default => [],
                        })
                        ->nullable()
                        ->searchable()
                        ->visible(fn (Get $get) => in_array($get('role'), ['retail_trader', 'plumber'])),

                    Forms\Components\Select::make('country_id')
                        ->label(__('Country'))
                        ->options(fn () =>
                        Country::query()
                            ->orderBy('name_en')
                            ->get()
                            ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->afterStateUpdated(fn (Set $set) => $set('city_id', null))
                        ->required(),

                    Forms\Components\Select::make('city_id')
                        ->label(__('City'))
                        ->options(fn (Get $get) =>
                        City::query()
                            ->when($get('country_id'), fn ($q, $cid) => $q->where('country_id', $cid))
                            ->orderBy('name_en')
                            ->get()
                            ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Textarea::make('about_me')
                        ->label(__('About me'))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('short_description')
                        ->label(__('Short description')),

                    Forms\Components\Textarea::make('long_description')
                        ->label(__('Long description'))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('video_url')
                        ->label(__('Video URL'))
                        ->url()
                        ->maxLength(2048)
                        ->columnSpanFull(),
                ]),

            ...self::brandCompanyFields(),

            Forms\Components\Section::make('معرض أعمال السباك')
                ->description('الصور والفيديوهات التي يرفعها السباك من التطبيق — يُنشأ للفيديو غلاف تلقائي.')
                ->icon('heroicon-o-photo')
                ->collapsible()
                ->visible(fn ($record) => $record?->role === User::ROLE_PLUMBER)
                ->schema([
                    Forms\Components\ViewField::make('_work_gallery')
                        ->view('filament.forms.plumber-work-gallery')
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('Security & Status'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn ($record) => $record === null)
                        ->maxLength(255),

                    Forms\Components\Toggle::make('is_phone_verified')->label(__('Phone Verified')),
                    Forms\Components\Toggle::make('is_approved')->label(__('Approved')),
                    Forms\Components\Toggle::make('is_active')->label(__('Active')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo')
                    ->label(__('Photo'))
                    ->disk('public')
                    ->circular()
                    ->size(36),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('الدور')
                    ->badge()
                    ->color(fn ($state) => UserRoles::color($state))
                    ->formatStateUsing(fn ($state) => UserRoles::label($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('parentDistributor.name')
                    ->label('تابع لـ')
                    ->placeholder('—')
                    ->toggleable(),

                // Locale-aware display, still sorts consistently by EN
                Tables\Columns\TextColumn::make('country.name_en')
                    ->label(__('Country'))
                    ->formatStateUsing(fn ($state, $record) =>
                    app()->getLocale() === 'ar'
                        ? ($record?->country?->name_ar ?? $state)
                        : $state
                    )
                    ->toggleable(),

                Tables\Columns\TextColumn::make('city.name_en')
                    ->label(__('City'))
                    ->formatStateUsing(fn ($state, $record) =>
                    app()->getLocale() === 'ar'
                        ? ($record?->city?->name_ar ?? $state)
                        : $state
                    )
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_phone_verified')
                    ->label(__('Phone'))
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label(__('Approved'))
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('الدور')
                    ->options(UserRoles::selectOptions())
                    ->multiple(),

                Tables\Filters\TernaryFilter::make('is_phone_verified')->label(__('Phone Verified')),
                Tables\Filters\TernaryFilter::make('is_approved')->label(__('Approved')),
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Active')),

                Tables\Filters\SelectFilter::make('country_id')
                    ->label(__('Country'))
                    ->options(fn () =>
                    Country::query()
                        ->orderBy('name_en')
                        ->get()
                        ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                        ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn ($record) => ! $record?->is_approved)
                    ->requiresConfirmation()
                    ->action(fn ($record) =>
                    $record->forceFill(['is_approved' => true, 'approved_at' => now()])->save()
                    ),

                Tables\Actions\Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn ($record) => (bool) $record?->is_active)
                    ->requiresConfirmation()
                    ->action(fn ($record) =>
                    $record->forceFill(['is_active' => false, 'deactivated_at' => now()])->save()
                    ),

                Tables\Actions\Action::make('activate')
                    ->label(__('Activate'))
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->visible(fn ($record) => ! (bool) $record?->is_active)
                    ->requiresConfirmation()
                    ->action(fn ($record) =>
                    $record->forceFill(['is_active' => true, 'deactivated_at' => null])->save()
                    ),

                \App\Filament\Support\UserNotificationActions::tableAction(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulkApprove')
                        ->label(__('Approve selected'))
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) =>
                        $records->each->forceFill(['is_approved' => true, 'approved_at' => now()])->each->save()
                        ),
                    Tables\Actions\BulkAction::make('bulkDeactivate')
                        ->label(__('Deactivate selected'))
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->action(fn ($records) =>
                        $records->each->forceFill(['is_active' => false, 'deactivated_at' => now()])->each->save()
                        ),
                    Tables\Actions\BulkAction::make('bulkActivate')
                        ->label(__('Activate selected'))
                        ->icon('heroicon-o-bolt')
                        ->color('success')
                        ->action(fn ($records) =>
                        $records->each->forceFill(['is_active' => true, 'deactivated_at' => null])->each->save()
                        ),
                    \App\Filament\Support\UserNotificationActions::bulkAction(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        // UserResource::getRelations()
        return [
            \App\Filament\Resources\UserResource\RelationManagers\PlumberWorkPhotosRelationManager::class,
        ];

    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
