<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Order;
use App\Models\User;

trait InteractsWithOrders
{
    protected function loadedOrder(Order $order): Order
    {
        return $order->load([
            'items',
            'requester:id,name,brand_name',
            'supplier:id,name,brand_name',
        ]);
    }

    protected function isBuyer(Order $order, User $user): bool
    {
        return (int) $order->requester_id === (int) $user->id;
    }

    protected function isSupplier(Order $order, User $user): bool
    {
        return $order->supplier_id !== null && (int) $order->supplier_id === (int) $user->id;
    }

    protected function isParty(Order $order, User $user): bool
    {
        return $this->isBuyer($order, $user)
            || $this->isSupplier($order, $user)
            || in_array($user->role, ['super_admin', 'admin'], true);
    }
}
