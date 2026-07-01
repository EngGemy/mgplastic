<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_account_id','type','amount_cents','points_delta','description','meta',
        'related_type','related_id','created_by'
    ];

    protected $casts = ['meta' => 'array'];

    public function wallet(){ return $this->belongsTo(WalletAccount::class, 'wallet_account_id'); }
    public function creator(){ return $this->belongsTo(User::class, 'created_by'); }
    public function related(){ return $this->morphTo(); }
}
