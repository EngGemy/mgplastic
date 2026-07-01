<?php
// app/Http/Resources/SizeResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SizeResource extends JsonResource
{
    public function toArray($request)
    {
        $locale = app()->getLocale();

        // لو عندك حقول ترجمة name/label في Size:
        $name = method_exists($this->resource, 'translate')
            ? ($this->translate($locale)->name ?? $this->name ?? $this->code)
            : ($this->name ?? $this->code);

        $image = $this->image ?? null; // عدّل اسم العمود لو مختلف
        $imageUrl = $image
            ? (str_starts_with($image, 'sizes/') ? Storage::disk('public')->url($image) : $image)
            : null;

        return [
            'id'        => (int) $this->id,
            'code'      => (string) ($this->code ?? ''),
            'name'      => (string) $name,
            'image_url' => $imageUrl,
            'system'    => $this->whenLoaded('system', function () {
                $sysName = method_exists($this->system, 'translate')
                    ? ($this->system->translate(app()->getLocale())->name ?? $this->system->name ?? $this->system->code)
                    : ($this->system->name ?? $this->system->code);

                return [
                    'id'   => (int) $this->system->id,
                    'code' => (string) ($this->system->code ?? ''),
                    'name' => (string) $sysName,
                ];
            }),
            // 'price' => $this->pivot->price ?? null,  // لو فعّلت أعمدة pivot
            // 'stock' => $this->pivot->stock ?? null,
        ];
    }
}
