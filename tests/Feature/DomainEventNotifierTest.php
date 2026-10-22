<?php

namespace Tests\Feature;

use App\Jobs\SendPushJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\DomainEventNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainEventNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_created_notifies_supplier_via_catalog(): void
    {
        Queue::fake();

        $supplier = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);
        $requester = User::factory()->create([
            'role' => 'retail_trader',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'ORD-TEST-1',
            'requester_id' => $requester->id,
            'supplier_id' => $supplier->id,
            'channel' => 'wholesale_to_retail',
            'status' => 'placed',
            'total_quantity' => 5,
            'total_points' => 10,
        ]);

        app(DomainEventNotifier::class)->orderCreated($order->fresh(['requester', 'supplier']));

        $this->assertDatabaseHas('notifications_inbox', [
            'user_id' => $supplier->id,
            'type' => 'order.created',
        ]);

        Queue::assertPushed(SendPushJob::class);
    }

    public function test_account_suspended_uses_high_priority_type(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        app(DomainEventNotifier::class)->accountSuspended($user, 'مخالفة الشروط');

        $this->assertDatabaseHas('notifications_inbox', [
            'user_id' => $user->id,
            'type' => 'account.suspended',
            'priority' => 'high',
        ]);
    }
}
