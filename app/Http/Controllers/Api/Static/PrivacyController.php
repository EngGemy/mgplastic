<?php

namespace App\Http\Controllers\Api\Static;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrivacyResource;
use App\Models\Privacy;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function show(Request $request)
    {
        $privacy = Privacy::first();

        if (! $privacy) {
            return response()->json([
                'status'  => false,
                'message' => 'Privacy policy not found',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Privacy policy loaded',
            'data'    => new PrivacyResource($privacy), // ← resource handles Accept-Language
        ]);
    }
}
