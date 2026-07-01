<?php

namespace App\Http\Controllers\Api\Static;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SliderController extends Controller
{
    /** Format a slider payload */
    protected function format(Slider $s): array
    {
        // Prefer Storage::url so it respects disk config
        $url = $s->image ? Storage::disk('public')->url($s->image) : null;

        return [
            'id'    => $s->id,
            'type'  => $s->type,           // 'home' | 'store'
            'image' => $url,               // full URL
            'path'  => $s->image,          // stored path (optional)
        ];
    }

    /** GET /api/sliders  (optionally filter by ?type=home|store) */
    public function index(Request $request)
    {
        $type = $request->query('type');

        $query = Slider::query();
        if ($type) {
            $query->where('type', $type);
        }

        $sliders = $query->latest('id')->get()->map(fn ($s) => $this->format($s));

        return response()->json([
            'status'  => true,
            'message' => 'Slider list',
            'data'    => $sliders,
        ]);
    }

    /** GET /api/sliders/home */
    public function home()
    {
        $sliders = Slider::where('type', 'home')
            ->latest('id')
            ->get()
            ->map(fn ($s) => $this->format($s));

        return response()->json([
            'status'  => true,
            'message' => 'Home sliders',
            'data'    => $sliders,
        ]);
    }

    /** GET /api/sliders/store */
    public function storeOnly()
    {
        $sliders = Slider::where('type', 'store')
            ->latest('id')
            ->get()
            ->map(fn ($s) => $this->format($s));

        return response()->json([
            'status'  => true,
            'message' => 'Store sliders',
            'data'    => $sliders,
        ]);
    }

    /** POST /api/sliders  (multipart/form-data: image, type=home|store) */
    public function store(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:2048'],
            'type'  => ['required', Rule::in(['home', 'store'])],
        ]);

        $path = $request->file('image')->store('sliders', 'public');

        $slider = Slider::create([
            'image' => $path,
            'type'  => $request->string('type'),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Slider added successfully',
            'data'    => $this->format($slider),
        ], 201);
    }
}
