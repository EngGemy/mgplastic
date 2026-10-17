<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\HandlesInvoiceReturnApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceReturnResource;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Services\InvoiceReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plumber returns on retail→plumber sales (tier 3 distributions where they are the buyer).
 */
class InvoiceReturnController extends Controller
{
    use HandlesInvoiceReturnApi;

    public function __construct(protected InvoiceReturnService $returnService) {}

    protected function returns(): InvoiceReturnService
    {
        return $this->returnService;
    }

    /** Distributions the plumber can inspect for returns. */
    public function distributions(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('to_user_id', $user->id)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->with([
                'fromUser:id,name,brand_name,phone',
                'items.invoiceItem.product.translations',
            ])
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (InvoiceDistribution $d) {
                $gate = $this->returns()->previewReturnGate($d);
                $lines = $this->returns()->returnableLines($d);

                return [
                    'distribution_id' => (int) $d->id,
                    'tier' => 3,
                    'status' => $d->status,
                    'retail_trader' => [
                        'id' => $d->fromUser?->id,
                        'name' => $d->fromUser?->brand_name ?: $d->fromUser?->name,
                        'phone' => $d->fromUser?->phone,
                    ],
                    'sold_quantity' => (int) $d->items->sum('quantity'),
                    'returned_quantity' => (int) $d->items->sum(fn ($i) => (int) ($i->returned_quantity ?? 0)),
                    'returnable_quantity' => (int) collect($lines)->sum('returnable'),
                    'returnable_points_preview' => $this->returns()->maxReturnablePoints($d),
                    'can_return' => (bool) ($gate['can_return'] ?? false),
                    'block_reason' => $gate['block_reason'] ?? null,
                    'confirmed_at' => optional($d->confirmed_at)->toIso8601String(),
                    'created_at' => optional($d->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $this->success(['items' => $rows], 'قائمة توزيعات المرتجع');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $returns = InvoiceReturn::query()
            ->where('tier', 3)
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
            })
            ->with(['items.product.translations', 'fromUser:id,name,brand_name', 'toUser:id,name,brand_name'])
            ->latest()
            ->limit(100)
            ->get();

        return $this->success(InvoiceReturnResource::collection($returns), 'قائمة المرتجعات');
    }

    public function previewDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if ((int) $distribution->to_user_id !== (int) $request->user()->id
            && ! in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح بعرض مرتجع هذا التوزيع', 403);
        }

        if ((int) $distribution->tier !== 3) {
            return $this->error('هذا المسار لمرتجع القطاعي→سباك فقط', 422);
        }

        $distribution->loadMissing(['items.invoiceItem.product', 'invoice']);

        return $this->respondReturnPreview($distribution, $request->user());
    }

    public function storeDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if ((int) $distribution->to_user_id !== (int) $request->user()->id
            && ! in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح بعمل مرتجع على هذا التوزيع', 403);
        }

        if ((int) $distribution->tier !== 3) {
            return $this->error('هذا المسار لمرتجع القطاعي→سباك فقط', 422);
        }

        $payload = $this->validatedReturnPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->runReturn(
            fn () => $this->returns()->returnPlumberDistribution(
                $distribution,
                $payload['lines'],
                $request->user(),
                $payload['note'],
            ),
            'تم تسجيل مرتجع السباك→القطاعي بنجاح',
        );
    }

    public function show(Request $request, InvoiceReturn $return): JsonResponse
    {
        $user = $request->user();
        if ((int) $return->from_user_id !== (int) $user->id
            && (int) $return->to_user_id !== (int) $user->id
            && ! in_array($user->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح', 403);
        }

        if ((int) $return->tier !== 3) {
            return $this->error('غير مصرّح', 403);
        }

        return $this->success(
            new InvoiceReturnResource($return->load(['items.product.translations', 'fromUser', 'toUser'])),
            'تفاصيل المرتجع',
        );
    }
}
