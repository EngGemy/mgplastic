<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SystemHealthAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{key: string, label: string, status: string, value: mixed, details: ?string}>  $checks
     */
    public function __construct(
        public string $kind, // degraded|recovered|repeat
        public array $checks,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->kind) {
            'recovered' => '[MG Monitoring] تم استعادة صحة النظام',
            'repeat' => '[MG Monitoring] تنبيه مستمر — مشاكل لم تُحل',
            default => '[MG Monitoring] تحذير صحة النظام',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rows = '';
        foreach ($this->checks as $check) {
            $rows .= '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.e($check['label']).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.e((string) $check['status']).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.e(is_scalar($check['value'] ?? null) ? (string) $check['value'] : json_encode($check['value'] ?? null)).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.e((string) ($check['details'] ?? '')).'</td>'
                .'</tr>';
        }

        $intro = match ($this->kind) {
            'recovered' => 'عادت فحوصات المراقبة إلى الحالة الطبيعية.',
            'repeat' => 'ما زالت المشاكل التالية قائمة (تذكير دوري).',
            default => 'تم رصد مشاكل في صحة النظام.',
        };

        $html = '<p>'.e($intro).'</p>'
            .'<table style="border-collapse:collapse;width:100%">'
            .'<thead><tr>'
            .'<th style="padding:6px 10px;border:1px solid #ddd;text-align:right">الفحص</th>'
            .'<th style="padding:6px 10px;border:1px solid #ddd;text-align:right">الحالة</th>'
            .'<th style="padding:6px 10px;border:1px solid #ddd;text-align:right">القيمة</th>'
            .'<th style="padding:6px 10px;border:1px solid #ddd;text-align:right">التفاصيل</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p>لوحة المراقبة: '.e(url(\App\Support\AdminPanelPath::url('system-health'))).'</p>';

        return new Content(htmlString: $html);
    }
}
