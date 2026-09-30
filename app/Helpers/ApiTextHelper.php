<?php

use App\Support\ApiText;

if (! function_exists('api_plain_text')) {
    /**
     * Strip HTML from descriptions for mobile API payloads.
     */
    function api_plain_text(mixed $value, bool $emptyString = false): ?string
    {
        return ApiText::plain($value, $emptyString);
    }
}

if (! function_exists('api_plain_string')) {
    function api_plain_string(mixed $value): string
    {
        return ApiText::plainString($value);
    }
}
