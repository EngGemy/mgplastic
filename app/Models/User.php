<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\HasStoreProfile;
use App\Models\Concerns\HasWallet;
use App\Support\StorePermissions;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;



class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasApiTokens, HasFactory, HasStoreProfile, Notifiable, HasWallet;
    public const ROLE_PLUMBER = 'plumber';
    public const ROLE_VENDOR  = 'vendor';

    protected $appends = ['profile_photo_url'];

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'country_id',
        'city_id',
        'role',
        'permissions',
        'network_code',
        'parent_distributor_id',
        'is_independent',
        'is_central_warehouse',
        'profile_photo',
        'brand_logo',
        'brand_name',
        'about_me',
        'short_description',
        'long_description',
        'video_url',
        'website',
        'show_social_links',
        'password',
        'otp_code',
        'address',
        'latitude',
        'longitude',
        'store_description',
        'otp_expires_at',
        'is_phone_verified',
        'is_approved','approved_at','is_active','deactivated_at',
        'marsol_otp_request_id',
        'marsol_otp_resend_token',
        'marsol_otp_expires_at',
        'otp_last_sent_at',
        'otp_attempts',

    ];

    /**
     * Attributes hidden from serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute casting.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'is_phone_verified' => 'boolean',
            'is_independent' => 'boolean',
            'is_central_warehouse' => 'boolean',
            'show_social_links' => 'boolean',
            'permissions' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'password' => 'hashed',
            'marsol_otp_expires_at' => 'datetime',
            'otp_last_sent_at'      => 'datetime',
        ];
    }

    /**
     * Country relationship.
     */
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * City relationship.
     */
    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Work photos relationship.
     */
    public function workPhotos()
    {
        return $this->hasMany(PlumberWorkPhoto::class, 'plumber_id');
    }

    /**
     * Plumber stores relationship.
     */
  


    public function vendorStores()
    {
        return $this->hasMany(PlumberStore::class, 'vendor_id');
    }

    /** Orders this user placed as a buyer. */
    public function ordersPlaced(): HasMany
    {
        return $this->hasMany(Order::class, 'requester_id');
    }

    /** Orders this user must fulfil as a supplier. */
    public function ordersReceived(): HasMany
    {
        return $this->hasMany(Order::class, 'supplier_id');
    }
    /**
     * Accessor: full absolute URL for profile photo.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (! $this->profile_photo) {
            return null;
        }

        return Storage::disk('public')->url($this->profile_photo);
    }

    /** Relative storage path for the store / brand image shown in panels. */
    public function storeBrandImagePath(): ?string
    {
        return filled($this->brand_logo)
            ? $this->brand_logo
            : (filled($this->profile_photo) ? $this->profile_photo : null);
    }

    public function storeBrandImageUrl(): ?string
    {
        $path = $this->storeBrandImagePath();

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->storeBrandImageUrl();
    }

    /**
     * Resolve a user for login by email, phone, numeric id, or network_code.
     */
    public static function findByLoginIdentifier(string $identifier): ?self
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            $byId = static::query()->whereKey((int) $identifier)->first();
            if ($byId) {
                return $byId;
            }
        }

        $byCode = static::query()
            ->whereNotNull('network_code')
            ->where('network_code', $identifier)
            ->first();
        if ($byCode) {
            return $byCode;
        }

        if (str_contains($identifier, '@')) {
            return static::query()->where('email', $identifier)->first();
        }

        $variants = static::phoneLookupVariants($identifier);
        if ($variants === []) {
            return null;
        }

        return static::query()->whereIn('phone', $variants)->first();
    }

    /**
     * Local and international forms of one Libyan mobile, so login and
     * forgot-password find the same account.
     *
     * @return list<string>
     */
    public static function phoneLookupVariants(string $phone): array
    {
        $raw = trim($phone);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $set = [];

        foreach ([$raw, $digits, ltrim($raw, '+')] as $value) {
            if ($value !== '') {
                $set[$value] = $value;
            }
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $set[$digits] = $digits;
        }

        if (preg_match('/^218(9[1-5]\d{7})$/', $digits, $match)) {
            $local = $match[1];
        } elseif (preg_match('/^0(9[1-5]\d{7})$/', $digits, $match)) {
            $local = $match[1];
        } elseif (preg_match('/^(9[1-5]\d{7})$/', $digits, $match)) {
            $local = $match[1];
        } else {
            $local = null;
        }

        if ($local !== null) {
            foreach (['0'.$local, $local, '218'.$local, '+218'.$local] as $value) {
                $set[$value] = $value;
            }
        }

        return array_values($set);
    }

    public static function findByPhone(string $phone): ?self
    {
        $variants = static::phoneLookupVariants($phone);
        if ($variants === []) {
            return null;
        }

        return static::query()->whereIn('phone', $variants)->first();
    }

    public function parentDistributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_distributor_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(User::class, 'parent_distributor_id');
    }

    public function retailTraders(): HasMany
    {
        return $this->hasMany(User::class, 'parent_distributor_id')
            ->where('role', 'retail_trader');
    }

    /** Many-to-many: wholesaler ↔ retail (supports multiple wholesalers). */
    public function linkedRetailTraders(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'wholesaler_retail_trader',
            'wholesaler_id',
            'retail_trader_id'
        )->withPivot(['linked_by', 'linked_at'])->withTimestamps();
    }

    public function linkedWholesalers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'wholesaler_retail_trader',
            'retail_trader_id',
            'wholesaler_id'
        )->withPivot(['linked_by', 'linked_at'])->withTimestamps();
    }

    /**
     * Retail traders that belong to this wholesaler, either as the primary
     * parent or through the network pivot. The pivot check must not use
     * users.id inside whereHas — that column collides with the outer users table
     * and hides linked traders (the profile then 404s).
     */
    public function scopeVisibleToWholesaler(Builder $query, int $wholesalerId): Builder
    {
        return $query->where(function (Builder $inner) use ($wholesalerId) {
            $inner->where('users.parent_distributor_id', $wholesalerId)
                ->orWhereIn('users.id', function ($sub) use ($wholesalerId) {
                    $sub->select('retail_trader_id')
                        ->from('wholesaler_retail_trader')
                        ->where('wholesaler_id', $wholesalerId);
                });
        });
    }

    public function isLinkedToWholesaler(User $wholesaler): bool
    {
        return app(\App\Services\RetailNetworkLinkService::class)->isLinked($wholesaler, $this);
    }

    public function plumbers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_distributor_id')
            ->where('role', self::ROLE_PLUMBER);
    }

    public function hasMapLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function mapUrl(): ?string
    {
        if (! $this->hasMapLocation()) {
            return null;
        }

        return 'https://www.openstreetmap.org/?mlat='.$this->latitude.'&mlon='.$this->longitude.'#map=16/'.$this->latitude.'/'.$this->longitude;
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(InvoiceDistribution::class, 'from_user_id');
    }

    public function receivedDistributions(): HasMany
    {
        return $this->hasMany(InvoiceDistribution::class, 'to_user_id');
    }

    public function wholesaleInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'wholesale_distributor_id');
    }

    public function walletAccounts(): HasMany
    {
        return $this->hasMany(WalletAccount::class, 'owner_id');
    }

    // --- Helpers (optional) ---
    public function isVendor(): bool  { return $this->role === self::ROLE_VENDOR; }
    public function isPlumber(): bool { return $this->role === self::ROLE_PLUMBER; }

    public function isWholesaleDistributor(): bool
    {
        return $this->role === 'wholesale_distributor';
    }

    /** مخزن الدعوة — wholesale node with dedicated /warehouse panel */
    public function isCentralWarehouse(): bool
    {
        return $this->isWholesaleDistributor() && (bool) $this->is_central_warehouse;
    }

    public function isRetailTrader(): bool
    {
        return $this->role === 'retail_trader';
    }

    public function isNetworkStore(): bool
    {
        return $this->isWholesaleDistributor() || $this->isRetailTrader();
    }

    /** @return array{code: string, message: ?string, is_public: bool} */
    public function networkStoreStatus(): array
    {
        if (! $this->is_approved) {
            return [
                'code' => 'pending_approval',
                'message' => 'متجرك لم يُفعَّل بعد — طلبك قيد مراجعة الإدارة. يمكنك تجهيز بيانات المتجر الآن وسيظهر للجميع بعد الموافقة.',
                'is_public' => false,
            ];
        }

        if (! $this->is_active) {
            return [
                'code' => 'inactive',
                'message' => 'تم إيقاف نشاط المتجر. تواصل مع الإدارة لإعادة التفعيل.',
                'is_public' => false,
            ];
        }

        return [
            'code' => 'active',
            'message' => null,
            'is_public' => true,
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdminUser(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isAdminUser(),
            'warehouse' => $this->isCentralWarehouse()
                && $this->canStorePermission(StorePermissions::PANEL_ACCESS),
            'distributor' => $this->isWholesaleDistributor()
                && ! $this->isCentralWarehouse()
                && $this->canStorePermission(StorePermissions::PANEL_ACCESS),
            'trader' => $this->isRetailTrader()
                && $this->canStorePermission(StorePermissions::PANEL_ACCESS),
            default => false,
        };
    }

    public function canAdminPermission(string $permission): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }

        if ($this->role !== 'admin') {
            return false;
        }

        $permissions = $this->permissions;

        if ($permissions === null || $permissions === []) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public function canStorePermission(string $permission): bool
    {
        if (! $this->isNetworkStore()) {
            return false;
        }

        $permissions = $this->permissions;

        if ($permissions === null || $permissions === []) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public function isApprovedAndActive(): bool { return $this->is_approved && $this->is_active; }

    // Scopes
    public function scopeApproved($q) { return $q->where('is_approved', true); }
    public function scopeActive($q)   { return $q->where('is_active', true); }
    public function plumberWorkPhotos()
    {
        return $this->hasMany(\App\Models\PlumberWorkPhoto::class, 'plumber_id');
    }

}
