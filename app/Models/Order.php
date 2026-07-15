<?php

namespace App\Models;

use App\Support\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'channel',
        'requester_id',
        'supplier_id',
        'status',
        'total_quantity',
        'total_points',
        'carrier_name',
        'tracking_number',
        'expected_delivery_at',
        'note',
        'supplier_note',
        'placed_at',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'confirmed_by',
        'shipped_by',
        'delivered_by',
        'created_by',
        'delivered_invoice_id',
        'delivered_reference',
    ];

    protected function casts(): array
    {
        return [
            'total_quantity' => 'integer',
            'total_points' => 'integer',
            'expected_delivery_at' => 'date',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_reference' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function deliveredInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'delivered_invoice_id');
    }

    public function isFactoryChannel(): bool
    {
        return $this->channel === OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE;
    }

    public function isRetailChannel(): bool
    {
        return $this->channel === OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL;
    }

    public function statusLabel(): string
    {
        return OrderStatus::label($this->status);
    }

    public function statusColor(): string
    {
        return OrderStatus::color($this->status);
    }

    /** Orders this user placed or must fulfil. */
    public function scopeForNetworkUser(Builder $query, User $user): Builder
    {
        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('requester_id', $user->id)
                ->orWhere('supplier_id', $user->id);
        });
    }

    public function scopeIncomingFor(Builder $query, User $user): Builder
    {
        return $query->where('supplier_id', $user->id);
    }

    public function scopePlacedBy(Builder $query, User $user): Builder
    {
        return $query->where('requester_id', $user->id);
    }
}
