<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ConversionRule extends Model
{
    protected $fillable = [
        'name',
        'vendor_store_id',
        'currency',
        'points_per_currency_unit',
        'min_redeem_points',
        'max_redeem_points',
        'fee_percent',
        'fee_fixed_cents',
        'is_active',
        'starts_at',
        'ends_at',
        'notify_on_conversion',
        'notification_message_ar',
    ];

    protected $casts = [
        'fee_percent' => 'float',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'notify_on_conversion' => 'boolean',
    ];

    public function vendorStore()
    {
        return $this->belongsTo(PlumberStore::class, 'vendor_store_id');
    }

    /** الإعداد العام الوحيد لصرف النقاط */
    public static function globalSettings(): self
    {
        return static::query()->firstOrCreate(
            ['vendor_store_id' => null],
            [
                'name' => 'إعدادات صرف النقاط',
                'currency' => 'LYD',
                'points_per_currency_unit' => 100,
                'min_redeem_points' => 100,
                'is_active' => true,
                'notify_on_conversion' => true,
                'notification_message_ar' => 'تم تحويل نقاطك بنجاح — يمكنك طلب السحب من المحفظة.',
            ]
        );
    }

    public function isRedemptionOpen(?Carbon $at = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $at ??= now();

        if ($this->starts_at && $at->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $at->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function redemptionWindowLabel(): string
    {
        if (! $this->starts_at && ! $this->ends_at) {
            return 'مفتوح دائماً';
        }

        $start = $this->starts_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') ?? '—';
        $end = $this->ends_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') ?? '—';

        return "{$start} → {$end}";
    }
}
