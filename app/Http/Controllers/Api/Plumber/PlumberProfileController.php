<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Models\PlumberWorkPhoto;
use App\Services\VideoThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PlumberProfileController extends Controller
{
    public function __construct(
        protected VideoThumbnailService $videoThumbnails,
    ) {}

    /**
     * Upload multiple images and/or videos at once.
     * Videos get an automatic professional cover thumbnail.
     *
     * Route: POST /api/v1/plumber/work-photos
     */
    public function addWorkMedia(Request $request)
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'plumber') {
            return response()->json(['status' => false, 'message' => 'Only plumbers can add work media'], 403);
        }

        try {
            $request->validate([
                'media'   => 'required|array|min:1',
                'media.*' => 'required|file|max:512000|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mkv,avi',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'errors' => $e->errors(),
            ], 422);
        }

        $disk           = 'public';
        $created        = [];
        $skipped        = [];
        $hasVideoColumn = Schema::hasColumn((new PlumberWorkPhoto)->getTable(), 'video_path');

        try {
            DB::transaction(function () use ($request, $user, $disk, $hasVideoColumn, &$created, &$skipped) {
                foreach ($request->file('media', []) as $file) {
                    $mime         = strtolower($file->getMimeType() ?? '');
                    $isImage      = str_starts_with($mime, 'image/');
                    $isVideo      = str_starts_with($mime, 'video/');
                    $originalName = $file->getClientOriginalName();

                    if ($isImage) {
                        try {
                            $path = $file->store('work_photos/images', $disk);

                            $row = $user->workPhotos()->create([
                                'image'      => $path,
                                'video_path' => null,
                            ])->fresh();

                            $created[] = $this->formatWorkMediaRow($row, $disk);
                        } catch (\Throwable $e) {
                            Log::error('[WorkMedia] Failed to store image', [
                                'filename'  => $originalName,
                                'exception' => $e->getMessage(),
                            ]);

                            $skipped[] = [
                                'filename' => $originalName,
                                'reason'   => 'Exception: '.$e->getMessage(),
                            ];
                        }

                        continue;
                    }

                    if ($isVideo) {
                        if (! $hasVideoColumn) {
                            $skipped[] = [
                                'filename' => $originalName,
                                'reason'   => 'video_path column not found on plumber_work_photos (run migration to add it)',
                            ];
                            continue;
                        }

                        try {
                            $videoPath = $file->store('work_photos/videos', $disk);
                            $thumbPath = $this->videoThumbnails->generate($videoPath, 'work_photos/thumbnails');

                            $row = $user->workPhotos()->create([
                                'image'      => $thumbPath,
                                'video_path' => $videoPath,
                            ])->fresh();

                            $created[] = $this->formatWorkMediaRow($row, $disk);
                        } catch (\Throwable $e) {
                            Log::error('[WorkMedia] Failed to store video', [
                                'filename'  => $originalName,
                                'exception' => $e->getMessage(),
                            ]);

                            $skipped[] = [
                                'filename' => $originalName,
                                'reason'   => 'Exception: '.$e->getMessage(),
                            ];
                        }

                        continue;
                    }

                    $skipped[] = [
                        'filename' => $originalName,
                        'reason'   => 'Unsupported MIME type: '.($mime ?: 'unknown'),
                    ];
                }
            });
        } catch (\Throwable $e) {
            Log::critical('[WorkMedia] Transaction failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error while processing media upload',
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Work media processed',
            'data'    => $created,
            'skipped' => $skipped,
        ], 201);
    }

    protected function formatWorkMediaRow(PlumberWorkPhoto $row, string $disk): array
    {
        if (! empty($row->video_path)) {
            return [
                'id'         => $row->id,
                'type'       => 'video',
                'path'       => $row->video_path,
                'url'        => Storage::disk($disk)->url($row->video_path),
                'thumbnail'  => $row->image ? Storage::disk($disk)->url($row->image) : null,
                'created_at' => optional($row->created_at)->toISOString(),
            ];
        }

        return [
            'id'         => $row->id,
            'type'       => 'image',
            'path'       => $row->image,
            'url'        => $row->image_url ?? Storage::disk($disk)->url($row->image),
            'thumbnail'  => $row->image_url ?? Storage::disk($disk)->url($row->image),
            'created_at' => optional($row->created_at)->toISOString(),
        ];
    }

    /**
     * Route: GET /api/v1/plumber/work-photos
     */
    public function listWorkPhotos()
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'plumber') {
            return response()->json(['status' => false, 'message' => 'Only plumbers can view work media'], 403);
        }

        $disk = 'public';
        $rows = $user->workPhotos()->latest()->get();

        $out = $rows->map(fn ($row) => $this->formatWorkMediaRow($row, $disk));

        return response()->json(['status' => true, 'data' => $out]);
    }

    /**
     * Route: DELETE /api/v1/plumber/work-photos/{id}
     */
    public function deleteWorkPhoto($id)
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'plumber') {
            return response()->json(['status' => false, 'message' => 'Only plumbers can delete work media'], 403);
        }

        $row = PlumberWorkPhoto::where('id', $id)
            ->where('plumber_id', $user->id)
            ->first();

        if (! $row) {
            return response()->json(['status' => false, 'message' => 'Media not found'], 404);
        }

        $disk = 'public';

        if (! empty($row->image)) {
            Storage::disk($disk)->delete($row->image);
        }

        if (Schema::hasColumn($row->getTable(), 'video_path') && ! empty($row->video_path)) {
            Storage::disk($disk)->delete($row->video_path);
        }

        $row->delete();

        return response()->json(['status' => true, 'message' => 'Media deleted successfully']);
    }

    /**
     * Route: POST /api/v1/plumber/update-profile
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'plumber') {
            return response()->json(['status' => false, 'message' => 'Only plumbers can update this info'], 403);
        }

        $request->validate([
            'about_me'          => 'nullable|string|max:500',
            'short_description' => 'nullable|string|max:255',
            'long_description'  => 'nullable|string',
            'video_url'         => 'nullable|url',
            'website'           => 'nullable|url|max:500',
            'profile_photo'     => 'nullable|image|max:2048',
            'address'           => 'nullable|string|max:500',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
        ]);

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }
            $user->profile_photo = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        foreach (['about_me', 'short_description', 'long_description', 'video_url', 'website', 'address', 'latitude', 'longitude'] as $field) {
            if ($request->has($field)) {
                $user->{$field} = $request->input($field);
            }
        }

        $user->save();

        $fresh = $user->fresh();

        return response()->json([
            'status'  => true,
            'message' => 'Profile updated successfully',
            'data'    => $fresh,
            'location' => [
                'latitude'  => $fresh->latitude,
                'longitude' => $fresh->longitude,
                'map_url'   => $fresh->mapUrl(),
                'website'   => $fresh->website,
            ],
        ]);
    }
}
