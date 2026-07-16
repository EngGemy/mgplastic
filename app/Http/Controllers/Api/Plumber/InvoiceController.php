<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Http\Resources\Plumber\ReceivedDistributionResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\SystemLabel;
use App\Models\WalletAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $invoices = Invoice::where('plumber_id', $user->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 200,
            'data'   => $invoices,
        ]);
    }

    public function store(Request $request)
    {
        // Only the image file is required; everything else nullable
        $v = Validator::make($request->all(), [
            'vendor_store_id' => 'nullable|exists:plumber_stores,id',
            'subtotal_cents'  => 'nullable|integer',
            'tax_cents'       => 'nullable|integer',
            'total_cents'     => 'nullable|integer',
            'currency'        => 'nullable|string|min:3', // set to nullable; remove “required”
            'number'          => 'nullable|string|max:64',
            // image required (JPG/PNG/WebP). If you also want PDF, change rule to: mimes:jpg,jpeg,png,webp,pdf
            'file'            => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 422, 'errors' => $v->errors()], 422);
        }

        $user = $request->user();
        $data = $v->validated();

        $invoice = DB::transaction(function () use ($user, $data, $request) {
            // store the required image
            $path = $request->file('file')->store('invoices/' . date('Y/m/d'), 'public');

            return Invoice::create([
                'plumber_id'      => $user->id,
                'vendor_store_id' => $data['vendor_store_id'] ?? null,

                // Use null-coalescing so missing keys don’t throw errors
                'subtotal_cents'  => $data['subtotal_cents']  ?? null,
                'tax_cents'       => $data['tax_cents']       ?? null,
                'total_cents'     => $data['total_cents']     ?? null,
                'currency'        => $data['currency']        ?? null,
                'number'          => $data['number']          ?? null,

                'attachment_path' => $path,
                'status'          => 'pending_review',
            ]);
        });

        // If you want to return a public URL for the image:
        $invoice->attachment_url = Storage::disk('public')->url($invoice->attachment_path);

        return response()->json([
            'status'  => 201,
            'message' => __('Invoice submitted, pending review'),
            'data'    => $invoice,
        ], 201);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if ($invoice->plumber_id !== $user->id) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }

        // include url convenience if you like
        $invoice->attachment_url = $invoice->attachment_path
            ? Storage::disk('public')->url($invoice->attachment_path)
            : null;

        return response()->json(['status' => 200, 'data' => $invoice]);
    }

    public function received(Request $request): JsonResponse
    {
        $user = $request->user();

        $distributions = InvoiceDistribution::where('to_user_id', $user->id)
            ->where('tier', 3)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->with([
                'invoice:id,number,status,attachment_path,approved_at',
                'fromUser:id,name,brand_name,phone,network_code,profile_photo',
                'items.invoiceItem.product.translations',
            ])
            ->latest()
            ->paginate(20)
            ->through(fn (InvoiceDistribution $distribution) => (new ReceivedDistributionResource($distribution))->resolve());

        $balancePoints = (int) (WalletAccount::query()
            ->where('owner_id', $user->id)
            ->where('currency', 'LYD')
            ->value('balance_points') ?? 0);

        return response()->json([
            'status' => 200,
            'data' => $distributions,
            'balance_points' => $balancePoints,
            'plumber_balance_points' => $balancePoints,
        ]);
    }

    public function distributionDetail(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        $user = $request->user();

        if ($distribution->to_user_id !== $user->id) {
            return response()->json(['status' => 403, 'message' => 'غير مصرح'], 403);
        }

        $distribution->load([
            'items.invoiceItem.product.translations',
            'fromUser:id,name,brand_name,phone,network_code,profile_photo',
            'invoice:id,number,status,attachment_path,approved_at',
        ]);

        $balancePoints = (int) (WalletAccount::query()
            ->where('owner_id', $user->id)
            ->where('currency', 'LYD')
            ->value('balance_points') ?? 0);

        return response()->json([
            'status' => 200,
            'data' => [
                'distribution' => new ReceivedDistributionResource($distribution),
                'points_earned' => $distribution->items->sum('points_value'),
                'balance_points' => $balancePoints,
                'plumber_balance_points' => $balancePoints,
                'status_label' => match ($distribution->status) {
                    'confirmed' => 'مؤكد — جارٍ معالجة النقاط',
                    'points_awarded' => 'تم إضافة النقاط للمحفظة',
                    default => $distribution->status,
                },
                'trader_name' => $distribution->fromUser?->brand_name ?: $distribution->fromUser?->name,
                'trader' => $distribution->fromUser ? [
                    'id' => $distribution->fromUser->id,
                    'name' => $distribution->fromUser->name,
                    'brand_name' => $distribution->fromUser->brand_name,
                    'display_name' => $distribution->fromUser->brand_name ?: $distribution->fromUser->name,
                    'phone' => $distribution->fromUser->phone,
                    'network_code' => $distribution->fromUser->network_code,
                    'profile_photo_url' => $distribution->fromUser->profile_photo_url,
                ] : null,
            ],
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user();

        $wallet = WalletAccount::where('owner_id', $user->id)->first();

        $recentTransactions = $wallet
            ? $wallet->transactions()->latest()->take(10)->get()
            : collect();

        return response()->json([
            'status' => 200,
            'data' => [
                'balance_points' => $wallet?->balance_points ?? 0,
                'balance_cents' => $wallet?->balance_cents ?? 0,
                'currency' => $wallet?->currency ?? 'LYD',
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }
}
