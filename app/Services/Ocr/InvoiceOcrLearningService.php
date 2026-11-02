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

        $alias = OcrAlias::query()->firstOrNew([
            'normalized_text' => $normalized,
            'system_hint' => $hint,
            'customer_user_id' => $customerId,
        ]);
        $alias->product_id = $productId;
        $alias->hits = (int) $alias->hits + 1;
        $alias->last_used_at = now();
        $alias->save();
    }
}
