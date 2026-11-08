<?php

namespace App\Http\Controllers\Api\Static;

use App\Http\Controllers\Controller;
use App\Http\Requests\Static\ClaimRequest;
use App\Models\Claim;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    public function store(ClaimRequest $request)
    {
        $claim = Claim::create($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Your claim has been submitted.',
            'data' => $claim,
        ]);
    }

    /** Admin-only — never expose claimant PII to guests or regular users. */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->isAdminUser()) {
            return response()->json(['status' => false, 'message' => 'غير مصرح'], 403);
        }

        $claims = Claim::latest()->paginate(50);

        return response()->json([
            'status' => true,
            'message' => 'List of all submitted claims',
            'data' => $claims->items(),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
            ],
        ]);
    }
}
