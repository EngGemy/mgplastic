<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RetailNetworkLinkService
{
    public function __construct(
        protected NetworkCodeService $codes,
    ) {}

    public function isLinked(User $wholesaler, User $retailTrader): bool
    {
        if (! $wholesaler->isWholesaleDistributor() || ! $retailTrader->isRetailTrader()) {
            return false;
        }

        if ((int) $retailTrader->parent_distributor_id === (int) $wholesaler->id) {
            return true;
        }

        return $wholesaler->linkedRetailTraders()
            ->whereKey($retailTrader->id)
            ->exists();
    }

    public function assertLinked(User $wholesaler, User $retailTrader): void
    {
        if (! $this->isLinked($wholesaler, $retailTrader)) {
            throw new \DomainException('هذا التاجر القطاعي غير مرتبط بشبكتك — أضفه عبر الرقم الموحّد أولاً');
        }
    }

    /**
     * Link an already-registered retail trader by network code (or phone).
     *
     * @return array{retail: User, created_link: bool, message: string}
     */
    public function linkByCode(User $wholesaler, string $codeOrPhone, User $linkedBy): array
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            throw new \DomainException('هذه العملية متاحة لموزّعي الجملة فقط');
        }

        $needle = $this->codes->normalize($codeOrPhone);
        if ($needle === '') {
            throw new \DomainException('أدخل الرقم الموحّد أو رقم الهاتف');
        }

        $retail = $this->codes->findByCode($needle)
            ?? User::findByPhone($codeOrPhone);

        if (! $retail) {
            throw new \DomainException('لم يُعثر على تاجر بهذا الرقم الموحّد — تأكد من الرقم أو سجّله جديداً');
        }

        if (! $retail->isRetailTrader()) {
            throw new \DomainException('هذا الرقم لا يخص تاجر قطاعي');
        }

        if (! $retail->is_active) {
            throw new \DomainException('حساب هذا التاجر موقوف حالياً');
        }

        if ($this->isLinked($wholesaler, $retail)) {
            $this->attach($wholesaler, $retail, $linkedBy);

            return [
                'retail' => $retail->fresh(),
                'created_link' => false,
                'message' => "التاجر «{$retail->name}» مرتبط بشبكتك مسبقاً — الرقم الموحّد: {$retail->network_code}",
            ];
        }

        $this->attach($wholesaler, $retail, $linkedBy);

        return [
            'retail' => $retail->fresh(),
            'created_link' => true,
            'message' => "تم إضافة التاجر «{$retail->name}» لشبكتك — الرقم الموحّد: {$retail->network_code}",
        ];
    }

    /**
     * Register a brand-new retail trader and link to this wholesaler.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerAndLink(User $wholesaler, array $data, User $createdBy): User
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            throw new \DomainException('هذه العملية متاحة لموزّعي الجملة فقط');
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            throw new \DomainException('رقم الهاتف مطلوب');
        }

        $existing = User::query()->where('phone', $phone)->first();
        if ($existing) {
            if ($existing->isRetailTrader()) {
                throw new \DomainException(
                    "هذا الهاتف مسجّل مسبقاً كتاجر قطاعي بالرقم الموحّد «{$existing->network_code}». "
                    .'استخدم خيار «مسجّل من قبل» وأدخل الرقم الموحّد لإضافته لشبكتك.'
                );
            }

            throw new \DomainException('رقم الهاتف مستخدم لحساب آخر في النظام');
        }

        return DB::transaction(function () use ($wholesaler, $data, $createdBy, $phone) {
            $retail = new User();
            $retail->fill([
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password'] ?? str()->random(12)),
                'role' => 'retail_trader',
                'brand_name' => $data['brand_name'] ?? null,
                'address' => $data['address'] ?? null,
                'store_description' => $data['store_description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'country_id' => $data['country_id'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'parent_distributor_id' => $wholesaler->id,
                'is_independent' => false,
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
                'approved_at' => now(),
            ]);
            $retail->save();

            $this->codes->ensure($retail);
            $this->attach($wholesaler, $retail, $createdBy);

            return $retail->fresh();
        });
    }

    public function attach(User $wholesaler, User $retailTrader, ?User $linkedBy = null): void
    {
        $wholesaler->linkedRetailTraders()->syncWithoutDetaching([
            $retailTrader->id => [
                'linked_by' => $linkedBy?->id,
                'linked_at' => now(),
            ],
        ]);

        $parent = $retailTrader->parent_distributor_id
            ? User::query()->find($retailTrader->parent_distributor_id)
            : null;

        if (! $parent || ! $parent->isWholesaleDistributor()) {
            $retailTrader->forceFill([
                'parent_distributor_id' => $wholesaler->id,
                'is_independent' => false,
            ])->save();
        }
    }

    /** @return list<int> */
    public function linkedRetailerIds(User $wholesaler): array
    {
        return $this->linkedRetailersFor($wholesaler)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function detach(User $wholesaler, User $retailTrader): void
    {
        $wholesaler->linkedRetailTraders()->detach($retailTrader->id);

        // Always re-read parent from DB — callers may hold a stale User instance.
        $retailTrader->refresh();

        if ((int) $retailTrader->parent_distributor_id === (int) $wholesaler->id) {
            $nextId = $retailTrader->linkedWholesalers()
                ->orderByPivot('linked_at')
                ->value('users.id');

            $retailTrader->forceFill([
                'parent_distributor_id' => $nextId,
                'is_independent' => $nextId === null,
            ])->save();
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public function linkedRetailersFor(User $wholesaler)
    {
        $ids = $wholesaler->linkedRetailTraders()->pluck('users.id')
            ->merge(
                User::query()
                    ->where('role', 'retail_trader')
                    ->where('parent_distributor_id', $wholesaler->id)
                    ->pluck('id')
            )
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->whereIn('id', $ids)
            ->where('role', 'retail_trader')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public function linkedWholesalersFor(User $retailTrader)
    {
        if (! $retailTrader->isRetailTrader()) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        $ids = $retailTrader->linkedWholesalers()->pluck('users.id')
            ->merge(
                collect([(int) ($retailTrader->parent_distributor_id ?? 0)])->filter()
            )
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->whereIn('id', $ids)
            ->where('role', 'wholesale_distributor')
            ->where('is_active', true)
            ->orderBy('brand_name')
            ->orderBy('name')
            ->get();
    }

    /** @return list<int> */
    public function linkedWholesalerIds(User $retailTrader): array
    {
        return $this->linkedWholesalersFor($retailTrader)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Retail trader links themselves to a wholesaler by network code / phone.
     * Multi-wholesaler: adds without removing other links.
     *
     * @return array{wholesaler: User, created_link: bool, message: string}
     */
    public function linkWholesalerByCode(User $retailTrader, string $codeOrPhone): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
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

        if ($this->isLinked($wholesaler, $retailTrader)) {
            $this->attach($wholesaler, $retailTrader, $retailTrader);

            return [
                'wholesaler' => $wholesaler->fresh(),
                'created_link' => false,
                'message' => "الموزّع «".($wholesaler->brand_name ?: $wholesaler->name)."» مرتبط بشبكتك مسبقاً",
            ];
        }

        $this->attach($wholesaler, $retailTrader, $retailTrader);

        return [
            'wholesaler' => $wholesaler->fresh(),
            'created_link' => true,
            'message' => "تم إضافة الموزّع «".($wholesaler->brand_name ?: $wholesaler->name)."» لشبكتك",
        ];
    }

    /**
     * Retail trader removes one wholesaler from their multi-wholesaler network.
     *
     * @return array{wholesaler: User, message: string}
     */
    public function unlinkWholesaler(User $retailTrader, User $wholesaler): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('هذه العملية متاحة لتاجر القطاعي فقط');
        }

        if (! $wholesaler->isWholesaleDistributor()) {
            throw new \DomainException('هذا الحساب ليس موزّع جملة');
        }

        if (! $this->isLinked($wholesaler, $retailTrader)) {
            throw new \DomainException('هذا الموزّع غير مرتبط بشبكتك');
        }

        $this->detach($wholesaler, $retailTrader);

        return [
            'wholesaler' => $wholesaler->fresh(),
            'message' => "تم فك ربط الموزّع «".($wholesaler->brand_name ?: $wholesaler->name)."» من شبكتك",
        ];
    }
}
