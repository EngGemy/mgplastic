<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class TermsCondition extends Model implements TranslatableContract
{
    use Translatable;

    protected $fillable = ['slug'];

    // fields that live in the *_translations table
    public $translatedAttributes = ['title', 'content'];

    // (optional) only if you want to be explicit:
    // protected $translationModel = TermsConditionTranslation::class;
    // protected $translationForeignKey = 'terms_condition_id';

    public $useTranslationFallback = true; // pairs with translatable.php config

}
