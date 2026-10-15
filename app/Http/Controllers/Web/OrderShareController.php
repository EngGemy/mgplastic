<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\WebsiteSetting;
use App\Support\OrderStatus;
use Illuminate\Http\Response;

class OrderShareController extends Controller
{
    public function show(Order $order): Response
    {
        $order->loadMissing([
            'items.product.translations',
            'requester',
            'supplier',
        ]);

        return response()->view('orders.share', [
            'order' => $order,
            'settings' => WebsiteSetting::instance(),
            'statusLabel' => OrderStatus::label($order->status),
            'statusDescription' => OrderStatus::definitions()[$order->status]['description'] ?? '',
        ]);
    }
}
