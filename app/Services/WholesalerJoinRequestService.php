<?php

namespace App\Services;

use App\Models\User;
use App\Models\WholesalerJoinRequest;
use App\Services\Notifications\DomainEventNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WholesalerJoinRequestService
{
    public function __construct(
        protected NetworkCodeService $codes,
        protected RetailNetworkLinkService $links,
        protected DomainEventNotifier $notifications,
    ) {}

    /**
     * Retail trader asks a wholesaler (by network code / phone) to join their network.
     *
     * @return array{request: WholesalerJoinRequest, created: bool, message: string}
     */
    public function requestJoin(User $retailTrader, string $codeOrPhone, ?string $message = null): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
        }

        if (! $retailTrader->is_active || ! $retailTrader->is_approved) {
            throw new \DomainException('حسابك غير مفعّل حالياً — تواصل مع الدعم');
        }

        $needle = $this->codes->normalize($codeOrPhone);
        if ($needle === '') {
            throw new \DomainException('أدخل الرقم الموحّد أو رقم الهاتف لموزّع الجملة');
        }

        $wholesaler = $this->codes->findByCode($needle)
            ?? User::findByPhone($codeOrPhone);

        if (! $wholesaler || ! $wholesaler->isWholesaleDistributor()) {
            throw new \DomainException('لم يُعثر على موزّع جملة بهذا الرقم — تأكد من الرقم الموحّد أو الهاتف');
        }

        if (! $wholesaler->is_active || ! $wholesaler->is_approved) {
            throw new \DomainException('حساب هذا الموزّع غير مفعّل حالياً');
        }

        if ($this->links->isLinked($wholesaler, $retailTrader)) {
            return [
                'request' => $this->syntheticApproved($wholesaler, $retailTrader),
                'created' => false,
                'message' => 'أنت مرتبط بهذا الموزّع مسبقاً',
            ];
        }

        return DB::transaction(function () use ($retailTrader, $wholesaler, $message) {
            $existing = WholesalerJoinRequest::query()
                ->where('wholesaler_id', $wholesaler->id)
                ->where('retail_trader_id', $retailTrader->id)
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (filled($message)) {
                    $existing->update(['message' => mb_substr(trim($message), 0, 500)]);
                }

                return [
                    'request' => $existing->fresh(['wholesaler.city', 'retailTrader.city']),
                    'created' => false,
                    'message' => 'لديك طلب انضمام معلّق لهذا الموزّع — بانتظار موافقته',
                ];
            }

            $req = WholesalerJoinRequest::query()->create([
                'wholesaler_id' => $wholesaler->id,
                'retail_trader_id' => $retailTrader->id,
                'status' => WholesalerJoinRequest::STATUS_PENDING,
                'message' => filled($message) ? mb_substr(trim($message), 0, 500) : null,
            ]);

            $this->notifications->networkJoinRequested($req->fresh(['wholesaler', 'retailTrader']));

            return [
                'request' => $req->fresh(['wholesaler.city', 'retailTrader.city']),
                'created' => true,
                'message' => 'تم إرسال طلب الانضمام لموزّع الجملة — بانتظار الموافقة',
            ];
        });
    }

    public function cancel(User $retailTrader, WholesalerJoinRequest $request): WholesalerJoinRequest
    {
        if ((int) $request->retail_trader_id !== (int) $retailTrader->id) {
            throw new \DomainException('هذا الطلب ليس لك');
        }

        if (! $request->isPending()) {
            throw new \DomainException('لا يمكن إلغاء طلب غير معلّق');
        }

        $request->update([
            'status' => WholesalerJoinRequest::STATUS_CANCELLED,
            'reviewed_at' => now(),
            'reviewed_by' => $retailTrader->id,
        ]);

        return $request->fresh(['wholesaler.city', 'retailTrader.city']);
    }

    public function approve(User $wholesaler, WholesalerJoinRequest $request): WholesalerJoinRequest
    {
        if ((int) $request->wholesaler_id !== (int) $wholesaler->id) {
            throw new \DomainException('هذا الطلب ليس لشبكتك');
        }

        if (! $request->isPending()) {
            throw new \DomainException('هذا الطلب لم يعد معلّقاً');
        }

        return DB::transaction(function () use ($wholesaler, $request) {
            $locked = WholesalerJoinRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new \DomainException('هذا الطلب لم يعد معلّقاً');
            }

            $retail = User::query()->whereKey($locked->retail_trader_id)->lockForUpdate()->firstOrFail();

            if (! $retail->isRetailTrader() || ! $retail->is_active) {
                $locked->update([
                    'status' => WholesalerJoinRequest::STATUS_REJECTED,
                    'rejection_reason' => 'حساب التاجر القطاعي غير صالح',
                    'reviewed_by' => $wholesaler->id,
                    'reviewed_at' => now(),
                ]);

                throw new \DomainException('حساب التاجر القطاعي غير صالح للموافقة');
            }

            $this->links->attach($wholesaler, $retail, $wholesaler);

            $locked->update([
                'status' => WholesalerJoinRequest::STATUS_APPROVED,
                'reviewed_by' => $wholesaler->id,
                'reviewed_at' => now(),
            ]);

            $fresh = $locked->fresh(['wholesaler.city', 'retailTrader.city']);
            $this->notifications->networkJoinApproved($fresh);

            return $fresh;
        });
    }

    public function reject(User $wholesaler, WholesalerJoinRequest $request, ?string $reason = null): WholesalerJoinRequest
    {
        if ((int) $request->wholesaler_id !== (int) $wholesaler->id) {
            throw new \DomainException('هذا الطلب ليس لشبكتك');
        }

        if (! $request->isPending()) {
            throw new \DomainException('هذا الطلب لم يعد معلّقاً');
        }

        $request->update([
            'status' => WholesalerJoinRequest::STATUS_REJECTED,
            'rejection_reason' => filled($reason) ? mb_substr(trim($reason), 0, 500) : null,
            'reviewed_by' => $wholesaler->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $request->fresh(['wholesaler.city', 'retailTrader.city']);
        $this->notifications->networkJoinRejected($fresh);

        return $fresh;
    }

    /** @return Collection<int, WholesalerJoinRequest> */
    public function outgoingFor(User $retailTrader, ?string $status = null): Collection
    {
        return WholesalerJoinRequest::query()
            ->where('retail_trader_id', $retailTrader->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['wholesaler.city', 'retailTrader.city'])
            ->latest('id')
            ->get();
    }

    /** @return Collection<int, WholesalerJoinRequest> */
    public function incomingFor(User $wholesaler, ?string $status = 'pending'): Collection
    {
        return WholesalerJoinRequest::query()
            ->where('wholesaler_id', $wholesaler->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['wholesaler.city', 'retailTrader.city'])
            ->latest('id')
            ->get();
    }

    public function pendingIncomingCount(User $wholesaler): int
    {
        return WholesalerJoinRequest::query()
            ->where('wholesaler_id', $wholesaler->id)
            ->pending()
            ->count();
    }

    protected function syntheticApproved(User $wholesaler, User $retail): WholesalerJoinRequest
    {
        $req = new WholesalerJoinRequest([
            'wholesaler_id' => $wholesaler->id,
            'retail_trader_id' => $retail->id,
            'status' => WholesalerJoinRequest::STATUS_APPROVED,
        ]);
        $req->setRelation('wholesaler', $wholesaler->loadMissing('city'));
        $req->setRelation('retailTrader', $retail->loadMissing('city'));
        $req->id = 0;

        return $req;
    }
}
