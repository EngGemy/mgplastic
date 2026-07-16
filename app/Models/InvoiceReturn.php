<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceReturn extends Model
{
    protected $fillable = [
        'return_number',
        'invoice_id',
        'distribution_id',
        'from_user_id',
        'to_user_id',
        'tier',
        'status',
        'total_quantity',
        'total_points',
        'note',
        'created_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'total_quantity' => 'integer',
            'total_points' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(InvoiceDistribution::class, 'distribution_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceReturnItem::class);
    }
}
