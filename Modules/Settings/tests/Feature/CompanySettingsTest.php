<?php

namespace Modules\Settings\Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Branding;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Settings\Filament\Admin\Pages\CompanySettings;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_a_fresh_install_is_branded_concentrix_it_operations(): void
    {
        $this->assertSame('Concentrix IT Operations', app(Branding::class)->name());

        $this->get('/')->assertSuccessful()->assertSee('Concentrix IT Operations');
    }

    public function test_the_company_name_can_be_changed_from_the_panel(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm([
                'company_name' => 'Concentrix Egypt',
                'app_name' => 'IT Ops',
                'primary_color' => '#0f766e',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Concentrix Egypt IT Ops', app(Branding::class)->name());
        $this->assertSame('#0f766e', app(Branding::class)->primaryColor());

        // Everywhere the name is shown, not just the setting.
        $this->get('/admin')->assertSee('Concentrix Egypt IT Ops');
        $this->get('/')->assertSee('Concentrix Egypt IT Ops');
    }

    public function test_changing_settings_is_audited(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['company_name' => 'Concentrix Egypt'])
            ->call('save');

        $entry = AuditLog::query()->where('record_label', 'Company settings')->firstOrFail();

        $this->assertSame('Concentrix Egypt', $entry->new_values['company_name']);
    }

    public function test_a_bad_colour_is_refused(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['primary_color' => 'red; background:url(x)'])
            ->call('save')
            ->assertHasFormErrors(['primary_color']);
    }

    public function test_company_settings_need_the_manage_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('lookups.view')->create())
            ->get('/admin/settings/company')
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('settings.manage')->create())
            ->get('/admin/settings/company')
            ->assertSuccessful();
    }
}
