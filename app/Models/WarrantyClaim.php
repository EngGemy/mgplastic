<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    protected $fillable = [
        'warranty_id', 'customer_note', 'photos', 'status', 'handled_by', 'resolution_note', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
