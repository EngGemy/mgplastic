<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\HomePopupResource\Pages\ListHomePopups;
use App\Models\HomePopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HomePopupListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_render_home_popup_list_with_json_roles(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'is_approved' => true,
        ]);

        HomePopup::query()->create([
            'version' => 1,
            'title_ar' => 'بوب أب قائمة',
            'title_en' => 'List popup',
            'body_ar' => 'وصف',
            'image_path' => 'home-popups/list-test.jpg',
            'image_ratio' => 0.8,
            'kind' => 'announcement',
            'buttons' => [[
                'id' => 'ok',
                'text_ar' => 'حسناً',
                'link_type' => 'home',
                'style' => 'primary',
            ]],
            'target_roles' => ['guest', 'plumber'],
            'show' => 'once',
            'dismissible' => true,
            'sort_order' => 5,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListHomePopups::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(HomePopup::all())
            ->assertSee('بوب أب قائمة')
            ->assertSee('زائر')
            ->assertSee('سباك');
    }
}
