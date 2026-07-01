<?php

if (! function_exists('slabel')) {
    function slabel(string $key, string $fallback = ''): string
    {
        return \App\Models\SystemLabel::get($key, $fallback);
    }
}

if (! function_exists('localized_name')) {
    /** اسم مترجم — العربية أولاً (النظام في ليبيا). */
    function localized_name(?object $model, string $attribute = 'name', ?string $fallback = null): string
    {
        if (! $model || ! method_exists($model, 'translate')) {
            return $fallback ?? '—';
        }

        return $model->translate('ar')?->{$attribute}
            ?? $model->translate(app()->getLocale())?->{$attribute}
            ?? $fallback
            ?? '—';
    }
}
