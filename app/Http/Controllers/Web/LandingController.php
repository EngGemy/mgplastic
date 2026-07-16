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
            'socialLinks' => \App\Models\SocialMedia::query()->orderBy('id')->get(),
        ]);
    }

    public function privacy()
    {
        return view('website.legal', [
            'pageTitle' => 'سياسة الخصوصية',
            'record' => $this->landing->privacyContent(),
            'settings' => \App\Models\WebsiteSetting::instance(),
            'socialLinks' => \App\Models\SocialMedia::query()->orderBy('id')->get(),
        ]);
    }

    /** Alias for /policy — same content as terms & conditions. */
    public function policy()
    {
        return view('website.legal', [
            'pageTitle' => 'السياسات والشروط',
            'record' => $this->landing->termsContent(),
            'settings' => \App\Models\WebsiteSetting::instance(),
            'socialLinks' => \App\Models\SocialMedia::query()->orderBy('id')->get(),
        ]);
    }

    public function contact()
    {
        $settings = \App\Models\WebsiteSetting::instance();

        return view('website.contact', [
            'settings' => $settings,
            'socialLinks' => \App\Models\SocialMedia::query()->orderBy('id')->get(),
            'categories' => $this->landing->categoriesTree(),
            'termsUrl' => route('terms'),
            'privacyUrl' => route('privacy'),
            'policyUrl' => route('policy'),
            'contactUrl' => route('contact'),
        ]);
    }
}
