<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class WithdrawalRequest extends Model
{
    protected $fillable = [
        'plumber_id','wallet_account_id','amount_cents','status','method','details',
        'rejection_reason','reviewed_by','approved_at','paid_at',
        'receipt_number','transfer_number',
    ];

    protected $casts = [
        'details'=>'array','rejection_reason'=>'array',
        'approved_at'=>'datetime','paid_at'=>'datetime'
    ];

    public function wallet(){ return $this->belongsTo(WalletAccount::class,'wallet_account_id'); }
    public function plumber(){ return $this->belongsTo(User::class,'plumber_id'); }
    public function reviewer(){ return $this->belongsTo(User::class,'reviewed_by'); }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public static function pendingCount(): int
    {
        if (! Schema::hasTable('withdrawal_requests') || ! Schema::hasColumn('withdrawal_requests', 'status')) {
            return 0;
        }

        return static::query()->pending()->count();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function currencyCode(): string
    {
        return $this->wallet?->currency ?? 'LYD';
    }

    public function formattedAmount(): string
    {
        $cents = (int) ($this->getAttribute('amount_cents') ?? 0);

        return number_format($cents / 100, 2).' '.$this->currencyLabel();
    }

    public function currencyLabel(): string
    {
        return match ($this->currencyCode()) {
            'LYD' => 'د.ل',
            default => $this->currencyCode(),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'قيد المراجعة',
            'approved' => 'معتمد',
            'paid' => 'مدفوع',
            'rejected' => 'مرفوض',
            default => (string) $this->status,
        };
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'bank_transfer' => 'تحويل بنكي',
            'mobile_wallet' => 'محفظة إلكترونية',
            default => (string) $this->method,
        };
    }

    public function payoutDetailsSummary(): string
    {
        $details = (array) ($this->details ?? []);

        if ($this->method === 'bank_transfer') {
            $parts = [];

            $holder = data_get($details, 'name')
                ?? data_get($details, 'account_holder')
                ?? data_get($details, 'holder_name');

            if ($holder) {
                $parts[] = (string) $holder;
            }

            if ($bank = data_get($details, 'bank_name')) {
                $parts[] = (string) $bank;
            }

            if ($iban = data_get($details, 'iban')) {
                $clean = preg_replace('/\s+/', '', (string) $iban);
                if (strlen($clean) >= 8) {
                    $parts[] = 'IBAN •••• '.substr($clean, -4);
                } else {
                    $parts[] = 'IBAN: '.$iban;
                }
            } elseif ($account = data_get($details, 'account_number')) {
                $account = preg_replace('/\D+/', '', (string) $account);
                $parts[] = 'حساب •••• '.substr($account, -4);
            }

            return $parts !== [] ? implode(' · ', $parts) : '—';
        }

        $phone = data_get($details, 'phone')
            ?? data_get($details, 'mobile')
            ?? data_get($details, 'wallet_number');

        if ($phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone);

            return 'هاتف: •••• '.substr($digits, -4);
        }

        $walletName = data_get($details, 'wallet_provider') ?? data_get($details, 'provider');
        if ($walletName) {
            return (string) $walletName;
        }

        return '—';
    }

    public function paymentProofSummary(): ?string
    {
        if ($this->status !== 'paid') {
            return null;
        }

        $parts = [];

        if (filled($this->receipt_number)) {
            $parts[] = 'إيصال: '.$this->receipt_number;
        }

        if (filled($this->transfer_number)) {
            $parts[] = 'تحويل: '.$this->transfer_number;
        }

        return $parts !== [] ? implode(' · ', $parts) : null;
    }

    /** Absolute URL for the printable transfer receipt (auth required). */
    public function receiptUrl(): ?string
    {
        if ($this->status !== 'paid') {
            return null;
        }

        return url('/api/v1/mobile/plumber/withdrawals/'.$this->id.'/receipt');
    }

    public function receiptDownloadUrl(): ?string
    {
        if ($this->status !== 'paid') {
            return null;
        }

        return url('/api/v1/mobile/plumber/withdrawals/'.$this->id.'/receipt/download');
    }

    /** Shareable signed web link (no app token needed). */
    public function receiptPublicUrl(): ?string
    {
        return \App\Http\Controllers\WithdrawalReceiptWebController::signedUrl($this);
    }

    /** Card-friendly payload for mobile UX after payout. */
    public function transferCard(): ?array
    {
        if ($this->status !== 'paid') {
            return null;
        }

        return [
            'title' => 'تم تحويل مستحقاتك ✓',
            'subtitle' => 'طلب سحب #'.$this->id,
            'amount_formatted' => $this->formattedAmount(),
            'method_label' => $this->methodLabel(),
            'payout_summary' => $this->payoutDetailsSummary(),
            'payment_proof' => $this->paymentProofSummary(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_at_formatted' => $this->paid_at
                ? $this->paid_at->timezone('Africa/Tripoli')->format('Y/m/d — h:i A')
                : null,
            'receipt_url' => $this->receiptUrl(),
            'receipt_download_url' => $this->receiptDownloadUrl(),
            'receipt_public_url' => $this->receiptPublicUrl(),
            'cta_label' => 'عرض إيصال التحويل',
        ];
    }
}
