<?php

// app/Http/Controllers/Api/Event/EventController.php

namespace App\Http\Controllers\Api\Event;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->isAdminUser()) {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-c5ed3b.log'), json_encode([
                'sessionId' => 'c5ed3b',
                'hypothesisId' => 'H-event',
                'location' => 'EventController::store',
                'message' => 'event write denied',
                'data' => ['user_id' => $user?->id, 'role' => $user?->role],
                'timestamp' => (int) (microtime(true) * 1000),
                'runId' => 'post-fix',
            ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
            // #endregion

            return response()->json(['status' => false, 'message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'category_id' => 'required|exists:event_categories,id',
            'city_id' => 'nullable|exists:cities,id',
            'location_city' => 'required|string|max:120',
            'location_country' => 'required|string|max:120',
            'image' => 'nullable|image|max:2048',
            'event_date' => 'required|date',
            'event_time' => 'required|date_format:H:i',
            'address' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'title_en' => 'required|string|max:255',
            'description_en' => 'nullable|string',
            'title_ar' => 'required|string|max:255',
            'description_ar' => 'nullable|string',
        ]);

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('events', 'public')
            : null;

        $event = Event::create([
            'category_id' => $request->category_id,
            'city_id' => $request->city_id,
            'location_city' => $request->location_city,
            'location_country' => $request->location_country,
            'image' => $imagePath,
            'address' => $request->address,
            'event_date' => $request->event_date,
            'event_time' => $request->event_time,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'en' => [
                'title' => $request->title_en,
                'description' => $request->description_en,
            ],
            'ar' => [
                'title' => $request->title_ar,
                'description' => $request->description_ar,
            ],
        ])->load(['category', 'city.country']);

        return response()->json([
            'status' => true,
            'message' => 'Event created',
            'data' => (new EventResource($event)),
        ], 201);
    }

    public function index(Request $request)
    {
        $perPage = (int) ($request->query('per_page', 10));
        $events = Event::with(['category', 'city.country'])
            ->latest('id')
            ->paginate($perPage);

        // Laravel will include links & meta for pagination automatically.
        return EventResource::collection($events)
            ->additional(['status' => true]);
    }

    public function show($id)
    {
        $event = Event::with(['category', 'city.country'])->find($id);
        if (! $event) {
            return response()->json(['status' => false, 'message' => 'Event not found'], 404);
        }

        return response()->json([
            'status' => true,
            'data' => (new EventResource($event)),
        ]);
    }
}
