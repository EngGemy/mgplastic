<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class PlumberStoreImage extends Model
{
    use HasFactory;

    protected $table = 'plumber_store_images';

    protected $fillable = [
        'plumber_store_id',
        'path',
        'caption',
        'sort_order',
    ];

    protected $appends = ['url'];

    public function store()
    {
        return $this->belongsTo(PlumberStore::class, 'plumber_store_id');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }
}
