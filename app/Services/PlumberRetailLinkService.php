<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlumberRetailLinkService
{
    public function __construct(
        protected NetworkCodeService $codes,
    ) {}

    public function isLinked(User $retailTrader, User $plumber): bool
    {
        return $retailTrader->isRetailTrader()
            && $plumber->isPlumber()
            && (int) $plumber->parent_distributor_id === (int) $retailTrader->id;
    }

    /**
     * Link an already-registered plumber by network code or phone.
     * Explicit link (by code/phone) may transfer the plumber from another retail shop.
     * Passive orders still use linkIfUnassigned — they never steal an existing link.
     *
     * @return array{plumber: User, created_link: bool, transferred: bool, message: string}
     */
    public function linkByCode(User $retailTrader, string $codeOrPhone): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
        }

        $needle = trim($codeOrPhone);
        if ($needle === '') {
            throw new \DomainException('أدخل الرقم الموحّد أو رقم الهاتف');
        }

        $plumber = $this->codes->findByCode($needle)
            ?? User::findByPhone($needle);

        if (! $plumber || ! $plumber->isPlumber()) {
            throw new \DomainException('لم يُعثر على سباك بهذا الرقم — تأكد من الرقم الموحّد أو الهاتف');
        }

        if (! $plumber->is_active) {
            throw new \DomainException('حساب هذا السباك موقوف حالياً');
        }

        return $this->link($retailTrader, $plumber, allowTransfer: true);
    }

    /**
     * @return array{plumber: User, created_link: bool, transferred: bool, message: string}
     */
    public function link(User $retailTrader, User $plumber, bool $allowTransfer = true): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
        }

        if (! $plumber->isPlumber()) {
            throw new \DomainException('هذا الحساب ليس سباكاً');
        }

        return DB::transaction(function () use ($retailTrader, $plumber, $allowTransfer) {
            $locked = User::query()->whereKey($plumber->id)->lockForUpdate()->firstOrFail();
            $parent = $this->retailParent($locked);

            if ($parent && (int) $parent->id === (int) $retailTrader->id) {
                return [
                    'plumber' => $locked->fresh(),
                    'created_link' => false,
                    'transferred' => false,
                    'message' => "السباك «{$locked->name}» موجود في شبكتك مسبقاً",
                ];
            }

            if ($parent && ! $allowTransfer) {
                $shop = $parent->brand_name ?: $parent->name;
                throw new \DomainException("السباك «{$locked->name}» مرتبط بتاجر قطاعي آخر".($shop ? " («{$shop}»)" : ''));
            }

            $transferred = $parent !== null;
            $this->assign($locked, $retailTrader);

            $message = $transferred
                ? "تم نقل السباك «{$locked->name}» إلى شبكتك"
                : "تم إضافة السباك «{$locked->name}» لشبكتك";

            return [
                'plumber' => $locked->fresh(),
                'created_link' => true,
                'transferred' => $transferred,
                'message' => $message,
            ];
        });
    }

    /**
     * Owning retail trader releases a plumber back to the free pool.
     *
     * @return array{plumber: User, message: string}
     */
    public function unlink(User $retailTrader, User $plumber): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
        }

        if (! $plumber->isPlumber()) {
            throw new \DomainException('هذا الحساب ليس سباكاً');
        }

        return DB::transaction(function () use ($retailTrader, $plumber) {
            $locked = User::query()->whereKey($plumber->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->parent_distributor_id !== (int) $retailTrader->id) {
                throw new \DomainException('هذا السباك ليس في شبكتك');
            }

            $locked->forceFill([
                'parent_distributor_id' => null,
                'is_independent' => true,
            ])->save();

            return [
                'plumber' => $locked->fresh(),
                'message' => "تم فك ربط السباك «{$locked->name}» من شبكتك",
            ];
        });
    }

    /**
     * First supply order claims an unassigned plumber.
     * A plumber already linked to a shop is left where he is.
     */
    public function linkIfUnassigned(User $retailTrader, User $plumber): bool
    {
        if (! $retailTrader->isRetailTrader() || ! $plumber->isPlumber()) {
            return false;
        }

        return DB::transaction(function () use ($retailTrader, $plumber) {
            $locked = User::query()->whereKey($plumber->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPlumber() || $this->retailParent($locked)) {
                return false;
            }

            $this->assign($locked, $retailTrader);
            $plumber->parent_distributor_id = $retailTrader->id;

            return true;
        });
    }

    public function assertInNetwork(User $retailTrader, User $plumber): void
    {
        if ($this->isLinked($retailTrader, $plumber)) {
            return;
        }

        if ($this->retailParent($plumber)) {
            throw new \DomainException('هذا السباك مرتبط بتاجر قطاعي آخر');
        }

        throw new \DomainException('هذا السباك ليس في شبكتك — أضفه بالرقم الموحّد، أو ينضم عند أول طلب يرسله إليك');
    }

    public function retailParent(User $plumber): ?User
    {
        if (! $plumber->parent_distributor_id) {
            return null;
        }

        $parent = User::query()->find($plumber->parent_distributor_id);

        return ($parent && $parent->isRetailTrader()) ? $parent : null;
    }

    protected function assign(User $plumber, User $retailTrader): void
    {
        $plumber->forceFill([
            'parent_distributor_id' => $retailTrader->id,
            'is_independent' => false,
        ])->save();
    }
}
