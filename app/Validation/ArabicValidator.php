<?php

namespace App\Validation;

use Illuminate\Validation\Validator;

/**
 * Validation copy is always Arabic, even when the panel or API locale is English or French.
 */
class ArabicValidator extends Validator
{
    public function passes()
    {
        $translator = $this->translator;
        $locale = $translator->getLocale();
        $fallback = $translator->getFallback();

        $translator->setLocale('ar');
        $translator->setFallback('ar');

        try {
            return parent::passes();
        } finally {
            $translator->setLocale($locale);
            $translator->setFallback($fallback);
        }
    }
}
