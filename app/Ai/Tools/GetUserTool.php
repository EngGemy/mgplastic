<?php

namespace App\Ai\Tools;

use App\Models\User;
use InvalidArgumentException;

class GetUserTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_user';
    }

    public function description(): string
    {
        return 'Get a user profile with wallet balance and basic network links.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'user_id' => ['type' => 'integer'],
            'phone' => ['type' => 'string'],
            'network_code' => ['type' => 'string'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $query = User::query();

        if (! empty($arguments['user_id'])) {
            $query->where('id', (int) $arguments['user_id']);
        } elseif (! empty($arguments['phone'])) {
            $query->where('phone', $arguments['phone']);
        } elseif (! empty($arguments['network_code'])) {
            $query->where('network_code', $arguments['network_code']);
        } else {
            throw new InvalidArgumentException('Provide user_id, phone, or network_code.');
        }

        /** @var User|null $user */
        $user = $query->with(['city', 'country', 'parentDistributor:id,name,role,network_code'])->first();
        if (! $user) {
            throw new InvalidArgumentException('User not found.');
        }

        $wallet = null;
        if (method_exists($user, 'wallet')) {
            try {
                $wallet = $user->wallet();
            } catch (\Throwable) {
                $wallet = null;
            }
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role,
                'network_code' => $user->network_code,
                'brand_name' => $user->brand_name,
                'is_approved' => (bool) $user->is_approved,
                'is_active' => (bool) $user->is_active,
                'is_phone_verified' => (bool) $user->is_phone_verified,
                'address' => $user->address,
                'city' => $user->city?->name_ar ?? $user->city?->name_en,
                'country' => $user->country?->name_ar ?? $user->country?->name_en,
                'parent_distributor' => $user->parentDistributor ? [
                    'id' => $user->parentDistributor->id,
                    'name' => $user->parentDistributor->name,
                    'role' => $user->parentDistributor->role,
                    'network_code' => $user->parentDistributor->network_code,
                ] : null,
                'permissions' => $user->permissions,
                'created_at' => optional($user->created_at)?->toIso8601String(),
            ],
            'wallet' => $wallet ? [
                'id' => $wallet->id ?? null,
                'balance_points' => (int) ($wallet->balance_points ?? 0),
                'balance_cents' => (int) ($wallet->balance_cents ?? 0),
                'currency' => $wallet->currency ?? 'LYD',
            ] : null,
        ];
    }
}
