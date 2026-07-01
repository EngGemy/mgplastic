<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PlumberStoreImageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'url'          => $this->url,       // from accessor
            'path'         => $this->path,
            'caption'      => $this->caption,
            'sort_order'   => (int) $this->sort_order,
            'is_active'    => (bool) $this->is_active,
            'is_primary'   => (bool) $this->is_primary,
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
