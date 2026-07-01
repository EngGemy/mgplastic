<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

class Privacy extends Model
{
    use Translatable;

    public $translatedAttributes = ['title', 'content'];
    protected $fillable = ['slug'];
}
