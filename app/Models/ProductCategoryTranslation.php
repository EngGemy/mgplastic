<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategoryTranslation extends Model
{
    public $timestamps = false;
    protected $fillable = ['name', 'description'];
}
