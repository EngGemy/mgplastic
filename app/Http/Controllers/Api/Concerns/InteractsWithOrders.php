<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Order;
use App\Models\User;

trait InteractsWithOrders
{
    protected function loadedOrder(Order $order): Order
    {
        // Include role — stock/permission helpers call isWholesaleDistributor()/isRetailTrader().
        return $order->load([
            'items.product:id,pieces_per_box',
            'requester:id,name,brand_name,role',
            'supplier:id,name,brand_name,role',
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
