<?php

namespace App\Models;

use App\Services\HomeMobileLayoutService;
use App\Support\DeepLink;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class HomePopup extends Model
{
    public const KINDS = ['promo', 'announcement', 'event', 'feature', 'auth'];

    public const FREQUENCIES = ['every_open', 'once', 'once_per_day'];

    public const LINK_TYPES = [
        'home',
        'deep_link',
        'url',
        'plumber',
        'shop',
        'event',
        'product',
        'product_collection',
        'product_promotion',
        'auth_login',
        'auth_register',
        'auth_register_plumber',
        'auth_register_retail',
        'catalog',
        'wallet',
        'points',
        'pending_receipts',
        'support',
    ];

    protected $fillable = [
        'title',
        'subtitle',
        'image_path',
        'kind',
        'cta_text',
        'link_type',
        'link_id',
        'link_path',
        'link_url',
        'target_roles',
        'frequency',
        'dismissible',
        'starts_at',
        'ends_at',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'dismissible' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'link_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected function setImagePathAttribute(mixed $value): void
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        $this->attributes['image_path'] = is_string($value) ? $value : null;
    }

    public function imageUrl(): ?string
    {
        return PublicStorageUrl::try($this->image_path);
    }

    public function scopeActiveForRole(Builder $query, string $role): Builder
    {
        $now = Carbon::now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function (Builder $q) use ($role) {
                $q->whereJsonContains('target_roles', $role);
            })
            ->orderByDesc('sort_order')
            ->orderByDesc('id');
    }

    /** Build mgplastic://… (or https URL) for the CTA button. */
    public function resolveCtaLink(): string
    {
        $scheme = (string) config('app_links.scheme', 'mgplastic');

        return match ($this->link_type) {
            'url' => filled($this->link_url) ? (string) $this->link_url : "{$scheme}://home",
            'deep_link' => $this->schemePath(ltrim((string) $this->link_path, '/')),
            'plumber' => DeepLink::customScheme('plumber', $this->link_id),
            'shop' => DeepLink::customScheme('shop', $this->link_id),
            'event' => DeepLink::customScheme('event', $this->link_id),
            'product' => DeepLink::customScheme('product', $this->link_id),
            'product_collection' => DeepLink::customScheme('collection', $this->link_id),
            'product_promotion' => DeepLink::customScheme('promotion', $this->link_id),
            'auth_login' => "{$scheme}://auth/login",
            'auth_register' => "{$scheme}://auth/register",
            'auth_register_plumber' => "{$scheme}://auth/register-plumber",
            'auth_register_retail' => "{$scheme}://auth/register-retail",
            'catalog' => "{$scheme}://catalog",
            'wallet' => "{$scheme}://wallet",
            'points' => "{$scheme}://points",
            'pending_receipts' => "{$scheme}://pending-receipts",
            'support' => "{$scheme}://support",
            default => "{$scheme}://home",
        };
    }

    /** @return array<string, mixed> */
    public function toMobilePayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => $this->imageUrl(),
            'cta' => [
                'text' => $this->cta_text,
                'link' => $this->resolveCtaLink(),
            ],
            'frequency' => in_array($this->frequency, self::FREQUENCIES, true)
                ? $this->frequency
                : 'once',
            'dismissible' => (bool) $this->dismissible,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];
    }

    public static function pickForRole(string $role): ?self
    {
        $role = in_array($role, HomeMobileLayoutService::ROLES, true) ? $role : 'guest';

        return static::query()->activeForRole($role)->first();
    }

    protected function schemePath(string $path): string
    {
        $scheme = (string) config('app_links.scheme', 'mgplastic');
        $path = trim($path);
        if ($path === '') {
            return "{$scheme}://home";
        }

        return "{$scheme}://{$path}";
    }
}
