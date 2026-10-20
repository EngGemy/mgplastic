<?php

namespace App\Filament\Resources\TestimonialResource\Pages;

use App\Filament\Resources\TestimonialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTestimonial extends CreateRecord
{
    protected static string $resource = TestimonialResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['rating'] = 5;
        $data['avatar_initials'] = $data['avatar_initials'] ?? $this->initials($data['name_ar'] ?? '');
        $data['published_at'] = $data['published_at'] ?? now();

        return $data;
    }

    protected function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = collect($parts)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

        return mb_strtoupper($letters ?: mb_substr($name, 0, 2));
    }
}
