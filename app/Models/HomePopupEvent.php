<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomePopupEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'home_popup_id',
        'version',
        'event',
        'button_id',
        'user_id',
        'role',
        'device_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function popup(): BelongsTo
    {
        return $this->belongsTo(HomePopup::class, 'home_popup_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
