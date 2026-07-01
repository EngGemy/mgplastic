<?php

namespace App\Http\Controllers\Api\Location;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\Request;

class CityController extends Controller
{
    // GET /api/v1/cities  (optional ?country_id=1)
    public function index(Request $request)
    {
        $nameCol = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        $query = City::query()->select('id', 'country_id', $nameCol . ' as name', 'name_en', 'name_ar');

        if ($request->filled('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }

        $cities = $query->orderBy($nameCol)->get();

        return response()->json([
            'status' => true,
            'data'   => $cities,
        ]);
    }

    // GET /api/v1/cities/{id}
    public function show($id)
    {
        $nameCol = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        $city = City::query()
            ->select('id', 'country_id', $nameCol . ' as name', 'name_en', 'name_ar')
            ->find($id);

        if (! $city) {
            return response()->json(['status' => false, 'message' => 'City not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $city]);
    }
}
