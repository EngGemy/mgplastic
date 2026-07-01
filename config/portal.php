<?php

return [
  /*
    |--------------------------------------------------------------------------
    | Plumber mobile app URL (App Store / Play Store / deep link).
    | Leave null to direct plumbers to registration on the website.
    |--------------------------------------------------------------------------
    */
    'plumber_app_url' => env('PLUMBER_APP_URL'),

  /*
    |--------------------------------------------------------------------------
    | Admin panel path — not linked publicly on the website.
    |--------------------------------------------------------------------------
    */
    'admin_path' => env('ADMIN_PANEL_PATH', 'admin'),
];
