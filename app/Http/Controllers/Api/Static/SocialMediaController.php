<?php

namespace App\Http\Controllers\Api\Static;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialMediaResource;
use App\Models\SocialMedia;
use Illuminate\Http\Request;

class SocialMediaController extends Controller
{
    public function index(Request $request)
    {
        $items = SocialMedia::query()
            ->when(method_exists(SocialMedia::class, 'translations'), fn ($q) => $q->with('translations'))
            ->get();

        // For response header
        $raw  = (string) $request->header('Accept-Language', 'en');
        $lang = substr(strtolower(str_replace('_','-',$raw)), 0, 2);
        if (! in_array($lang, ['ar','en'], true)) {
            $lang = 'en';
        }

        return response()
            ->json([
                'status'  => true,
                'message' => 'Social links',
                'data'    => SocialMediaResource::collection($items),
            ])
            ->header('Content-Language', $lang);
    }
}
