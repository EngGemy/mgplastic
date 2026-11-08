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
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-c5ed3b.log'), json_encode([
                'sessionId' => 'c5ed3b',
                'hypothesisId' => 'H-claims',
                'location' => 'ClaimController::index',
                'message' => 'claims list denied',
                'data' => ['user_id' => $user?->id, 'role' => $user?->role],
                'timestamp' => (int) (microtime(true) * 1000),
                'runId' => 'post-fix',
            ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
            // #endregion

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
