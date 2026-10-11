<?php

namespace App\Ai\Tools\Concerns;

use App\Models\User;
use App\Models\WalletAccount;

trait MapsNetworkUser
{
    /** @return array<string, mixed> */
    protected function mapUserBrief(User $user, bool $withWallet = false): array
    {
        $row = [
            'id' => $user->id,
            'name' => $user->name,
            'brand_name' => $user->brand_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'network_code' => $user->network_code,
            'is_approved' => (bool) $user->is_approved,
            'is_active' => (bool) $user->is_active,
            'city_id' => $user->city_id,
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'updated_at' => optional($user->updated_at)?->toIso8601String(),
        ];

        if ($withWallet) {
            $wallet = WalletAccount::query()
                ->where('owner_id', $user->id)
                ->where('currency', 'LYD')
                ->first();

            $row['balance_points'] = (int) ($wallet?->balance_points ?? 0);
            $row['balance_cents'] = (int) ($wallet?->balance_cents ?? 0);
        }

        return $row;
    }
}
