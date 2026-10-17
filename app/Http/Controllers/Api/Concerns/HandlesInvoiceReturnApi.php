<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Resources\InvoiceReturnResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Models\User;
use App\Services\InvoiceReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait HandlesInvoiceReturnApi
{
    use ApiResponds;

    abstract protected function returns(): InvoiceReturnService;

    protected function respondReturnPreview(InvoiceDistribution $distribution, ?User $actor = null): JsonResponse
    {
        $lines = $this->returns()->returnableLines($distribution);
        $gate = $this->returns()->previewReturnGate($distribution);

        $canReturn = (bool) ($gate['can_return'] ?? false);
        $blockReason = $gate['block_reason'] ?? null;

        if ($actor && ! $this->actorOwnsDistributionSide($distribution, $actor)) {
            $canReturn = false;
            $blockReason = 'غير مصرّح لك بعمل مرتجع على هذا التوزيع';
        }

        $tier = (int) $distribution->tier;
        $productPoints = $this->returns()->maxReturnablePoints($distribution);
        $affectsWallet = $tier === 3;

        return $this->success([
            'id' => (int) $distribution->id,
            'distribution_id' => (int) $distribution->id,
            'tier' => $tier,
            'status' => $distribution->status,
            'lines' => $lines,
            // Tier 2 never moves wallet points — preview is 0 so apps don't show a clawback.
            'returnable_points_preview' => $affectsWallet ? $productPoints : 0,
            'product_points_preview' => $productPoints,
            'affects_wallet_points' => $affectsWallet,
            'can_return' => $canReturn,
            'block_reason' => $blockReason,
        ], 'معاينة المرتجع');
    }

    /**
     * @param  callable(): InvoiceReturn  $fn
     */
    protected function runReturn(callable $fn, string $successMessage): JsonResponse
    {
        try {
            $return = $fn();

            return $this->created(
                new InvoiceReturnResource($return->load(['items.product.translations', 'fromUser', 'toUser'])),
                $successMessage,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * @param  callable(): InvoiceReturn  $fn
     */
    protected function runReturnDecision(callable $fn, string $successMessage): JsonResponse
    {
        try {
            $return = $fn();

            return $this->success(
                new InvoiceReturnResource($return->load(['items.product.translations', 'fromUser', 'toUser'])),
                $successMessage,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    protected function assertCanViewReturn(InvoiceReturn $return, $user): ?JsonResponse
    {
        if ((int) $return->from_user_id !== (int) $user->id
            && (int) $return->to_user_id !== (int) $user->id
            && ! in_array($user->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح', 403);
        }

        return null;
    }

    /**
     * @return array{lines: array<int, array{invoice_item_id:int, quantity:int}>, note:?string}|JsonResponse
     */
    protected function validatedReturnPayload(Request $request): array|JsonResponse
    {
        $v = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'lines' => 'بنود المرتجع',
            'lines.*.invoice_item_id' => 'معرّف بند الفاتورة',
            'lines.*.quantity' => 'كمية المرتجع',
            'note' => 'الملاحظة',
        ]);

        if ($v->fails()) {
            return $this->error('بيانات المرتجع غير صالحة', 422, $v->errors());
        }

        return [
            'lines' => $request->input('lines'),
            'note' => $request->input('note'),
        ];
    }

    protected function actorOwnsDistributionSide(InvoiceDistribution $distribution, User $user): bool
    {
        return (int) $distribution->from_user_id === (int) $user->id
            || (int) $distribution->to_user_id === (int) $user->id
            || in_array($user->role, ['super_admin', 'admin'], true);
    }

    protected function actorOwnsInvoice(Invoice $invoice, User $user): bool
    {
        $invoice->loadMissing('sourceDistribution');

        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return true;
        }

        if ((int) ($invoice->wholesale_distributor_id ?? 0) === (int) $user->id) {
            return true;
        }

        if ((int) ($invoice->counterparty_user_id ?? 0) === (int) $user->id) {
            return true;
        }

        $dist = $invoice->sourceDistribution;

        return $dist ? $this->actorOwnsDistributionSide($dist, $user) : false;
    }

    /**
     * Compact row for return-picker lists (distributions).
     *
     * @param  'from'|'to'  $counterpartySide  Which side of the distribution is the "other party"
     * @return array<string, mixed>
     */
    protected function summarizeDistributionReturnRow(
        InvoiceDistribution $distribution,
        string $counterpartyKey = 'counterparty',
        string $counterpartySide = 'to',
    ): array {
        $distribution->loadMissing([
            'fromUser:id,name,brand_name,phone',
            'toUser:id,name,brand_name,phone',
            'items.invoiceItem.product.translations',
        ]);

        $gate = $this->returns()->previewReturnGate($distribution);
        $lines = $this->returns()->returnableLines($distribution);
        $other = $counterpartySide === 'from' ? $distribution->fromUser : $distribution->toUser;
        $tier = (int) $distribution->tier;
        $productPoints = $this->returns()->maxReturnablePoints($distribution);
        $affectsWallet = $tier === 3;
        $distributionId = (int) $distribution->id;

        return [
            // Use `id` with …/distributions/{id}/return-preview and …/returns
            'id' => $distributionId,
            'distribution_id' => $distributionId,
            'invoice_id' => $distribution->invoice_id ? (int) $distribution->invoice_id : null,
            'tier' => $tier,
            'status' => $distribution->status,
            $counterpartyKey => [
                'id' => $other?->id,
                'name' => $other?->brand_name ?: $other?->name,
                'phone' => $other?->phone,
            ],
            'sold_quantity' => (int) $distribution->items->sum('quantity'),
            'returned_quantity' => (int) $distribution->items->sum(fn ($i) => (int) ($i->returned_quantity ?? 0)),
            'returnable_quantity' => (int) collect($lines)->sum('returnable'),
            'returnable_points_preview' => $affectsWallet ? $productPoints : 0,
            'product_points_preview' => $productPoints,
            'affects_wallet_points' => $affectsWallet,
            'can_return' => (bool) ($gate['can_return'] ?? false),
            'block_reason' => $gate['block_reason'] ?? null,
            'confirmed_at' => optional($distribution->confirmed_at)->toIso8601String(),
            'created_at' => optional($distribution->created_at)->toIso8601String(),
        ];
    }

    /**
     * Compact row for return-picker lists (outgoing invoices → tier-2 source distribution).
     *
     * @return array<string, mixed>|null
     */
    protected function summarizeInvoiceReturnRow(
        Invoice $invoice,
        string $counterpartyKey = 'counterparty',
        string $counterpartySide = 'to',
    ): ?array {
        $invoice->loadMissing([
            'sourceDistribution.fromUser:id,name,brand_name,phone',
            'sourceDistribution.toUser:id,name,brand_name,phone',
            'sourceDistribution.items.invoiceItem.product.translations',
            'wholesaleDistributor:id,name,brand_name,phone',
            'counterparty:id,name,brand_name,phone',
        ]);

        $distribution = $invoice->sourceDistribution;
        if (! $distribution || (int) $distribution->tier !== 2) {
            return null;
        }

        $row = $this->summarizeDistributionReturnRow($distribution, $counterpartyKey, $counterpartySide);
        // For invoice lists, `id` is the invoice id expected by …/invoices/{id}/…
        $row['id'] = (int) $invoice->id;
        $row['invoice_id'] = (int) $invoice->id;
        $row['invoice_number'] = $invoice->number ?? null;
        $row['invoice_status'] = $invoice->status;
        $row['return_via'] = 'invoice'; // POST …/invoices/{invoice}/returns

        return $row;
    }
}
