<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Notifications\DomainEventNotifier;
use App\Services\WithdrawalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(DomainEventNotifier::class, function ($mock) {
            $mock->shouldReceive('withdrawalRequested')->byDefault();
            $mock->shouldReceive('withdrawalPaid')->byDefault();
            $mock->shouldReceive('withdrawalRejected')->byDefault();
        });
    }

    public function test_request_withdrawal_holds_balance_and_creates_pending_request(): void
    {
        $plumber = $this->plumberWithBalance(50_000);

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/withdrawals', [
                'amount_cents' => 20_000,
                'method' => 'mobile_wallet',
                'details' => ['phone' => '0912345678'],
            ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount_cents', 20_000);

        $this->assertSame(30_000, (int) $plumber->wallet('LYD')->fresh()->balance_cents);
        $this->assertDatabaseHas('withdrawal_requests', [
            'plumber_id' => $plumber->id,
            'amount_cents' => 20_000,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_account_id' => $plumber->wallet('LYD')->id,
            'type' => 'withdrawal',
            'amount_cents' => -20_000,
        ]);
    }

    public function test_request_withdrawal_rejects_insufficient_balance(): void
    {
        $plumber = $this->plumberWithBalance(5_000);

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/withdrawals', [
                'amount_cents' => 10_000,
                'method' => 'bank_transfer',
                'details' => ['iban' => 'LY123'],
            ])
            ->assertStatus(400)
            ->assertJsonPath('status', false);

        $this->assertSame(5_000, (int) $plumber->wallet('LYD')->fresh()->balance_cents);
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_mark_paid_records_proof_and_keeps_held_balance(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $plumber = $this->plumberWithBalance(40_000);
        $withdrawal = $this->createPendingWithdrawal($plumber, 15_000);

        $this->actingAs($admin);
        WithdrawalRequestService::markPaid($withdrawal, [
            'receipt_number' => 'RCP-100',
            'transfer_number' => '',
        ]);

        $withdrawal->refresh();
        $this->assertSame('paid', $withdrawal->status);
        $this->assertSame('RCP-100', $withdrawal->receipt_number);
        $this->assertNotNull($withdrawal->paid_at);
        $this->assertSame(25_000, (int) $plumber->wallet('LYD')->fresh()->balance_cents);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_account_id' => $plumber->wallet('LYD')->id,
            'type' => 'withdrawal',
            'amount_cents' => 0,
            'related_id' => $withdrawal->id,
        ]);
    }

    public function test_reject_and_refund_restores_balance(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $plumber = $this->plumberWithBalance(40_000);
        $withdrawal = $this->createPendingWithdrawal($plumber, 12_000);

        $this->assertSame(28_000, (int) $plumber->wallet('LYD')->fresh()->balance_cents);

        $this->actingAs($admin);
        WithdrawalRequestService::rejectAndRefund($withdrawal, [
            'reason_ar' => 'بيانات غير صحيحة',
            'reason_en' => 'Invalid details',
        ]);

        $withdrawal->refresh();
        $this->assertSame('rejected', $withdrawal->status);
        $this->assertSame(40_000, (int) $plumber->wallet('LYD')->fresh()->balance_cents);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_account_id' => $plumber->wallet('LYD')->id,
            'type' => 'adjustment',
            'amount_cents' => 12_000,
            'related_id' => $withdrawal->id,
        ]);
    }

    public function test_mark_paid_twice_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $plumber = $this->plumberWithBalance(30_000);
        $withdrawal = $this->createPendingWithdrawal($plumber, 10_000);

        $this->actingAs($admin);
        WithdrawalRequestService::markPaid($withdrawal, ['receipt_number' => 'RCP-1']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('الطلب ليس قيد المراجعة');

        WithdrawalRequestService::markPaid($withdrawal->fresh(), ['receipt_number' => 'RCP-2']);
    }

    public function test_reject_after_paid_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $plumber = $this->plumberWithBalance(30_000);
        $withdrawal = $this->createPendingWithdrawal($plumber, 10_000);

        $this->actingAs($admin);
        WithdrawalRequestService::markPaid($withdrawal, ['transfer_number' => 'TRX-9']);

        $balanceAfterPay = (int) $plumber->wallet('LYD')->fresh()->balance_cents;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('الطلب ليس قيد المراجعة');

        try {
            WithdrawalRequestService::rejectAndRefund($withdrawal->fresh(), [
                'reason_ar' => 'خطأ',
            ]);
        } finally {
            $this->assertSame($balanceAfterPay, (int) $plumber->wallet('LYD')->fresh()->balance_cents);
        }
    }

    private function plumberWithBalance(int $balanceCents): User
    {
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $wallet = $plumber->wallet('LYD');
        $wallet->update(['balance_cents' => $balanceCents]);

        return $plumber->fresh();
    }

    private function createPendingWithdrawal(User $plumber, int $amountCents): WithdrawalRequest
    {
        $response = $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/withdrawals', [
                'amount_cents' => $amountCents,
                'method' => 'mobile_wallet',
                'details' => ['phone' => '0911111111'],
            ])
            ->assertCreated();

        return WithdrawalRequest::query()->findOrFail($response->json('data.id'));
    }
}
