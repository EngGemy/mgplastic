<?php

namespace App\Http\Resources\Mobile;

use App\Models\WalletAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletAccount */
class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'balance_cents' => $this->balance_cents,
            'balance_formatted' => number_format($this->balance_cents / 100, 2).' د.ل',
            'balance_points' => (int) $this->balance_points,
        ];
    }
}
