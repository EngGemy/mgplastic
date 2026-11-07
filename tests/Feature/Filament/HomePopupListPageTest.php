<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\HomePopupResource;
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
            ->assertSee('سباك')
            ->assertSee('JSON')
            ->assertSee('تفعيل');
    }

    public function test_admin_can_toggle_active_from_list_and_open_json_preview(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $popup = HomePopup::query()->create([
            'version' => 2,
            'title_ar' => 'تفعيل سريع',
            'image_path' => 'home-popups/toggle.jpg',
            'image_ratio' => 0.8,
            'kind' => 'announcement',
            'buttons' => [[
                'id' => 'ok',
                'text_ar' => 'حسناً',
                'link_type' => 'home',
                'style' => 'primary',
            ]],
            'target_roles' => ['guest'],
            'show' => 'once',
            'dismissible' => true,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListHomePopups::class)
            ->assertSuccessful()
            ->callTableAction('toggle_active', $popup)
            ->assertSuccessful();

        $this->assertFalse($popup->fresh()->is_active);

        $html = HomePopupResource::jsonPreviewHtml($popup->fresh())->toHtml();
        $this->assertStringContainsString('mgplastic://home', $html);
        $this->assertStringContainsString('تفعيل سريع', $html);

        Livewire::actingAs($admin)
            ->test(ListHomePopups::class)
            ->mountTableAction('preview_json', $popup)
            ->assertSuccessful();
    }
}
