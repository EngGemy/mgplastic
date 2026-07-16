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

    public static function canView($record): bool
    {
        return static::canViewAny();
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
        return $form
            ->columns(3)
            ->schema([
                // ===== العمود الرئيسي =====
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Forms\Components\Section::make('المعلومات الأساسية')
                            ->description('بيانات التواصل والتعريف الأساسية للمستخدم.')
                            ->icon('heroicon-o-identification')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('الاسم')
                                    ->placeholder('الاسم الكامل / اسم المتجر')
                                    ->prefixIcon('heroicon-o-user')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('phone')
                                    ->label('رقم الهاتف')
                                    ->placeholder('09XXXXXXXX')
                                    ->prefixIcon('heroicon-o-phone')
                                    ->tel()
                                    ->required()
                                    ->maxLength(50),

                                Forms\Components\TextInput::make('email')
                                    ->label('البريد الإلكتروني')
                                    ->placeholder('اختياري')
                                    ->prefixIcon('heroicon-o-envelope')
                                    ->email()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Section::make('صلاحيات لوحة التحكم')
                            ->description('تُطبَّق على دور «مدير لوحة التحكم» فقط. اتركها فارغة = صلاحيات كاملة.')
                            ->icon('heroicon-o-key')
                            ->visible(fn (Get $get) => $get('role') === 'admin')
                            ->schema([
                                Forms\Components\CheckboxList::make('permissions')
                                    ->label('')
                                    ->options(AdminPermissions::labels())
                                    ->columns(2)
                                    ->bulkToggleable()
                                    ->gridDirection('row')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('الموقع الجغرافي')
                            ->description('الدولة والمدينة والعنوان التفصيلي.')
                            ->icon('heroicon-o-map-pin')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('country_id')
                                    ->label('الدولة')
                                    ->options(fn () =>
                                    Country::query()
                                        ->orderBy('name_en')
                                        ->get()
                                        ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                                        ->toArray()
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->reactive()
                                    ->afterStateUpdated(fn (Set $set) => $set('city_id', null))
                                    ->required(),

                                Forms\Components\Select::make('city_id')
                                    ->label('المدينة')
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
                                    ->native(false)
                                    ->required(),

                                Forms\Components\Textarea::make('address')
                                    ->label('العنوان التفصيلي')
                                    ->placeholder('الحي — الشارع — معلم قريب')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('النبذة والوصف')
                            ->description('نصوص تعريفية تظهر في الملف العام والتطبيق.')
                            ->icon('heroicon-o-document-text')
                            ->collapsible()
                            ->schema([
                                Forms\Components\Textarea::make('about_me')
                                    ->label('نبذة تعريفية')
                                    ->rows(2)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('short_description')
                                    ->label('وصف مختصر')
                                    ->placeholder('سطر أو سطرين يظهران في القوائم...')
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('long_description')
                                    ->label('وصف تفصيلي')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ]),

                        ...self::brandCompanyFields(),

                        Forms\Components\Section::make('روابط ووسائط')
                            ->description('الموقع الإلكتروني وفيديو تعريفي.')
                            ->icon('heroicon-o-link')
                            ->collapsible()
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('website')
                                    ->label('الموقع الإلكتروني')
                                    ->placeholder('https://example.com')
                                    ->prefixIcon('heroicon-o-globe-alt')
                                    ->url()
                                    ->maxLength(500),

                                Forms\Components\TextInput::make('video_url')
                                    ->label('رابط فيديو تعريفي')
                                    ->placeholder('https://youtube.com/...')
                                    ->prefixIcon('heroicon-o-play-circle')
                                    ->url()
                                    ->maxLength(2048),
                            ]),

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
                    ]),

                // ===== الشريط الجانبي =====
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('الصورة والدور')
                            ->icon('heroicon-o-user-circle')
                            ->schema([
                                Forms\Components\FileUpload::make('profile_photo')
                                    ->label('الصورة الشخصية')
                                    ->disk('public')
                                    ->directory('profile_photos')
                                    ->image()
                                    ->avatar()
                                    ->imageEditor()
                                    ->circleCropper()
                                    ->alignCenter()
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('role')
                                    ->label('الدور الوظيفي')
                                    ->options(fn () => static::assignableRoleOptions())
                                    ->required()
                                    ->searchable()
                                    ->native(false)
                                    ->live()
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
                                    ->preload()
                                    ->native(false)
                                    ->visible(fn (Get $get) => in_array($get('role'), ['retail_trader', 'plumber']))
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('الأمان والحالة')
                            ->icon('heroicon-o-lock-closed')
                            ->schema([
                                Forms\Components\TextInput::make('password')
                                    ->label('كلمة المرور')
                                    ->password()
                                    ->revealable()
                                    ->placeholder(fn ($record) => $record ? 'اتركها فارغة لعدم التغيير' : '6 أحرف على الأقل')
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->required(fn ($record) => $record === null)
                                    ->minLength(6)
                                    ->maxLength(255),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('نشط')
                                    ->helperText('حساب فعّال ويمكنه تسجيل الدخول.')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\Toggle::make('is_approved')
                                    ->label('معتمد')
                                    ->helperText('تمت الموافقة عليه ويظهر في الشبكة.')
                                    ->inline(false),

                                Forms\Components\Toggle::make('is_phone_verified')
                                    ->label('الهاتف موثّق')
                                    ->inline(false),

                                Forms\Components\Toggle::make('show_social_links')
                                    ->label('إظهار روابط التواصل')
                                    ->helperText('عند الإيقاف تُخفى حسابات السوشيال ميديا.')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn ($record) => 'mg-user-row mg-user-row--'.UserRoles::group($record->role))
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo')
                    ->label('الصورة')
                    ->disk('public')
                    ->circular()
                    ->size(44)
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name ?? 'U').'&background=0D8ABC&color=fff&size=88'),

                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record) => UserRoles::label($record->role)),

                Tables\Columns\TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->icon('heroicon-m-phone')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('الدور')
                    ->badge()
                    ->icon(fn ($state) => UserRoles::icon($state))
                    ->color(fn ($state) => UserRoles::color($state))
                    ->formatStateUsing(fn ($state) => UserRoles::label($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_points')
                    ->label('النقاط')
                    ->getStateUsing(function ($record) {
                        if ($record->role !== 'plumber') {
                            return null;
                        }

                        return (int) (\App\Models\WalletAccount::query()
                            ->where('owner_id', $record->id)
                            ->where('currency', 'LYD')
                            ->value('balance_points') ?? 0);
                    })
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((int) $state).' نقطة')
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'gray' : ((int) $state > 0 ? 'warning' : 'gray'))
                    ->icon('heroicon-m-star')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('parentDistributor.name')
                    ->label('تابع لـ')
                    ->placeholder('—')
                    ->toggleable(),

                // Locale-aware display, still sorts consistently by EN
                Tables\Columns\TextColumn::make('country.name_en')
                    ->label('الدولة')
                    ->formatStateUsing(fn ($state, $record) =>
                    app()->getLocale() === 'ar'
                        ? ($record?->country?->name_ar ?? $state)
                        : $state
                    )
                    ->toggleable(),

                Tables\Columns\TextColumn::make('city.name_en')
                    ->label('المدينة')
                    ->formatStateUsing(fn ($state, $record) =>
                    app()->getLocale() === 'ar'
                        ? ($record?->city?->name_ar ?? $state)
                        : $state
                    )
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_phone_verified')
                    ->label('موثّق')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label('معتمد')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('الدور')
                    ->options(UserRoles::selectOptions())
                    ->multiple(),

                Tables\Filters\TernaryFilter::make('is_phone_verified')->label('موثّق الهاتف'),
                Tables\Filters\TernaryFilter::make('is_approved')->label('معتمد'),
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),

                Tables\Filters\SelectFilter::make('country_id')
                    ->label('الدولة')
                    ->options(fn () =>
                    Country::query()
                        ->orderBy('name_en')
                        ->get()
                        ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                        ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('عرض')
                    ->icon('heroicon-o-eye'),

                Tables\Actions\Action::make('approve')
                    ->label(fn ($record) => $record?->role === 'wholesale_distributor' ? 'تفعيل المتجر' : 'اعتماد')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn ($record) => ! $record?->is_approved)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        if ($record->role === 'wholesale_distributor') {
                            app(\App\Services\StoreApprovalService::class)->approve($record, auth()->user());
                        } else {
                            $record->forceFill(['is_approved' => true, 'approved_at' => now()])->save();
                        }
                    }),

                Tables\Actions\Action::make('deactivate')
                    ->label('إيقاف')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn ($record) => (bool) $record?->is_active)
                    ->requiresConfirmation()
                    ->action(fn ($record) =>
                    $record->forceFill(['is_active' => false, 'deactivated_at' => now()])->save()
                    ),

                Tables\Actions\Action::make('activate')
                    ->label('تفعيل')
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->visible(fn ($record) => ! (bool) $record?->is_active)
                    ->requiresConfirmation()
                    ->action(fn ($record) =>
                    $record->forceFill(['is_active' => true, 'deactivated_at' => null])->save()
                    ),

                \App\Filament\Support\UserNotificationActions::tableAction(),

                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulkApprove')
                        ->label('اعتماد المحدد')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) =>
                        $records->each->forceFill(['is_approved' => true, 'approved_at' => now()])->each->save()
                        ),
                    Tables\Actions\BulkAction::make('bulkDeactivate')
                        ->label('إيقاف المحدد')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->action(fn ($records) =>
                        $records->each->forceFill(['is_active' => false, 'deactivated_at' => now()])->each->save()
                        ),
                    Tables\Actions\BulkAction::make('bulkActivate')
                        ->label('تفعيل المحدد')
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
        return [
            \App\Filament\Resources\UserResource\RelationManagers\PlumberWorkPhotosRelationManager::class,
            \App\Filament\RelationManagers\SocialLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view'   => Pages\ViewUser::route('/{record}'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
