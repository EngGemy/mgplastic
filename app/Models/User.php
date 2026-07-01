<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\HasStoreProfile;
use App\Models\Concerns\HasWallet;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;



class User extends Authenticatable
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
        'parent_distributor_id',
        'is_independent',
        'profile_photo',
        'brand_logo',
        'brand_name',
        'about_me',
        'short_description',
        'long_description',
        'video_url',
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
    /**
     * Accessor: full absolute URL for profile photo.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (! $this->profile_photo) {
            return null;
        }
        // Will produce: https://your-app.com/storage/profile_photos/filename.jpg
        return Storage::disk('public')->url($this->profile_photo);
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

    public function isRetailTrader(): bool
    {
        return $this->role === 'retail_trader';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdminUser(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
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

    public function isApprovedAndActive(): bool { return $this->is_approved && $this->is_active; }

    // Scopes
    public function scopeApproved($q) { return $q->where('is_approved', true); }
    public function scopeActive($q)   { return $q->where('is_active', true); }
    public function plumberWorkPhotos()
    {
        return $this->hasMany(\App\Models\PlumberWorkPhoto::class, 'plumber_id');
    }

}
