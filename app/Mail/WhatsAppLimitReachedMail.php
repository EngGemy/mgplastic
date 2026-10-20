<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WhatsAppLimitReachedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $limit,
        public int $sentToday,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[MG WhatsApp] تم بلوغ الحد اليومي للإرسال',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>تم بلوغ حد إرسال واتساب اليومي (<strong>'.$this->limit.'</strong>).'
                .' أُرسل اليوم: <strong>'.$this->sentToday.'</strong> رسالة (غير OTP).</p>'
                .'<p>الرسائل الإضافية ستُسجَّل كـ skipped_limit حتى الغد.</p>',
        );
    }
}
