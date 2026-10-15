<?php

namespace App\Services;

use App\Support\PriceListWhatsApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSender
{
    /**
     * Attempt to deliver a WhatsApp text message.
     *
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function send(?string $phone, string $message): array
    {
        $digits = PriceListWhatsApp::international($phone);
        $chatUrl = PriceListWhatsApp::url($phone, $message);
        $driver = (string) config('services.whatsapp.driver', 'log');

        if ($digits === null || strlen($digits) < 11) {
            return [
                'sent' => false,
                'phone' => null,
                'chat_url' => null,
                'driver' => $driver,
                'error' => 'لا يوجد رقم واتساب صالح',
            ];
        }

        try {
            $sent = match ($driver) {
                'libyasms' => $this->sendViaLibyaSms($digits, $message),
                'http' => $this->sendViaHttp($digits, $message),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] send failed', [
                'driver' => $driver,
                'phone' => $digits,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'phone' => $digits,
                'chat_url' => $chatUrl,
                'driver' => $driver,
                'error' => $e->getMessage(),
            ];
        }

        if ($driver === 'log') {
            Log::info('[WhatsApp] message prepared', [
                'phone' => $digits,
                'message' => $message,
                'chat_url' => $chatUrl,
            ]);
        }

        return [
            'sent' => $sent,
            'phone' => $digits,
            'chat_url' => $chatUrl,
            'driver' => $driver,
            'error' => $sent || $driver === 'log' ? null : 'فشل إرسال واتساب',
        ];
    }

    protected function sendViaLibyaSms(string $digits, string $message): bool
    {
        $token = trim((string) config('services.whatsapp.libyasms.token'));
        $instance = trim((string) config('services.whatsapp.libyasms.instance_id'));
        $base = rtrim((string) config('services.whatsapp.libyasms.base_url', 'https://whatsapp.libyasms.com'), '/');

        if ($token === '' || $instance === '') {
            throw new \RuntimeException('اضبط WHATSAPP_LIBYASMS_TOKEN و WHATSAPP_LIBYASMS_INSTANCE_ID');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->get($base.'/api/WhatsAppMessages/SendMessage/SendTextMessage', [
                'instanceId' => $instance,
                'phoneNumber' => '+'.$digits,
                'message' => $message,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('LibyaSMS WhatsApp HTTP '.$response->status().': '.$response->body());
        }

        return true;
    }

    protected function sendViaHttp(string $digits, string $message): bool
    {
        $url = trim((string) config('services.whatsapp.http.url'));
        if ($url === '') {
            throw new \RuntimeException('اضبط WHATSAPP_API_URL');
        }

        $request = Http::acceptJson()->timeout(20)->asJson();
        $token = trim((string) config('services.whatsapp.http.token'));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($url, [
            'to' => $digits,
            'phone' => '+'.$digits,
            'message' => $message,
            'body' => $message,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('WhatsApp HTTP '.$response->status().': '.$response->body());
        }

        return true;
    }
}
