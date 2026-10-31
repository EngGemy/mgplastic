<?php

namespace App\Http\Controllers\Api\Static;


use App\Http\Controllers\Controller;
use App\Http\Requests\Static\ClaimRequest;
use App\Models\Claim;

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

    public function index()
    {
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
