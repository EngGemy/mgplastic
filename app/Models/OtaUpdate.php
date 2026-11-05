<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class OtaUpdate extends Model
{
    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    protected $fillable = [
        'platform',
        'native_build',
        'version',
        'file_path',
        'release_note',
        'is_active',
        'uploaded_by',
        'activated_by',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::bumpCheckCache());
        static::deleted(fn () => static::bumpCheckCache());
    }

    /** Invalidate cached GET /mobile/ota/check responses (Filament + artisan + tinker). */
    public static function bumpCheckCache(): void
    {
        Cache::forever('ota:check:generation', (int) Cache::get('ota:check:generation', 1) + 1);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    protected function setFilePathAttribute(mixed $value): void
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        $this->attributes['file_path'] = is_string($value) ? $value : null;
    }

    /**
     * Absolute HTTPS download URL for the mobile client (rejects non-https).
     */
    public function downloadUrl(): ?string
    {
        $url = PublicStorageUrl::try($this->file_path);

        if ($url === null) {
            return null;
        }

        // Local/disk configs may return a relative /storage/... path.
        if (str_starts_with($url, '/')) {
            $base = rtrim((string) config('app.url'), '/');
            $url = $base.$url;
        }

        if (str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, strlen('http://'));
        }

        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }

    public static function suggestedFileName(string $platform, string $nativeBuild, int $version): string
    {
        $safeBuild = preg_replace('/[^A-Za-z0-9._-]+/', '-', $nativeBuild) ?: 'build';

        return sprintf('mg-%s-build%s-v%d.zip', $platform, $safeBuild, $version);
    }

    public static function nextVersionFor(string $platform, string $nativeBuild): int
    {
        $max = static::query()
            ->where('platform', $platform)
            ->where('native_build', $nativeBuild)
            ->max('version');

        return ((int) $max) + 1;
    }
}
