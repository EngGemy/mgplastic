@if (file_exists(resource_path('css/filament/admin/theme.css')))
    <style>{!! file_get_contents(resource_path('css/filament/admin/theme.css')) !!}</style>
@endif
