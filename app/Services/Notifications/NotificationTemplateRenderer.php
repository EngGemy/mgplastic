<?php

namespace App\Services\Notifications;

use App\Notifications\Catalog\NotificationDefinition;

final class NotificationTemplateRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(NotificationDefinition $def, string $locale, array $data, ?string $titleOverride = null, ?string $bodyOverride = null): array
    {
        $title = $titleOverride ?? $def->titleFor($locale);
        $body = $bodyOverride ?? $def->bodyFor($locale);

        return [
            'title' => $this->interpolate($title, $data),
            'body' => $this->interpolate($body, $data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function interpolate(string $template, array $data): string
    {
        return (string) preg_replace_callback('/:([a-zA-Z0-9_]+)/', function (array $m) use ($data) {
            $key = $m[1];
            if (! array_key_exists($key, $data)) {
                return $m[0];
            }
            $value = $data[$key];

            return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }, $template);
    }
}
