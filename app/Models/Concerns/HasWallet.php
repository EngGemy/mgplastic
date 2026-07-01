<?php

namespace App\Models\Concerns;

use App\Models\WalletAccount;

trait HasWallet
{
    public function wallet(string $currency = 'LYD'): WalletAccount
    {
        /** @var \App\Models\User $this */
        return WalletAccount::firstOrCreate(
            ['owner_id' => $this->id, 'currency' => $currency],
            ['balance_cents' => 0, 'balance_points' => 0]
        );
    }
}
