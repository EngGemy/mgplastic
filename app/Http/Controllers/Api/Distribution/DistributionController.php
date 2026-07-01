<?php

namespace App\Http\Controllers\Api\Distribution;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Services\DistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DistributionController extends Controller
{
    public function __construct(private DistributionService $distributionService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $distributions = InvoiceDistribution::query()
            ->where(fn ($q) => $q
                ->where('from_user_id', $user->id)
                ->orWhere('to_user_id', $user->id))
            ->with(['invoice', 'fromUser:id,name,role', 'toUser:id,name,role', 'items.invoiceItem.product'])
            ->latest()
            ->paginate(20);

        return response()->json(['status' => 200, 'data' => $distributions]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,id',
            'to_user_id' => 'required|exists:users,id',
            'tier' => 'required|integer|in:1,2,3',
            'parent_distribution_id' => 'nullable|exists:invoice_distributions,id',
            'items' => 'required|array|min:1',
            'items.*.invoice_item_id' => 'required|exists:invoice_items,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 422, 'errors' => $v->errors()], 422);
        }

        $data = $v->validated();

        try {
            $distribution = $this->distributionService->createDistribution(
                invoice: Invoice::findOrFail($data['invoice_id']),
                fromUser: $request->user(),
                toUser: User::findOrFail($data['to_user_id']),
                tier: $data['tier'],
                items: $data['items'],
                parentId: $data['parent_distribution_id'] ?? null,
            );

            return response()->json([
                'status' => 201,
                'message' => 'تم إنشاء التوزيع بنجاح',
                'data' => $distribution->load('items.invoiceItem.product'),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['status' => 422, 'message' => $e->getMessage()], 422);
        }
    }

    public function confirm(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if ($distribution->from_user_id !== $request->user()->id
            && $request->user()->role !== 'super_admin') {
            return response()->json(['status' => 403, 'message' => 'غير مصرح'], 403);
        }

        try {
            $this->distributionService->confirmDistribution($distribution);

            return response()->json([
                'status' => 200,
                'message' => $distribution->tier === 3 ? 'تم منح النقاط للسباك' : 'تم تأكيد التوزيع',
                'data' => $distribution->fresh(['items.invoiceItem.product', 'fromUser', 'toUser', 'invoice']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 422, 'message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        $user = $request->user();
        if ($distribution->from_user_id !== $user->id
            && $distribution->to_user_id !== $user->id
            && $user->role !== 'super_admin') {
            return response()->json(['status' => 403, 'message' => 'غير مصرح'], 403);
        }

        return response()->json([
            'status' => 200,
            'data' => $distribution->load(['items.invoiceItem.product', 'fromUser', 'toUser', 'invoice']),
        ]);
    }
}
