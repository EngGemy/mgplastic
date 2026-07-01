<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PointRule extends Model
{
    protected $fillable = [
        'vendor_store_id','type','percent_rate','fixed_points','min_total_cents',
        'max_total_cents','starts_at','ends_at','is_active','name'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'is_active' => 'boolean',
        'percent_rate' => 'float',
    ];

    public function vendorStore(){ return $this->belongsTo(PlumberStore::class, 'vendor_store_id'); }

    public function appliesToAmount(int $totalCents): bool
    {
        if (!$this->is_active) return false;
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->ends_at && $now->gt($this->ends_at)) return false;
        if (!is_null($this->min_total_cents) && $totalCents < $this->min_total_cents) return false;
        if (!is_null($this->max_total_cents) && $totalCents > $this->max_total_cents) return false;
        return true;
    }
}
