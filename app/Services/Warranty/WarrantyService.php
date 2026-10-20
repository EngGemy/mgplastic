<?php

namespace App\Services\Warranty;

use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\PlumberScore;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Models\WarrantyItem;
use App\Models\WarrantySetting;
use App\Services\Reputation\PlumberScoreService;
use App\Services\WhatsAppBrandCard;
use App\Services\WhatsAppNotifier;
use App\Support\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarrantyService
{
    public function __construct(
        protected PlumberScoreService $scores,
        protected WhatsAppNotifier $whatsapp,
        protected WhatsAppBrandCard $cards,
    ) {}

    public function unlockProgress(User $plumber): array
    {
        $minLevel = (string) (WarrantySetting::get('min_level') ?? config('warranty.min_level', 'gold'));
        $minScore = (int) (WarrantySetting::get('min_score') ?? config('warranty.min_score', 70));
        $minDist = (int) (WarrantySetting::get('min_distributions') ?? config('warranty.min_distributions', 5));

        $score = PlumberScore::query()->where('user_id', $plumber->id)->first();
        $level = $score?->level ?? 'bronze';
        $scoreVal = (int) ($score?->score ?? 0);
        $dist = (int) ($score?->confirmed_distributions_count ?? 0);
        $openClaims = WarrantyClaim::query()
            ->whereHas('warranty', fn ($q) => $q->where('plumber_id', $plumber->id))
            ->whereIn('status', ['open', 'in_review'])
            ->count();

        $levelOk = $this->levelRank($level) >= $this->levelRank($minLevel);
        $requirements = [
            ['key' => 'level', 'label' => 'المستوى '.$minLevel.' أو أعلى', 'current' => $level, 'target' => $minLevel, 'done' => $levelOk],
            ['key' => 'score', 'label' => 'درجة ≥ '.$minScore, 'current' => $scoreVal, 'target' => $minScore, 'done' => $scoreVal >= $minScore],
            ['key' => 'distributions', 'label' => 'توزيعات مؤكدة', 'current' => $dist, 'target' => $minDist, 'done' => $dist >= $minDist],
            ['key' => 'no_open_claims', 'label' => 'لا مطالبات مفتوحة', 'current' => $openClaims === 0 ? 1 : 0, 'target' => 1, 'done' => $openClaims === 0],
            ['key' => 'account', 'label' => 'حساب نشط ومعتمد', 'current' => ($plumber->is_active && $plumber->is_approved) ? 1 : 0, 'target' => 1, 'done' => (bool) ($plumber->is_active && $plumber->is_approved)],
        ];

        $can = config('warranty.enabled', true) && collect($requirements)->every(fn ($r) => $r['done']);

        return [
            'can_issue' => $can,
            'requirements' => $requirements,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function eligibleItems(User $plumber): array
    {
        $months = (int) (WarrantySetting::get('default_months') ?? config('warranty.default_months', 12));
        $rows = [];

        $distributions = InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('to_user_id', $plumber->id)
            ->where('status', 'confirmed')
            ->with(['items.invoiceItem.product.translations'])
            ->latest('confirmed_at')
            ->limit(100)
            ->get();

        foreach ($distributions as $dist) {
            foreach ($dist->items as $di) {
                $productId = $di->invoiceItem?->product_id;
                if (! $productId) {
                    continue;
                }
                $used = (int) WarrantyItem::query()
                    ->where('distribution_id', $dist->id)
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $remaining = max(0, (int) $di->quantity - $used);
                if ($remaining <= 0) {
                    continue;
                }
                $product = $di->invoiceItem?->product;
                $rows[] = [
                    'distribution_id' => $dist->id,
                    'product_id' => $productId,
                    'invoice_item_id' => $di->invoice_item_id,
                    'name' => $product ? localized_name($product, 'name', 'منتج') : 'منتج',
                    'quantity_available' => (int) $di->quantity,
                    'remaining_quantity' => $remaining,
                    'warranty_months' => $months,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array{customer_name:string,customer_phone:string,work_description?:string,site_address?:string,city_id?:int,customer_consent:bool,items:list}  $data
     */
    public function issue(User $plumber, array $data): Warranty
    {
        $unlock = $this->unlockProgress($plumber);
        if (! $unlock['can_issue']) {
            throw ValidationException::withMessages([
                'warranty' => ['لا يمكنك إصدار ضمان بعد — أكمل شروط الفتح'],
            ]);
        }
        if (empty($data['customer_consent'])) {
            throw ValidationException::withMessages([
                'customer_consent' => ['يجب تأكيد موافقة العميل على استلام الرسالة'],
            ]);
        }

        $items = $data['items'] ?? [];
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['اختر منتجًا واحدًا على الأقل']]);
        }

        $eligible = collect($this->eligibleItems($plumber))->keyBy(fn ($r) => $r['distribution_id'].':'.$r['product_id']);
        $months = (int) (WarrantySetting::get('default_months') ?? config('warranty.default_months', 12));

        return DB::transaction(function () use ($plumber, $data, $items, $eligible, $months) {
            $code = $this->uniqueCode();
            $phone = preg_replace('/\D+/', '', (string) $data['customer_phone']) ?? '';
            $warranty = Warranty::query()->create([
                'code' => $code,
                'plumber_id' => $plumber->id,
                'customer_name' => trim((string) $data['customer_name']),
                'customer_phone' => $phone,
                'customer_phone_hash' => hash('sha256', $phone),
                'site_address' => $data['site_address'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'work_description' => $data['work_description'] ?? null,
                'issued_at' => now(),
                'starts_at' => now(),
                'expires_at' => now()->addMonths($months),
                'status' => 'active',
                'meta' => ['customer_consent' => true, 'consent_at' => now()->toIso8601String()],
            ]);

            foreach ($items as $row) {
                $key = ((int) $row['distribution_id']).':'.((int) $row['product_id']);
                $elig = $eligible->get($key);
                $qty = (int) ($row['quantity'] ?? 0);
                if (! $elig || $qty < 1 || $qty > (int) $elig['remaining_quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ['منتج أو كمية غير صالحة من مشترياتك المؤكدة'],
                    ]);
                }
                WarrantyItem::query()->create([
                    'warranty_id' => $warranty->id,
                    'product_id' => (int) $row['product_id'],
                    'distribution_id' => (int) $row['distribution_id'],
                    'invoice_item_id' => $elig['invoice_item_id'] ?? null,
                    'quantity' => $qty,
                    'warranty_months' => (int) ($elig['warranty_months'] ?? $months),
                ]);
            }

            $this->generatePdf($warranty->fresh(['items.product', 'plumber']));
            $this->generateCard($warranty);
            $this->sendWhatsApp($warranty->fresh(['plumber', 'items']));

            try {
                \App\Jobs\Reputation\RecomputeScoreJob::runSync((int) $plumber->id, 'warranty_issued', (int) $warranty->id);
            } catch (\Throwable) {
            }

            return $warranty->fresh(['items.product']);
        });
    }

    public function sendWhatsApp(Warranty $warranty): void
    {
        if ($warranty->whatsapp_sent_at) {
            return;
        }
        $warranty->loadMissing(['plumber', 'items']);
        $card = null;
        if ($warranty->card_path && Storage::disk('public')->exists($warranty->card_path)) {
            $bin = Storage::disk('public')->get($warranty->card_path);
            $card = [
                'base64' => base64_encode($bin),
                'mimetype' => 'image/jpeg',
                'filename' => basename($warranty->card_path),
            ];
        }
        $caption = WhatsAppMessage::make()
            ->header('🛡️')
            ->event('🛡️', 'ضمان MG')
            ->line('كود الضمان: *'.$warranty->code.'*')
            ->line('السباك: *'.($warranty->plumber?->name ?: '—').'*')
            ->line('_تحقق من الضمان عبر الرابط_')
            ->openInApp($warranty->verifyUrl())
            ->footer($warranty->verifyUrl())
            ->toString();

        $this->whatsapp->sendMany([[
            'phone' => $warranty->customer_phone,
            'text' => $caption,
            'image' => $card,
            'event' => 'warranty_issued',
            'recipient_type' => 'customer',
            'reference_type' => 'warranty',
            'reference_id' => $warranty->id,
        ]]);
        $warranty->update(['whatsapp_sent_at' => now()]);
    }

    public function resend(Warranty $warranty): void
    {
        $hours = (int) config('warranty.resend_cooldown_hours', 24);
        if ($warranty->whatsapp_sent_at && $warranty->whatsapp_sent_at->gt(now()->subHours($hours))) {
            throw ValidationException::withMessages([
                'warranty' => ['يمكن إعادة الإرسال بعد '.$hours.' ساعة من آخر إرسال'],
            ]);
        }
        $warranty->update(['whatsapp_sent_at' => null]);
        $this->sendWhatsApp($warranty);
    }

    public function fileClaim(Warranty $warranty, string $last4, string $note): WarrantyClaim
    {
        $phone = preg_replace('/\D+/', '', $warranty->customer_phone) ?? '';
        if (! str_ends_with($phone, $last4) || strlen($last4) !== 4) {
            throw ValidationException::withMessages([
                'phone_last4' => ['آخر 4 أرقام غير صحيحة'],
            ]);
        }
        if ($warranty->status !== 'active') {
            throw ValidationException::withMessages([
                'warranty' => ['هذا الضمان غير ساري'],
            ]);
        }

        $claim = WarrantyClaim::query()->create([
            'warranty_id' => $warranty->id,
            'customer_note' => $note,
            'status' => 'open',
        ]);

        try {
            \App\Jobs\Reputation\RecomputeScoreJob::dispatch((int) $warranty->plumber_id, 'warranty_claim', (int) $claim->id);
        } catch (\Throwable) {
        }

        return $claim;
    }

    public function expireDue(): int
    {
        return Warranty::query()
            ->where('status', 'active')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    protected function uniqueCode(): string
    {
        do {
            $code = 'MGW-'.strtoupper(Str::random(8));
        } while (Warranty::query()->where('code', $code)->exists());

        return $code;
    }

    protected function generatePdf(Warranty $warranty): void
    {
        $terms = (string) (WarrantySetting::get('terms_ar') ?? config('warranty.terms_ar'));
        $html = view('warranties.pdf', [
            'warranty' => $warranty,
            'terms' => $terms,
            'verifyUrl' => $warranty->verifyUrl(),
        ])->render();

        // Store printable HTML certificate (DomPDF not required on all hosts).
        $path = 'warranties/'.$warranty->code.'.html';
        Storage::disk('public')->put($path, $html);
        $warranty->update(['pdf_path' => $path]);
    }

    protected function generateCard(Warranty $warranty): void
    {
        $asset = $this->cards->assetFor('warranty_issued', [
            'title' => 'ضمان رسمي',
            'hero' => $warranty->code,
            'hero_label' => 'كود الضمان',
            'client' => $warranty->customer_name,
            'rows' => [
                ['سباك', $warranty->plumber?->name ?: '—'],
                ['حتى', optional($warranty->expires_at)->format('Y-m-d') ?: '—'],
            ],
            'badge' => 'ضمان',
        ]);
        if ($asset && isset($asset['relative'])) {
            $warranty->update(['card_path' => $asset['relative']]);
        }
    }

    protected function levelRank(string $level): int
    {
        return match ($level) {
            'bronze' => 1, 'silver' => 2, 'gold' => 3, 'diamond' => 4, default => 0,
        };
    }
}
