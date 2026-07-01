<?php

// app/Models/ProductVariant.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id','catalog_code',
        'outer_diameter_mm','wall_thickness_mm','insertion_depth_mm','weight_kg_per_m',
        'pressure_class',
        'width_w_mm','height_l_mm','depth_h_mm','depth_h1_mm','depth_h2_mm','depth_h3_mm',
        'd1_mm','d2_mm','d3_mm','d4_mm',
        'extra'
    ];

    protected $casts = [
        'extra' => 'array'
    ];

    public function product() { return $this->belongsTo(Product::class); }
}
