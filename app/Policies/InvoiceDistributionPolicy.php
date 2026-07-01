<?php

namespace App\Policies;

use App\Models\InvoiceDistribution;
use App\Models\User;

class InvoiceDistributionPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'wholesale_distributor', 'retail_trader']);
    }

    public function view(User $user, InvoiceDistribution $distribution): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $distribution->from_user_id === $user->id
            || $distribution->to_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'wholesale_distributor', 'retail_trader']);
    }

    public function update(User $user, InvoiceDistribution $distribution): bool
    {
        if ($distribution->status !== 'draft') {
            return false;
        }

        return $user->role === 'super_admin' || $distribution->from_user_id === $user->id;
    }

    public function confirm(User $user, InvoiceDistribution $distribution): bool
    {
        if ($distribution->status !== 'draft') {
            return false;
        }

        return $user->role === 'super_admin' || $distribution->from_user_id === $user->id;
    }

    public function delete(User $user, InvoiceDistribution $distribution): bool
    {
        if ($distribution->status !== 'draft') {
            return false;
        }

        return $user->role === 'super_admin' || $distribution->from_user_id === $user->id;
    }
}
