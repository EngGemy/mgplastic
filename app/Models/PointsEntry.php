<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointsEntry extends Model
{
    protected $table = 'points_ledger';

    protected $fillable = ['plumber_id','points_delta','source_type','source_id','meta'];

    protected $casts = ['meta'=>'array'];

    public function plumber(){ return $this->belongsTo(User::class, 'plumber_id'); }
    public function source(){ return $this->morphTo(); }
}
