<?php

namespace App\Ai\Tools;

use App\Models\WebsiteSetting;
use InvalidArgumentException;

class UpdateWebsiteSettingFieldTool extends AbstractTool
{
    /** @var list<string> */
    private const ALLOWED = [
        'site_name',
        'site_domain',
        'seo_title',
        'seo_description',
        'footer_tagline',
        'about_eyebrow',
        'about_title',
        'about_subtitle',
        'about_badge_year',
        'about_badge_text',
        'contact_phone',
        'contact_whatsapp',
        'contact_email',
        'contact_address',
        'contact_address_detail',
        'contact_work_days',
        'contact_work_hours',
        'points_eyebrow',
        'points_title',
        'points_subtitle',
    ];

    public function isWrite(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'update_website_setting_field';
    }

    public function description(): string
    {
        return 'Update a single safe text field on WebsiteSetting. Requires AI_TOOLS_ALLOW_WRITE=true. JSON/array/image fields are blocked.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'field' => [
                'type' => 'string',
                'description' => 'Allowed field name, e.g. site_name, contact_phone, seo_title',
            ],
            'value' => [
                'type' => 'string',
                'description' => 'New string value',
            ],
        ], ['field', 'value']);
    }

    public function handle(array $arguments): array
    {
        $field = (string) ($arguments['field'] ?? '');
        if (! in_array($field, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Field not allowed. Allowed: '.implode(', ', self::ALLOWED));
        }

        $value = (string) ($arguments['value'] ?? '');
        $settings = WebsiteSetting::instance();
        $settings->update([$field => $value]);

        return [
            'field' => $field,
            'value' => $settings->fresh()->{$field},
        ];
    }
}
