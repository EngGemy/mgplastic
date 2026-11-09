<?php

namespace App\Services\Ocr;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * OCR session context — POS sale vs supply order from a supplier.
 *
 * @phpstan-type ContextArray array{
 *   purpose:string,
 *   source:?string,
 *   supplier_user_id:?int,
 *   customer_user_id:?int
 * }
 */
class OcrBatchContext
{
    public const PURPOSE_POS = 'pos';

    public const PURPOSE_SUPPLY = 'supply_order';

    public const SOURCE_FACTORY = 'factory';

    public const SOURCE_WAREHOUSE = 'warehouse';

    public const SOURCE_WHOLESALE = 'wholesale';

    public const SOURCE_SHOP = 'shop';

    public function __construct(
        public readonly string $purpose,
        public readonly ?string $source = null,
        public readonly ?int $supplierUserId = null,
        public readonly ?int $customerUserId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromRequestInput(array $input, string $defaultPurpose = self::PURPOSE_POS): self
    {
        $purpose = (string) ($input['purpose'] ?? $defaultPurpose);
        if (! in_array($purpose, [self::PURPOSE_POS, self::PURPOSE_SUPPLY], true)) {
            throw ValidationException::withMessages(['purpose' => 'purpose يجب أن يكون pos أو supply_order']);
        }

        if ($purpose === self::PURPOSE_POS) {
            return new self(
                purpose: self::PURPOSE_POS,
                source: null,
                supplierUserId: null,
                customerUserId: isset($input['customer_user_id']) ? (int) $input['customer_user_id'] : null,
            );
        }

        $source = (string) ($input['source'] ?? '');
        if (! in_array($source, [self::SOURCE_FACTORY, self::SOURCE_WAREHOUSE, self::SOURCE_WHOLESALE, self::SOURCE_SHOP], true)) {
            throw ValidationException::withMessages(['source' => 'source مطلوب لطلب التوريد: factory|warehouse|wholesale|shop']);
        }

        $supplierId = null;
        if ($source === self::SOURCE_WAREHOUSE) {
            $supplierId = isset($input['warehouse_id']) ? (int) $input['warehouse_id'] : (isset($input['supplier_user_id']) ? (int) $input['supplier_user_id'] : null);
            if (! $supplierId) {
                throw ValidationException::withMessages(['warehouse_id' => 'warehouse_id مطلوب عند source=warehouse']);
            }
        } elseif ($source === self::SOURCE_WHOLESALE) {
            $supplierId = isset($input['wholesaler_id']) ? (int) $input['wholesaler_id']
                : (isset($input['wholesale_distributor_id']) ? (int) $input['wholesale_distributor_id']
                : (isset($input['supplier_id']) ? (int) $input['supplier_id']
                : (isset($input['supplier_user_id']) ? (int) $input['supplier_user_id'] : null)));
            if (! $supplierId) {
                throw ValidationException::withMessages(['wholesaler_id' => 'wholesaler_id مطلوب عند source=wholesale']);
            }
        } elseif ($source === self::SOURCE_SHOP) {
            $supplierId = isset($input['retail_trader_id']) ? (int) $input['retail_trader_id']
                : (isset($input['supplier_user_id']) ? (int) $input['supplier_user_id'] : null);
            if (! $supplierId) {
                throw ValidationException::withMessages(['retail_trader_id' => 'retail_trader_id مطلوب عند source=shop']);
            }
        }

        return new self(
            purpose: self::PURPOSE_SUPPLY,
            source: $source,
            supplierUserId: $supplierId,
            customerUserId: null,
        );
    }

    /**
     * Filter for GET /current — partial match on query params.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromCurrentQuery(array $query, string $defaultPurpose = self::PURPOSE_POS): self
    {
        $purpose = (string) ($query['purpose'] ?? $defaultPurpose);
        if ($purpose === self::PURPOSE_POS) {
            return new self(purpose: self::PURPOSE_POS);
        }

        $source = isset($query['source']) ? (string) $query['source'] : null;
        if (! $source || ! in_array($source, [self::SOURCE_FACTORY, self::SOURCE_WAREHOUSE, self::SOURCE_WHOLESALE, self::SOURCE_SHOP], true)) {
            throw ValidationException::withMessages([
                'source' => 'source مطلوب لـ /current عند طلب التوريد: factory|warehouse|wholesale|shop',
            ]);
        }

        $supplierId = null;
        if ($source === self::SOURCE_WAREHOUSE) {
            $supplierId = ! empty($query['warehouse_id']) ? (int) $query['warehouse_id']
                : (! empty($query['supplier_user_id']) ? (int) $query['supplier_user_id'] : null);
            if (! $supplierId) {
                throw ValidationException::withMessages(['warehouse_id' => 'warehouse_id مطلوب عند source=warehouse']);
            }
        } elseif ($source === self::SOURCE_WHOLESALE) {
            $supplierId = (int) ($query['wholesaler_id'] ?? $query['wholesale_distributor_id'] ?? $query['supplier_id'] ?? $query['supplier_user_id'] ?? 0) ?: null;
            if (! $supplierId) {
                throw ValidationException::withMessages(['wholesaler_id' => 'wholesaler_id مطلوب عند source=wholesale']);
            }
        } elseif ($source === self::SOURCE_SHOP) {
            $supplierId = ! empty($query['retail_trader_id']) ? (int) $query['retail_trader_id']
                : (! empty($query['supplier_user_id']) ? (int) $query['supplier_user_id'] : null);
            if (! $supplierId) {
                throw ValidationException::withMessages(['retail_trader_id' => 'retail_trader_id مطلوب عند source=shop']);
            }
        }

        return new self(
            purpose: self::PURPOSE_SUPPLY,
            source: $source,
            supplierUserId: $supplierId,
        );
    }

    public function assertAllowedFor(User $user): void
    {
        if ($this->purpose === self::PURPOSE_POS) {
            if ($user->isPlumber()) {
                throw ValidationException::withMessages(['purpose' => 'السباك لا يستخدم OCR لنقطة البيع — استخدم supply_order']);
            }

            return;
        }

        // supply_order role × source matrix
        match ($this->source) {
            self::SOURCE_FACTORY => $this->requireWholesale($user, 'factory'),
            self::SOURCE_WAREHOUSE => $this->requireWholesale($user, 'warehouse'),
            self::SOURCE_WHOLESALE => $this->requireRetail($user, 'wholesale'),
            self::SOURCE_SHOP => $this->requirePlumber($user, 'shop'),
            default => throw ValidationException::withMessages(['source' => 'source غير صالح']),
        };

        if ($this->supplierUserId) {
            $supplier = User::query()->find($this->supplierUserId);
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_user_id' => 'المورّد غير موجود']);
            }
            if ($this->source === self::SOURCE_WAREHOUSE && ! $supplier->isWholesaleDistributor()) {
                throw ValidationException::withMessages(['warehouse_id' => 'المخزن غير صالح']);
            }
            if ($this->source === self::SOURCE_WHOLESALE && ! $supplier->isWholesaleDistributor()) {
                throw ValidationException::withMessages(['wholesaler_id' => 'الموزّع غير صالح']);
            }
            if ($this->source === self::SOURCE_SHOP && ! $supplier->isRetailTrader()) {
                throw ValidationException::withMessages(['retail_trader_id' => 'المحل غير صالح']);
            }
        }
    }

    /**
     * @return ContextArray
     */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose,
            'source' => $this->source,
            'supplier_user_id' => $this->supplierUserId,
            'customer_user_id' => $this->customerUserId,
        ];
    }

    public function stockSourceLabel(): string
    {
        if ($this->purpose === self::PURPOSE_POS) {
            return 'seller_pos';
        }

        return match ($this->source) {
            self::SOURCE_FACTORY => 'factory_catalog',
            self::SOURCE_WAREHOUSE => 'warehouse_stock',
            self::SOURCE_WHOLESALE => 'wholesaler_stock',
            self::SOURCE_SHOP => 'shop_stock',
            default => 'unknown',
        };
    }

    /** Supply orders keep all approved lines in cart_lines (flags only). */
    public function splitUnavailableOnApply(): bool
    {
        return $this->purpose === self::PURPOSE_POS;
    }

    /** Factory catalog has no stock gate. */
    public function ignoreStock(): bool
    {
        return $this->purpose === self::PURPOSE_SUPPLY && $this->source === self::SOURCE_FACTORY;
    }

    protected function requireWholesale(User $user, string $label): void
    {
        if (! $user->isWholesaleDistributor()) {
            throw ValidationException::withMessages(['source' => "source={$label} متاح لموزّع الجملة فقط"]);
        }
    }

    protected function requireRetail(User $user, string $label): void
    {
        if (! $user->isRetailTrader()) {
            throw ValidationException::withMessages(['source' => "source={$label} متاح للقطاعي فقط"]);
        }
    }

    protected function requirePlumber(User $user, string $label): void
    {
        if (! $user->isPlumber()) {
            throw ValidationException::withMessages(['source' => "source={$label} متاح للسباك فقط"]);
        }
    }
}
