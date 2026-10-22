<?php

namespace App\Notifications\Catalog;

final class NotificationDefinition
{
    /**
     * @param  array{ar: string, en: string}  $title
     * @param  array{ar: string, en: string}  $body
     * @param  list<string>  $channels  push|in_app|email
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly array $title,
        public readonly array $body,
        public readonly string $priority,
        public readonly array $channels,
        public readonly bool $defaultPush,
        public readonly bool $defaultInApp,
        public readonly bool $defaultEmail,
        public readonly string $screen,
    ) {}

    public function titleFor(string $locale): string
    {
        $locale = str_starts_with(strtolower($locale), 'en') ? 'en' : 'ar';

        return $this->title[$locale] ?? $this->title['ar'];
    }

    public function bodyFor(string $locale): string
    {
        $locale = str_starts_with(strtolower($locale), 'en') ? 'en' : 'ar';

        return $this->body[$locale] ?? $this->body['ar'];
    }
}
