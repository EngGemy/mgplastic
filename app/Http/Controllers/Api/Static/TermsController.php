<?php

namespace App\Http\Controllers\Api\Static;

use App\Http\Controllers\Controller;
use App\Http\Resources\TermsResource;
use App\Models\TermsCondition;
use Illuminate\Http\Request;

class TermsController extends Controller
{
    public function show(Request $request)
    {
        $terms = TermsCondition::with('translations')->first();
        if (! $terms) {
            return response()->json([
                'status'  => false,
                'message' => 'Terms not found.',
            ], 404);
        }

        // For header only (resource also resolves language internally)
        $raw  = (string) $request->header('Accept-Language', 'en');
        $lang = substr(strtolower(str_replace('_','-',$raw)), 0, 2);
        if (! in_array($lang, ['ar','en'], true)) {
            $lang = 'en';
        }

        return response()
            ->json([
                'status'  => true,
                'message' => 'Terms retrieved successfully',
                'data'    => new TermsResource($terms),
            ])
            ->header('Content-Language', $lang);
    }
}
