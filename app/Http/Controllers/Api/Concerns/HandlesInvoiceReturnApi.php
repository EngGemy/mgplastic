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

        return $this->success([
            'distribution_id' => (int) $distribution->id,
            'tier' => (int) $distribution->tier,
            'status' => $distribution->status,
            'lines' => $lines,
            'returnable_points_preview' => $this->returns()->maxReturnablePoints($distribution),
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
}
