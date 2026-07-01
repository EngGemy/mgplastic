<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Http\Resources\Plumber\PlumberListResource;
use App\Http\Resources\Plumber\PlumberShowResource;
use App\Models\User;
use App\Models\PlumberWorkPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PlumberPublicController extends Controller
{
    /**
     * GET /api/v1/plumbers
     * Query params: search, country_id, city_id, per_page (default 15), page
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->approved()
            ->active()
            ->with([
                'city:id,country_id,name_en,name_ar',
                'country:id,name_en,name_ar',
            ])
            ->select([
                'id',
                'name',
                'profile_photo',
                'city_id',
                'country_id',
                'short_description',
            ]);

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$s}%");
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', (int) $request->input('country_id'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->input('city_id'));
        }

        $perPage   = max(1, min(100, (int) $request->input('per_page', 15)));
        $paginated = $query->latest('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'status'  => true,
            'message' => 'Plumbers list',
            'data'    => PlumberListResource::collection($paginated->items()),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/plumbers/{id}
     *
     * Returns:
     * - plumber base data (from PlumberShowResource)
     * - all work media (images + videos) with unified shape:
     *   [
     *     id, type, path, url, thumbnail, created_at
     *   ]
     */
    public function show(Request $request, $id)
    {
        $plumber = User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->approved()
            ->active()
            ->with([
                'city:id,country_id,name_en,name_ar',
                'country:id,name_en,name_ar',
                'workPhotos' => function ($q) {
                    $q->latest();
                },
            ])
            ->find($id);

        if (! $plumber) {
            return response()->json([
                'status'  => false,
                'message' => 'Plumber not found',
            ], 404);
        }

        $disk           = 'public';
        $hasVideoColumn = Schema::hasColumn((new PlumberWorkPhoto)->getTable(), 'video_path');

        // نحول أعمال السباك (صور + فيديوهات) لشكل موحّد مع thumbnail
        $workMedia = $plumber->workPhotos->map(function (PlumberWorkPhoto $p) use ($disk, $hasVideoColumn) {
            // صورة فقط (بدون فيديو)
            if (! empty($p->image) && (empty($p->video_path) || ! $hasVideoColumn)) {
                $url = $p->image_url ?? Storage::disk($disk)->url($p->image);

                return [
                    'id'         => $p->id,
                    'type'       => 'image',
                    'path'       => $p->image,
                    'url'        => $url,
                    'thumbnail'  => $url,
                    'created_at' => optional($p->created_at)->toISOString(),
                ];
            }

            // فيديو: الفيديو في video_path، والـ thumbnail (لو موجودة) في image
            if ($hasVideoColumn && ! empty($p->video_path)) {
                $videoUrl = Storage::disk($disk)->url($p->video_path);
                $thumbUrl = null;

                if (! empty($p->image)) {
                    $thumbUrl = $p->image_url ?? Storage::disk($disk)->url($p->image);
                }

                return [
                    'id'         => $p->id,
                    'type'       => 'video',
                    'path'       => $p->video_path,
                    'url'        => $videoUrl,
                    'thumbnail'  => $thumbUrl,
                    'created_at' => optional($p->created_at)->toISOString(),
                ];
            }

            // حالة غير معروفة (لا صورة ولا فيديو)
            return [
                'id'         => $p->id,
                'type'       => 'unknown',
                'path'       => null,
                'url'        => null,
                'thumbnail'  => null,
                'created_at' => optional($p->created_at)->toISOString(),
            ];
        });

        // احصائيات بسيطة
        $imagesCount = $workMedia->where('type', 'image')->count();
        $videosCount = $workMedia->where('type', 'video')->count();

        // نجمع بيانات PlumberShowResource مع أعماله
        $plumberData = array_merge(
            (new PlumberShowResource($plumber))->toArray($request),
            [
                'work_media'        => $workMedia,
                'work_media_count'  => $workMedia->count(),
                'work_images_count' => $imagesCount,
                'work_videos_count' => $videosCount,
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Plumber details',
            'data'    => $plumberData,
        ]);
    }
}
