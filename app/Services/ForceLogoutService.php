<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ForceLogoutService
{
    /**
     * End all web sessions + API tokens + biometric unlock for a user.
     *
     * @return array{sessions:int, tokens:int, biometric:int}
     */
    public function revokeAll(User $user): array
    {
        $sessions = 0;
        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        $tokens = $user->tokens()->delete();

        $biometric = BiometricDevice::query()
            ->where('user_id', $user->id)
            ->delete();

        // saveQuietly: avoid observer loops; skip if row is already being deleted.
        if ($user->exists) {
            $user->forceFill(['remember_token' => null])->saveQuietly();
        }

        return [
            'sessions' => (int) $sessions,
            'tokens' => (int) $tokens,
            'biometric' => (int) $biometric,
        ];
    }

    /** Remove Sanctum tokens / biometric rows whose user no longer exists. */
    public function purgeOrphans(): array
    {
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereNotIn('tokenable_id', function ($q) {
                $q->select('id')->from('users');
            })
            ->delete();

        // Morph alias variants used historically
        $tokens += DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereNotIn('tokenable_id', function ($q) {
                $q->select('id')->from('users');
            })
            ->delete();

        $biometric = 0;
        if (DB::getSchemaBuilder()->hasTable('biometric_devices')) {
            $biometric = DB::table('biometric_devices')
                ->whereNotIn('user_id', function ($q) {
                    $q->select('id')->from('users');
                })
                ->delete();
        }

        return [
            'tokens' => (int) $tokens,
            'biometric' => (int) $biometric,
        ];
    }
}
