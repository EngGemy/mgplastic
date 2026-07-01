<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

class SocialMedia extends Model
{
    use Translatable;

    public $translatedAttributes = ['name'];
    protected $fillable = ['platform', 'url'];
}
