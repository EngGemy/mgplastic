<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\LandingPageService;

class LandingController extends Controller
{
    public function __construct(protected LandingPageService $landing) {}

    public function index()
    {
        return view('website.landing', $this->landing->data());
    }

    public function terms()
    {
        return view('website.legal', [
            'pageTitle' => 'الشروط والأحكام',
            'record' => $this->landing->termsContent(),
            'settings' => \App\Models\WebsiteSetting::instance(),
        ]);
    }

    public function privacy()
    {
        return view('website.legal', [
            'pageTitle' => 'سياسة الخصوصية',
            'record' => $this->landing->privacyContent(),
            'settings' => \App\Models\WebsiteSetting::instance(),
        ]);
    }
}
