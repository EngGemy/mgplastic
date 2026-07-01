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
        $request->validate([
            'category_id'    => 'required|exists:event_categories,id',
            'city_id'        => 'required|exists:cities,id',
            'image'          => 'nullable|image|max:2048',
            'event_date'     => 'required|date',
            'event_time'     => 'required|date_format:H:i',
            'address'        => 'required|string|max:255',   // ← added
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            'title_en'       => 'required|string|max:255',
            'description_en' => 'nullable|string',
            'title_ar'       => 'required|string|max:255',
            'description_ar' => 'nullable|string',
        ]);

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('events', 'public')
            : null;

        $event = Event::create([
            'category_id' => $request->category_id,
            'city_id'     => $request->city_id,
            'image'       => $imagePath,
            'address'     => $request->address,   // ← added
            'event_date'  => $request->event_date,
            'event_time'  => $request->event_time,
            'latitude'    => $request->latitude,
            'longitude'   => $request->longitude,
            'en' => [
                'title'       => $request->title_en,
                'description' => $request->description_en,
            ],
            'ar' => [
                'title'       => $request->title_ar,
                'description' => $request->description_ar,
            ],
        ])->load(['category', 'city']);

        return response()->json([
            'status'  => true,
            'message' => 'Event created',
            'data'    => (new EventResource($event)),
        ], 201);
    }

    public function index(Request $request)
    {
        $perPage = (int) ($request->query('per_page', 10));
        $events  = Event::with(['category', 'city'])
            ->latest('id')
            ->paginate($perPage);

        // Laravel will include links & meta for pagination automatically.
        return EventResource::collection($events)
            ->additional(['status' => true]);
    }

    public function show($id)
    {
        $event = Event::with(['category', 'city'])->find($id);
        if (!$event) {
            return response()->json(['status' => false, 'message' => 'Event not found'], 404);
        }
        return response()->json([
            'status' => true,
            'data'   => (new EventResource($event)),
        ]);
    }
}
