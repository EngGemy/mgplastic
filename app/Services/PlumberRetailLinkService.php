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
        if (! $retailTrader->isRetailTrader() || ! $plumber->isPlumber()) {
            return false;
        }

        if ((int) $plumber->parent_distributor_id === (int) $retailTrader->id) {
            return true;
        }

        return $retailTrader->linkedPlumbers()->whereKey($plumber->id)->exists();
    }

    /**
     * Link an already-registered plumber by network code or phone.
     * Multi-shop: adds this retail shop without removing other links.
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

        return $this->link($retailTrader, $plumber);
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

        return DB::transaction(function () use ($retailTrader, $plumber) {
            $locked = User::query()->whereKey($plumber->id)->lockForUpdate()->firstOrFail();

            if ($this->isLinked($retailTrader, $locked)) {
                $this->attach($retailTrader, $locked, $retailTrader);

                return [
                    'plumber' => $locked->fresh(),
                    'created_link' => false,
                    'transferred' => false,
                    'message' => "السباك «{$locked->name}» موجود في شبكتك مسبقاً",
                ];
            }

            $this->attach($retailTrader, $locked, $retailTrader);

            return [
                'plumber' => $locked->fresh(),
                'created_link' => true,
                'transferred' => false,
                'message' => "تم إضافة السباك «{$locked->name}» لشبكتك",
            ];
        });
    }

    /**
     * Retail trader releases this plumber from their shop only (other shops stay).
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

            if (! $this->isLinked($retailTrader, $locked)) {
                throw new \DomainException('هذا السباك ليس في شبكتك');
            }

            $this->detach($retailTrader, $locked);

            return [
                'plumber' => $locked->fresh(),
                'message' => "تم فك ربط السباك «{$locked->name}» من شبكتك",
            ];
        });
    }

    /**
     * On first order to a shop: add that shop to the plumber's network (does not remove others).
     */
    public function linkIfUnassigned(User $retailTrader, User $plumber): bool
    {
        if (! $retailTrader->isRetailTrader() || ! $plumber->isPlumber()) {
            return false;
        }

        return DB::transaction(function () use ($retailTrader, $plumber) {
            $locked = User::query()->whereKey($plumber->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPlumber()) {
                return false;
            }

            if ($this->isLinked($retailTrader, $locked)) {
                return false;
            }

            $this->attach($retailTrader, $locked, $retailTrader);

            return true;
        });
    }

    public function assertInNetwork(User $retailTrader, User $plumber): void
    {
        if ($this->isLinked($retailTrader, $plumber)) {
            return;
        }

        throw new \DomainException('هذا السباك ليس في شبكتك — أضفه بالرقم الموحّد، أو ينضم عند أول طلب يرسله إليك');
    }

    /** Preferred / primary retail shop (legacy parent, else earliest pivot link). */
    public function retailParent(User $plumber): ?User
    {
        if ($plumber->parent_distributor_id) {
            $parent = User::query()->find($plumber->parent_distributor_id);
            if ($parent && $parent->isRetailTrader()) {
                return $parent;
            }
        }

        return $plumber->linkedRetailShops()
            ->where('users.role', 'retail_trader')
            ->orderByPivot('linked_at')
            ->first();
    }

    /** @return list<int> */
    public function linkedRetailShopIds(User $plumber): array
    {
        $ids = $plumber->linkedRetailShops()->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($plumber->parent_distributor_id) {
            $parent = User::query()->find($plumber->parent_distributor_id);
            if ($parent?->isRetailTrader()) {
                $ids[] = (int) $parent->id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function attach(User $retailTrader, User $plumber, ?User $linkedBy = null): void
    {
        $retailTrader->linkedPlumbers()->syncWithoutDetaching([
            $plumber->id => [
                'linked_by' => $linkedBy?->id,
                'linked_at' => now(),
            ],
        ]);

        // Keep legacy primary parent for older UI — set only if empty or invalid.
        $parent = $plumber->parent_distributor_id
            ? User::query()->find($plumber->parent_distributor_id)
            : null;

        if (! $parent || ! $parent->isRetailTrader()) {
            $plumber->forceFill([
                'parent_distributor_id' => $retailTrader->id,
                'is_independent' => false,
            ])->save();
        }
    }

    public function detach(User $retailTrader, User $plumber): void
    {
        $retailTrader->linkedPlumbers()->detach($plumber->id);

        if ((int) $plumber->parent_distributor_id === (int) $retailTrader->id) {
            $nextId = $plumber->linkedRetailShops()
                ->orderByPivot('linked_at')
                ->value('users.id');

            $plumber->forceFill([
                'parent_distributor_id' => $nextId,
                'is_independent' => $nextId === null,
            ])->save();
        }
    }
}
