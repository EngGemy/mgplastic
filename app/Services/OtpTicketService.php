<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;

class OtpTicketService
{
    public const TTL_MINUTES = 15;

    public function issue(User $user, string $purpose = 'otp'): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => (int) $user->id,
            'purpose' => $purpose,
            'exp' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function userFromTicket(?string $ticket, string $purpose = 'otp'): ?User
    {
        if (! filled($ticket)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($ticket), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data)
            || ($data['purpose'] ?? null) !== $purpose
            || (int) ($data['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        return User::query()->find((int) ($data['uid'] ?? 0));
    }
}
