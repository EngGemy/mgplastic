<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class NetworkCodeService
{
    public function ensure(User $user): string
    {
        if (filled($user->network_code)) {
            return $user->network_code;
        }

        return DB::transaction(function () use ($user) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (filled($locked->network_code)) {
                return $locked->network_code;
            }

            $code = $this->generateForRole($locked->role, (int) $locked->id);
            $locked->forceFill(['network_code' => $code])->save();

            $user->network_code = $code;

            return $code;
        });
    }

    public function generateForRole(string $role, int $id): string
    {
        $prefix = match ($role) {
            'wholesale_distributor' => 'MG-W',
            'retail_trader' => 'MG-R',
            'plumber' => 'MG-P',
            default => 'MG-U',
        };

        $code = sprintf('%s-%06d', $prefix, $id);

        // Extremely unlikely collision with soft-deleted reuse — bump until unique.
        $n = 0;
        while (User::query()->where('network_code', $code)->where('id', '!=', $id)->exists()) {
            $n++;
            $code = sprintf('%s-%06d-%d', $prefix, $id, $n);
        }

        return $code;
    }

    public function normalize(?string $input): string
    {
        $code = strtoupper(trim((string) $input));
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        return $code;
    }

    public function findByCode(string $input): ?User
    {
        $code = $this->normalize($input);

        if ($code === '') {
            return null;
        }

        return User::query()->where('network_code', $code)->first();
    }
}
