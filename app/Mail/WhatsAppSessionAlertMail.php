<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WhatsAppSessionAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $kind, // down|restored
        public string $session,
        public string $detail,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->kind === 'restored'
            ? '[MG WhatsApp] تمت استعادة الجلسة'
            : '[MG WhatsApp] الجلسة غير متاحة';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $body = $this->kind === 'restored'
            ? '<p>جلسة واتساب <strong>'.e($this->session).'</strong> عادت إلى WORKING.</p>'
            : '<p>جلسة واتساب <strong>'.e($this->session).'</strong> ليست WORKING.</p>';

        return new Content(
            htmlString: $body.'<p>'.e($this->detail).'</p>',
        );
    }
}
