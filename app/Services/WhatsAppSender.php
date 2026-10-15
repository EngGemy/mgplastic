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
                'evolution' => $this->sendViaEvolution($digits, $message),
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

    /**
     * Evolution API (Baileys): POST /message/sendText/{instance}
     *
     * @see https://doc.evolution-api.com
     */
    protected function sendViaEvolution(string $digits, string $message): bool
    {
        $base = rtrim((string) config('services.whatsapp.evolution.base_url'), '/');
        $apiKey = trim((string) config('services.whatsapp.evolution.api_key'));
        $instance = trim((string) config('services.whatsapp.evolution.instance', 'mg-plastic'));
        $version = strtolower((string) config('services.whatsapp.evolution.version', 'v2'));

        if ($base === '' || $apiKey === '' || $instance === '') {
            throw new \RuntimeException('اضبط WHATSAPP_EVOLUTION_URL و WHATSAPP_EVOLUTION_API_KEY و WHATSAPP_EVOLUTION_INSTANCE');
        }

        $payload = $version === 'v1'
            ? [
                'number' => $digits,
                'textMessage' => ['text' => $message],
                'options' => [
                    'delay' => 1200,
                    'presence' => 'composing',
                    'linkPreview' => true,
                ],
            ]
            : [
                'number' => $digits,
                'text' => $message,
                'delay' => 1200,
                'linkPreview' => true,
            ];

        $response = Http::withHeaders([
            'apikey' => $apiKey,
        ])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($base.'/message/sendText/'.rawurlencode($instance), $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Evolution WhatsApp HTTP '.$response->status().': '.$response->body());
        }

        return true;
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
