<?php

namespace App\Http\Controllers\Api\Location;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\City;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    // GET /api/v1/countries
    public function index(Request $request)
    {
        $nameCol = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        $countries = Country::query()
            ->select('id', $nameCol . ' as name', 'name_en', 'name_ar')
            ->orderBy($nameCol)
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $countries,
        ]);
    }

    // GET /api/v1/countries/{id}
    public function show($id)
    {
        $nameCol = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        $country = Country::query()
            ->select('id', $nameCol . ' as name', 'name_en', 'name_ar')
            ->find($id);

        if (! $country) {
            return response()->json(['status' => false, 'message' => 'Country not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $country]);
    }

    // GET /api/v1/countries/{id}/cities
    public function cities($id)
    {
        $country = Country::find($id);
        if (! $country) {
            return response()->json(['status' => false, 'message' => 'Country not found'], 404);
        }

        $nameCol = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        $cities = City::query()
            ->where('country_id', $country->id)
            ->select('id', 'country_id', $nameCol . ' as name', 'name_en', 'name_ar')
            ->orderBy($nameCol)
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Cities fetched successfully.',
            'data'    => [
                'country' => [
                    'id'   => $country->id,
                    'name' => $country->$nameCol,
                ],
                'cities'  => $cities,
            ],
        ]);
    }
}
