<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Warranty extends Model
{
    protected $fillable = [
        'code', 'plumber_id', 'customer_name', 'customer_phone', 'customer_phone_hash',
        'site_address', 'city_id', 'work_description', 'issued_at', 'starts_at', 'expires_at',
        'status', 'revoked_reason', 'pdf_path', 'card_path', 'whatsapp_sent_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'whatsapp_sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function plumber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plumber_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarrantyItem::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    public function verifyUrl(): string
    {
        return url('/w/'.$this->code);
    }

    public function pdfUrl(): ?string
    {
        return $this->pdf_path ? url('storage/'.ltrim($this->pdf_path, '/')) : null;
    }

    public function cardUrl(): ?string
    {
        return $this->card_path ? url('storage/'.ltrim($this->card_path, '/')) : null;
    }

    public function maskedPhone(): string
    {
        $d = preg_replace('/\D+/', '', $this->customer_phone) ?? '';
        if (strlen($d) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($d) - 4)).substr($d, -4);
    }
}
