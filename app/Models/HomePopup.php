<?php

namespace App\Models;

use App\Services\HomeMobileLayoutService;
use App\Support\DeepLink;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class HomePopup extends Model
{
    public const KINDS = ['announcement', 'poll'];

    public const SHOW_MODES = ['once', 'daily', 'always'];

    public const BUTTON_STYLES = ['primary', 'outline', 'ghost'];

    public const LINK_TYPES = [
        'none',
        'home',
        'deep_link',
        'url',
        'plumber',
        'shop',
        'event',
        'product',
        'product_collection',
        'product_promotion',
        'register',
        'login',
        'catalog',
        'wallet',
        'points',
        'pending_receipts',
        'support',
        'list_plumbers',
        'list_shops',
        'list_events',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $popup) {
            if (! $popup->isDirty('image_path')) {
                return;
            }
            $old = $popup->getOriginal('image_path');
            $new = $popup->image_path;
            if (is_array($old)) {
                $old = $old[0] ?? null;
            }
            if (filled($old) && $old !== $new) {
                Storage::disk('public')->delete($old);
            }
        });

        static::saved(function () {
            try {
                app(HomeMobileLayoutService::class)->forgetCache();
            } catch (\Throwable) {
                // ignore during early boot / migrate
            }
        });

        static::deleted(function (self $popup) {
            if (filled($popup->image_path) && is_string($popup->image_path)) {
                Storage::disk('public')->delete($popup->image_path);
            }
            try {
                app(HomeMobileLayoutService::class)->forgetCache();
            } catch (\Throwable) {
                // ignore
            }
        });
    }

    protected $fillable = [
        'version',
        'title_ar',
        'title_en',
        'body_ar',
        'body_en',
        'image_path',
        'image_ratio',
        'kind',
        'buttons',
        'target_roles',
        'test_user_ids',
        'frequency', // legacy
        'show',
        'dismissible',
        'dismiss_text_ar',
        'dismiss_text_en',
        'starts_at',
        'ends_at',
        'sort_order',
        'is_active',
        // legacy columns kept for old rows / backfill
        'title',
        'subtitle',
        'cta_text',
        'link_type',
        'link_id',
        'link_path',
        'link_url',
        'layout',
    ];

    protected function casts(): array
    {
        return [
            'buttons' => 'array',
            'target_roles' => 'array',
            'test_user_ids' => 'array',
            'dismissible' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
            'image_ratio' => 'float',
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

    public function events(): HasMany
    {
        return $this->hasMany(HomePopupEvent::class);
    }

    public function imageUrl(): ?string
    {
        return PublicStorageUrl::try($this->image_path);
    }

    public function scopeActiveForRole(Builder $query, string $role, ?User $user = null): Builder
    {
        $now = Carbon::now();
        $role = $role === 'vendor' ? 'retail_trader' : $role;

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
            ->when(
                $user,
                function (Builder $q) use ($user) {
                    $q->where(function (Builder $inner) use ($user) {
                        $inner->whereNull('test_user_ids')
                            ->orWhere('test_user_ids', '[]')
                            ->orWhere('test_user_ids', '')
                            ->orWhereJsonContains('test_user_ids', $user->id)
                            ->orWhereJsonContains('test_user_ids', (string) $user->id);
                    });
                },
                function (Builder $q) {
                    // Guests never see test-only campaigns.
                    $q->where(function (Builder $inner) {
                        $inner->whereNull('test_user_ids')
                            ->orWhere('test_user_ids', '[]')
                            ->orWhere('test_user_ids', '');
                    });
                }
            )
            ->orderByDesc('sort_order')
            ->orderByDesc('id');
    }

    public static function pickForRole(string $role, ?User $user = null): ?self
    {
        $role = $role === 'vendor' ? 'retail_trader' : $role;
        $role = in_array($role, HomeMobileLayoutService::ROLES, true) ? $role : 'guest';

        return static::query()->activeForRole($role, $user)->first();
    }

    /**
     * Mobile popup payload (null when none). Prefer single-locale strings.
     *
     * @return array<string, mixed>|null
     */
    public static function mobilePayloadFor(?string $role, ?User $user = null, string $locale = 'ar'): ?array
    {
        $role = $role === 'vendor' ? 'retail_trader' : (string) $role;
        $popup = static::pickForRole($role !== '' ? $role : 'guest', $user);

        return $popup?->toMobilePayload($role !== '' ? $role : 'guest', $locale);
    }

    /** @return array<string, mixed> */
    public function toMobilePayload(string $matchedRole = 'guest', string $locale = 'ar'): array
    {
        $locale = str_starts_with(strtolower($locale), 'en') ? 'en' : 'ar';
        $buttons = $this->resolvedButtons($locale, $matchedRole);

        $dismissible = $this->dismissible !== false;
        if ($buttons === [] && $this->kind === 'poll') {
            $dismissible = true; // never trap the user
        }

        $show = in_array((string) $this->show, self::SHOW_MODES, true)
            ? (string) $this->show
            : $this->legacyShow();

        $ratio = (float) ($this->image_ratio ?: 0.8);
        if ($ratio < 0.5 || $ratio > 2.0) {
            $ratio = 0.8;
        }

        $payload = [
            'id' => $this->id,
            'version' => max(1, (int) $this->version),
            'kind' => $this->kind === 'poll' ? 'poll' : 'announcement',
            'image_url' => $this->imageUrl(),
            'image_ratio' => round($ratio, 2),
            'title' => $this->localized('title', $locale),
            'body' => $this->localized('body', $locale),
            'buttons' => $buttons,
            'dismissible' => $dismissible,
            'show' => $show,
        ];

        $dismissText = $this->localized('dismiss_text', $locale);
        if ($dismissText !== null) {
            $payload['dismiss_text'] = $dismissText;
        }

        // Legacy single-button fields (mobile still accepts them if buttons empty).
        if ($buttons === [] && filled($this->cta_text)) {
            $payload['button_text'] = (string) $this->cta_text;
            $payload['button_link'] = $this->resolveLinkFromParts(
                (string) ($this->link_type ?: 'home'),
                $this->link_id,
                $this->link_path,
                $this->link_url
            );
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolvedButtons(string $locale, string $role): array
    {
        $raw = is_array($this->buttons) ? $this->buttons : [];
        if ($raw === [] && filled($this->cta_text)) {
            $raw = [[
                'id' => 'cta',
                'text_ar' => $this->cta_text,
                'text_en' => $this->cta_text,
                'link_type' => $this->link_type ?: 'home',
                'link_id' => $this->link_id,
                'link_path' => $this->link_path,
                'link_url' => $this->link_url,
                'style' => 'primary',
            ]];
        }

        $out = [];
        $index = 0;
        foreach ($raw as $btn) {
            if (! is_array($btn)) {
                continue;
            }

            $id = trim((string) ($btn['id'] ?? ''));
            if ($id === '') {
                $id = 'btn_'.($index + 1);
            }

            $text = $this->pickLocalized(
                $btn['text'] ?? null,
                $btn['text_ar'] ?? null,
                $btn['text_en'] ?? null,
                $locale
            );
            if ($text === null || $text === '') {
                continue;
            }

            $linkType = (string) ($btn['link_type'] ?? 'none');
            $link = $this->resolveLinkFromParts(
                $linkType,
                isset($btn['link_id']) ? (int) $btn['link_id'] : null,
                isset($btn['link_path']) ? (string) $btn['link_path'] : null,
                isset($btn['link_url']) ? (string) $btn['link_url'] : null,
            );

            // Drop dead entity targets.
            if (! $this->linkTargetIsAlive($linkType, isset($btn['link_id']) ? (int) $btn['link_id'] : null)) {
                continue;
            }

            // Hide role-inappropriate deep links (server-side).
            if ($link !== null && ! $this->linkAllowedForRole($link, $role)) {
                continue;
            }

            $style = (string) ($btn['style'] ?? '');
            if (! in_array($style, self::BUTTON_STYLES, true)) {
                $style = $index === 0 ? 'primary' : 'outline';
            }

            $row = [
                'id' => $id,
                'text' => $text,
                'style' => $style,
            ];

            // Poll answers / none → null link
            if ($linkType === 'none' || $link === null) {
                $row['link'] = null;
            } else {
                $row['link'] = $link;
            }

            $out[] = $row;
            $index++;
        }

        return $out;
    }

    public function bumpVersion(): void
    {
        $this->version = max(1, (int) $this->version) + 1;
        $this->save(); // saved hook clears home popup/layout caches
    }

    public function refreshImageRatioFromFile(): void
    {
        if (! filled($this->image_path)) {
            return;
        }

        try {
            $absolute = Storage::disk('public')->path($this->image_path);
            if (! is_file($absolute)) {
                return;
            }
            $size = @getimagesize($absolute);
            if (! is_array($size) || ($size[1] ?? 0) < 1) {
                return;
            }
            $ratio = round(((float) $size[0]) / ((float) $size[1]), 2);
            if ($ratio >= 0.5 && $ratio <= 2.0) {
                $this->image_ratio = $ratio;
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    /** @return array{views:int,clicks:int,dismisses:int,ctr:float,by_button:array<string,int>} */
    public function statsSummary(?Carbon $from = null, ?Carbon $to = null, ?string $role = null): array
    {
        $q = $this->events()->getQuery();
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        if ($to) {
            $q->where('created_at', '<=', $to);
        }
        if ($role) {
            $q->where('role', $role);
        }

        $views = (clone $q)->where('event', 'view')
            ->selectRaw('COUNT(DISTINCT COALESCE(user_id, device_id)) as c')
            ->value('c');
        $clicks = (clone $q)->where('event', 'click')
            ->selectRaw('COUNT(DISTINCT COALESCE(user_id, device_id)) as c')
            ->value('c');
        $dismisses = (clone $q)->where('event', 'dismiss')
            ->selectRaw('COUNT(DISTINCT COALESCE(user_id, device_id)) as c')
            ->value('c');

        $byButton = (clone $q)->where('event', 'click')
            ->whereNotNull('button_id')
            ->selectRaw('button_id, COUNT(*) as c')
            ->groupBy('button_id')
            ->pluck('c', 'button_id')
            ->map(fn ($c) => (int) $c)
            ->all();

        $views = (int) $views;
        $clicks = (int) $clicks;
        $dismisses = (int) $dismisses;

        return [
            'views' => $views,
            'clicks' => $clicks,
            'dismisses' => $dismisses,
            'ctr' => $views > 0 ? round($clicks / $views, 4) : 0.0,
            'by_button' => $byButton,
        ];
    }

    protected function localized(string $field, string $locale): ?string
    {
        return $this->pickLocalized(
            null,
            $this->{$field.'_ar'} ?? null,
            $this->{$field.'_en'} ?? null,
            $locale
        );
    }

    protected function pickLocalized(mixed $dual, mixed $ar, mixed $en, string $locale): ?string
    {
        if (is_array($dual)) {
            $ar = $dual['ar'] ?? $ar;
            $en = $dual['en'] ?? $en;
        } elseif (is_string($dual) && $dual !== '') {
            return $dual;
        }

        $primary = $locale === 'en' ? $en : $ar;
        $fallback = $locale === 'en' ? $ar : $en;

        if (filled($primary)) {
            return (string) $primary;
        }
        if (filled($fallback)) {
            return (string) $fallback;
        }

        return null;
    }

    protected function legacyShow(): string
    {
        return match ((string) ($this->frequency ?? 'once')) {
            'once_per_day', 'daily' => 'daily',
            'every_open', 'always' => 'always',
            default => 'once',
        };
    }

    protected function resolveLinkFromParts(
        string $linkType,
        ?int $linkId,
        ?string $linkPath,
        ?string $linkUrl
    ): ?string {
        $scheme = (string) config('app_links.scheme', 'mgplastic');

        return match ($linkType) {
            'none' => null,
            'url' => filled($linkUrl) ? (string) $linkUrl : null,
            'deep_link' => filled($linkPath) ? $scheme.'://'.ltrim((string) $linkPath, '/') : null,
            'plumber' => $linkId ? DeepLink::customScheme('plumber', $linkId) : null,
            'shop' => $linkId ? DeepLink::customScheme('shop', $linkId) : null,
            'event' => $linkId ? DeepLink::customScheme('event', $linkId) : null,
            'product' => $linkId ? DeepLink::customScheme('product', $linkId) : null,
            'product_collection' => $linkId ? DeepLink::customScheme('collection', $linkId) : null,
            'product_promotion' => $linkId ? DeepLink::customScheme('promotion', $linkId) : null,
            'register' => $scheme.'://register',
            'login' => $scheme.'://login',
            'auth_register' => $scheme.'://register',
            'auth_login' => $scheme.'://login',
            'auth_register_plumber' => $scheme.'://register',
            'auth_register_retail' => $scheme.'://register',
            'catalog' => $scheme.'://catalog',
            'wallet' => $scheme.'://wallet',
            'points' => $scheme.'://points',
            'pending_receipts' => $scheme.'://pending-receipts',
            'support' => $scheme.'://support',
            'list_plumbers' => $scheme.'://list/plumbers',
            'list_shops' => $scheme.'://list/shops',
            'list_events' => $scheme.'://list/events',
            'home' => $scheme.'://home',
            default => $scheme.'://home',
        };
    }

    protected function linkTargetIsAlive(string $linkType, ?int $linkId): bool
    {
        if ($linkId === null || $linkId < 1) {
            return ! in_array($linkType, ['plumber', 'shop', 'event', 'product', 'product_collection', 'product_promotion'], true);
        }

        return match ($linkType) {
            'plumber' => User::query()->whereKey($linkId)->where('role', 'plumber')->where('is_active', true)->exists(),
            'shop' => User::query()
                ->whereKey($linkId)
                ->whereIn('role', ['retail_trader', 'wholesale_distributor', 'vendor'])
                ->where('is_active', true)
                ->exists(),
            'event' => Event::query()->whereKey($linkId)->exists(),
            'product' => Product::query()->whereKey($linkId)->exists(),
            'product_collection' => ProductCollection::query()->whereKey($linkId)->where('is_active', true)->exists(),
            'product_promotion' => ProductPromotion::query()->whereKey($linkId)->where('is_active', true)->exists(),
            default => true,
        };
    }

    protected function linkAllowedForRole(string $link, string $role): bool
    {
        $role = $role === 'vendor' ? 'retail_trader' : $role;
        $path = parse_url($link, PHP_URL_PATH) ?: $link;
        $path = ltrim(str_replace('mgplastic://', '', $path), '/');
        $head = explode('/', $path)[0] ?? '';
        $head = explode('?', $head)[0];

        $guestBlocked = [
            'wallet', 'points', 'notifications', 'pending-receipts', 'order', 'returns',
            'profile', 'plumber-orders', 'my-works', 'reputation', 'trader-orders',
            'choose-wholesaler', 'join-wholesaler', 'network', 'distributor-orders',
            'new-order', 'join-requests', 'pos', 'invoices', 'store-products',
            'reports', 'store-media', 'withdrawal',
        ];

        if ($role === 'guest' && in_array($head, $guestBlocked, true)) {
            return false;
        }

        if ($role === 'plumber' && in_array($head, [
            'trader-orders', 'choose-wholesaler', 'join-wholesaler', 'distributor-orders',
            'new-order', 'join-requests', 'pos', 'invoices', 'store-products', 'reports', 'store-media',
        ], true)) {
            return false;
        }

        if ($role === 'retail_trader' && in_array($head, [
            'plumber-orders', 'my-works', 'reputation', 'distributor-orders',
            'new-order', 'join-requests', 'pos',
        ], true)) {
            return false;
        }

        if ($role === 'wholesale_distributor' && in_array($head, [
            'plumber-orders', 'my-works', 'reputation', 'trader-orders',
            'choose-wholesaler', 'join-wholesaler',
        ], true)) {
            return false;
        }

        return true;
    }
}
