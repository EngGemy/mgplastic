<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SocialLink extends Model
{
    protected $fillable = [
        'linkable_type',
        'linkable_id',
        'platform',
        'url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public const PLATFORMS = [
        'facebook' => 'فيسبوك',
        'instagram' => 'إنستغرام',
        'whatsapp' => 'واتساب',
        'twitter' => 'X / تويتر',
        'youtube' => 'يوتيوب',
        'tiktok' => 'تيك توك',
        'website' => 'موقع إلكتروني',
        'snapchat' => 'سناب شات',
    ];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'platform_label' => self::PLATFORMS[$this->platform] ?? $this->platform,
            'url' => $this->url,
            'sort_order' => $this->sort_order,
        ];
    }
}
