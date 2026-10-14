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
     * A plumber stays with the first retail trader who claimed them.
     *
     * @return array{plumber: User, created_link: bool, message: string}
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

        return $this->link($retailTrader, $plumber);
    }

    /**
     * @return array{plumber: User, created_link: bool, message: string}
     */
    public function link(User $retailTrader, User $plumber): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
        }

        if (! $plumber->isPlumber()) {
            throw new \DomainException('هذا الحساب ليس سباكاً');
        }

        return DB::transaction(function () use ($retailTrader, $plumber) {
            $locked = User::query()->whereKey($plumber->id)->lockForUpdate()->firstOrFail();
            $parent = $this->retailParent($locked);

            if ($parent && (int) $parent->id === (int) $retailTrader->id) {
                return [
                    'plumber' => $locked->fresh(),
                    'created_link' => false,
                    'message' => "السباك «{$locked->name}» موجود في شبكتك مسبقاً",
                ];
            }

            if ($parent) {
                throw new \DomainException("السباك «{$locked->name}» مرتبط بتاجر قطاعي آخر");
            }

            $this->assign($locked, $retailTrader);

            return [
                'plumber' => $locked->fresh(),
                'created_link' => true,
                'message' => "تم إضافة السباك «{$locked->name}» لشبكتك",
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
