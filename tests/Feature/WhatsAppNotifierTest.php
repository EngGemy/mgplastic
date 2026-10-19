<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Services\WhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function enableN8n(array $extra = []): void
    {
        config(array_merge([
            'services.n8n.url' => 'https://n8n.test/webhook/notify',
            'services.n8n.secret' => 'test-secret',
            'services.n8n.enabled' => true,
            'services.n8n.admin_phones' => ['01012345678', '201098765432'],
        ], $extra));
    }

    public function test_normalizes_egyptian_local_phone(): void
    {
        $notifier = app(WhatsAppNotifier::class);

        $this->assertSame('201012345678', $notifier->normalizePhone('+20 10-1234-5678'));
        $this->assertSame('201012345678', $notifier->normalizePhone('01012345678'));
        $this->assertSame('201098765432', $notifier->normalizePhone('201098765432'));
    }

    public function test_otp_sends_sync_without_admins(): void
    {
        $this->enableN8n();
        Http::fake([
            'https://n8n.test/webhook/notify' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create([
            'phone' => '01011112222',
            'is_phone_verified' => false,
        ]);

        $meta = app(PhoneOtpService::class)->issue($user, 'login');

        $this->assertTrue(Hash::check($meta['otp'], $user->fresh()->otp_code));
        $this->assertTrue(now()->lt($user->fresh()->otp_expires_at));

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://n8n.test/webhook/notify') {
                return false;
            }
            if ($request->header('X-Secret')[0] !== 'test-secret') {
                return false;
            }
            $messages = $request['messages'] ?? [];
            if (count($messages) !== 1) {
                return false;
            }
            $msg = $messages[0];

            return $msg['phone'] === '201011112222'
                && str_contains($msg['text'], 'رمز التحقق الخاص بك:')
                && str_contains($msg['text'], 'MG للصناعات البلاستيكية');
        });
    }

    public function test_invoice_created_notifies_customer_and_admins_once(): void
    {
        $this->enableN8n();
        Queue::fake();

        $customer = User::factory()->create([
            'phone' => '01012345678',
            'name' => 'عميل تجريبي',
            'role' => 'wholesale_distributor',
        ]);

        Invoice::query()->create([
            'number' => 'INV-100',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $customer->id,
            'total_cents' => 150050,
            'currency' => 'LYD',
            'status' => 'approved',
        ]);

        Queue::assertPushed(SendWhatsAppNotification::class, function (SendWhatsAppNotification $job) {
            $phones = collect($job->messages)->pluck('phone')->unique()->values()->all();
            // customer 01012345678 → 201012345678 (also in admin list) + second admin
            $this->assertContains('201012345678', $phones);
            $this->assertContains('201098765432', $phones);
            $this->assertTrue(
                collect($job->messages)->every(fn ($m) => str_contains($m['text'], 'فاتورة جديدة')
                    && str_contains($m['text'], 'INV-100'))
            );

            // Deduped: same phone+text appears once
            $keys = collect($job->messages)->map(fn ($m) => $m['phone'].'|'.$m['text'])->unique();

            return $keys->count() === count($job->messages);
        });
    }

    public function test_disabled_setting_skips_send(): void
    {
        $this->enableN8n(['services.n8n.enabled' => false]);
        Http::fake();

        app(WhatsAppNotifier::class)->send('01012345678', 'مرحبا');

        Http::assertNothingSent();
    }

    public function test_notify_user_and_admins_queues_job(): void
    {
        $this->enableN8n();
        Queue::fake();

        app(WhatsAppNotifier::class)->notifyUserAndAdmins('01111111111', "MG للصناعات البلاستيكية\nاختبار");

        Queue::assertPushed(SendWhatsAppNotification::class, function (SendWhatsAppNotification $job) {
            return count($job->messages) >= 2;
        });
    }
}
