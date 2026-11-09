<?php

namespace App\Services\Ocr;

use App\Models\OcrAlias;
use App\Models\OcrCorrection;
use App\Models\User;
use App\Support\ArabicTextNormalizer;

class InvoiceOcrLearningService
{
    public function rememberCorrection(
        string $imageSha256,
        string $rawText,
        ?int $productId,
        ?float $qty,
        ?User $user = null,
        ?string $systemHint = null,
        ?int $customerUserId = null,
        bool $rememberForCustomer = false,
    ): void {
        $normalized = ArabicTextNormalizer::normalize($rawText);
        if ($normalized === '' || ! $productId) {
            return;
        }

        OcrCorrection::query()->create([
            'image_sha256' => $imageSha256,
            'raw_text' => mb_substr($rawText, 0, 500),
            'product_id' => $productId,
            'qty' => $qty,
            'user_id' => $user?->id,
        ]);

        $hint = $systemHint ?? '';
        $customerId = $rememberForCustomer ? $customerUserId : null;

        // User-level alias first (corrections from select_product / set_unit / confirm).
        if ($user) {
            $userAlias = OcrAlias::query()->firstOrNew([
                'normalized_text' => $normalized,
                'system_hint' => $hint,
                'owner_user_id' => $user->id,
                'scope' => 'user',
            ]);
            $userAlias->product_id = $productId;
            $userAlias->customer_user_id = $customerId;
            $userAlias->hits = (int) $userAlias->hits + 1;
            $userAlias->last_used_at = now();
            $userAlias->save();
            $this->maybePromoteGlobal($normalized, $hint, $productId);
        }

        $alias = OcrAlias::query()->firstOrNew([
            'normalized_text' => $normalized,
            'system_hint' => $hint,
            'customer_user_id' => $customerId,
            'owner_user_id' => null,
            'scope' => 'global',
        ]);
        if (! $alias->exists || (int) $alias->product_id === (int) $productId) {
            $alias->product_id = $productId;
            $alias->hits = (int) $alias->hits + 1;
            $alias->last_used_at = now();
            if (! $alias->scope) {
                $alias->scope = 'global';
            }
            $alias->save();
        }

        $product = \App\Models\Product::query()->find($productId);
        if ($product) {
            app(OcrTermAliasService::class)->learnFromCorrection($rawText, $product, $user);
        }
    }

    public function rememberUnit(string $rawText, string $qtyUnit, ?User $user = null): void
    {
        $normalized = ArabicTextNormalizer::normalize($rawText);
        if ($normalized === '' || ! $user || ! in_array($qtyUnit, ['piece', 'box'], true)) {
            return;
        }

        $alias = OcrAlias::query()->firstOrNew([
            'normalized_text' => $normalized,
            'system_hint' => '',
            'owner_user_id' => $user->id,
            'scope' => 'user',
        ]);
        $alias->qty_unit = $qtyUnit;
        $alias->hits = (int) $alias->hits + 1;
        $alias->last_used_at = now();
        if (! $alias->product_id) {
            // keep nullable product for unit-only memory when schema allows — skip if constrained
            $existingProduct = OcrAlias::query()
                ->where('normalized_text', $normalized)
                ->whereNotNull('product_id')
                ->value('product_id');
            if ($existingProduct) {
                $alias->product_id = (int) $existingProduct;
            } else {
                return;
            }
        }
        $alias->save();
    }

    protected function maybePromoteGlobal(string $normalized, string $hint, int $productId): void
    {
        $minUsers = max(2, (int) config('ocr.alias_promote_min_users', 3));
        $users = OcrAlias::query()
            ->where('normalized_text', $normalized)
            ->where('system_hint', $hint)
            ->where('scope', 'user')
            ->where('product_id', $productId)
            ->whereNotNull('owner_user_id')
            ->distinct('owner_user_id')
            ->count('owner_user_id');

        if ($users < $minUsers) {
            return;
        }

        $global = OcrAlias::query()->firstOrNew([
            'normalized_text' => $normalized,
            'system_hint' => $hint,
            'customer_user_id' => null,
            'owner_user_id' => null,
            'scope' => 'global',
        ]);
        $global->product_id = $productId;
        $global->confirming_users = $users;
        $global->hits = max((int) $global->hits, $users);
        $global->last_used_at = now();
        $global->save();
    }
}
