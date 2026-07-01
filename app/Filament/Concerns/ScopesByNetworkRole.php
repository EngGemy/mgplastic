<?php

namespace App\Filament\Concerns;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesByNetworkRole
{
    protected static function currentPanelUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function isNetworkAdmin(?User $user = null): bool
    {
        $user ??= static::currentPanelUser();

        return $user && in_array($user->role, ['super_admin', 'admin'], true);
    }

    protected static function scopeInvoicesForRole(Builder $query, ?User $user = null): Builder
    {
        $user ??= static::currentPanelUser();

        if (! $user) {
            return $query->whereRaw('0 = 1');
        }

        if (static::isNetworkAdmin($user)) {
            return $query;
        }

        return match ($user->role) {
            'wholesale_distributor' => $query
                ->where('invoice_type', 'wholesale_pos')
                ->where('wholesale_distributor_id', $user->id),

            'retail_trader' => $query->where(function (Builder $q) use ($user) {
                $q->where('counterparty_user_id', $user->id)
                    ->where('invoice_flow', 'outgoing')
                    ->orWhereHas(
                        'distributions',
                        fn (Builder $d) => $d
                            ->where('to_user_id', $user->id)
                            ->where('tier', 2)
                            ->whereIn('status', ['confirmed', 'points_awarded'])
                    );
            }),

            default => $query->whereRaw('0 = 1'),
        };
    }

    protected static function scopeDistributionsForRole(Builder $query, ?User $user = null): Builder
    {
        $user ??= static::currentPanelUser();

        if (! $user) {
            return $query->whereRaw('0 = 1');
        }

        if (static::isNetworkAdmin($user)) {
            return $query;
        }

        return match ($user->role) {
            'wholesale_distributor' => $query->where(
                fn (Builder $q) => $q
                    ->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id)
            ),

            'retail_trader' => $query->where(
                fn (Builder $q) => $q
                    ->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id)
            ),

            default => $query->whereRaw('0 = 1'),
        };
    }

    protected static function userCanAccessInvoice(Invoice $invoice, ?User $user = null): bool
    {
        return static::scopeInvoicesForRole(Invoice::query(), $user)
            ->whereKey($invoice->getKey())
            ->exists();
    }

    protected static function userCanAccessDistribution(InvoiceDistribution $distribution, ?User $user = null): bool
    {
        return static::scopeDistributionsForRole(InvoiceDistribution::query(), $user)
            ->whereKey($distribution->getKey())
            ->exists();
    }
}
