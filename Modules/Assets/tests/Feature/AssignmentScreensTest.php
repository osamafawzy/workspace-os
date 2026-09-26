<?php

namespace Modules\Assets\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Assets\Actions\AssignAssets as AssignAction;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Pages\AssignAssets;
use Modules\Assets\Filament\Admin\Pages\PrintHandoverForm;
use Modules\Assets\Filament\Admin\Pages\ReturnAssets;
use Modules\Assets\Filament\Admin\Resources\HandoverForms\Pages\ListHandoverForms;
use Modules\Assets\Filament\Admin\Resources\ReturnedAssets\Pages\ListReturnedAssets;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetReturn;
use Modules\Assets\Models\HandoverForm;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use Modules\Employees\Models\Employee;
use Tests\TestCase;

class AssignmentScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function tech(string ...$extra): User
    {
        $user = User::factory()->withPermissions('assets.view', 'assignments.view', 'assignments.assign', 'assignments.return', 'assignments.print', 'employees.view', ...$extra)->create(['name' => 'Tech One']);
        $this->actingAs($user);

        return $user;
    }

    public function test_every_screen_needs_its_permission(): void
    {
        $screens = [
            '/admin/assign-assets' => ['assets.view', 'assignments.assign'],
            '/admin/return-assets' => ['assets.view', 'assignments.return'],
            '/admin/handover-forms' => ['assignments.view'],
            '/admin/returned-assets' => ['assignments.view'],
        ];

        foreach ($screens as $url => $permissions) {
            $this->actingAs(User::factory()->withPermissions('assets.view')->create())->get($url)->assertForbidden();
            $this->actingAs(User::factory()->withPermissions(...$permissions)->create())->get($url)->assertSuccessful();
        }

        foreach (['return-assets', 'returned-assets'] as $item) {
            $this->actingAs(User::factory()->superAdmin()->create())->get("/admin/coming-soon?item={$item}")->assertNotFound();
        }
    }

    public function test_an_asset_is_gathered_assigned_and_its_form_opens(): void
    {
        $this->tech();

        $employee = Employee::factory()->create(['name' => 'Ahmed Hassan']);
        $laptop = Asset::factory()->create(['serial_number' => 'LT-100', 'asset_tag' => 'AT-100']);
        $headset = Asset::factory()->create(['serial_number' => 'HS-200']);
        $taken = Asset::factory()->create(['serial_number' => 'TAKEN-1', 'employee_id' => Employee::factory()->create()->id]);

        $page = Livewire::withQueryParams(['asset' => $laptop->id])
            ->test(AssignAssets::class)
            ->assertSet('selected', [$laptop->id])
            // A scanned tag adds its asset.
            ->set('search', 'hs-200')
            ->call('scan')
            ->assertSet('selected', [$laptop->id, $headset->id])
            // Something already with somebody is refused.
            ->call('add', $taken->id)
            ->assertNotified()
            ->assertSet('selected', [$laptop->id, $headset->id])
            ->fillForm(['employee_id' => $employee->id, 'notes' => 'Bag included'])
            ->assertSet('employeeId', $employee->id)
            ->call('assign');

        $form = HandoverForm::query()->firstOrFail();
        $page->assertRedirect(PrintHandoverForm::getUrl(['form' => $form->id]));

        $this->assertSame($employee->id, $laptop->fresh()->employee_id);
        $this->assertSame($employee->id, $headset->fresh()->employee_id);
        $this->assertSame('Bag included', $form->snapshot['notes']);
    }

    public function test_assets_come_back_with_their_condition_and_the_receipt_opens(): void
    {
        $tech = $this->tech();

        $employee = Employee::factory()->create(['name' => 'Sara Ali']);
        $laptop = Asset::factory()->create(['serial_number' => 'LT-1']);
        $mouse = Asset::factory()->create(['serial_number' => 'MS-1']);
        app(AssignAction::class)->handle($employee, [$laptop->id, $mouse->id], $tech);

        $page = Livewire::withQueryParams(['employee' => $employee->id])
            ->test(ReturnAssets::class)
            ->assertSee('LT-1')
            ->call('addAllFromEmployee')
            ->assertCount('selected', 2)
            ->set("selected.{$mouse->id}", AssetCondition::Damaged->value)
            ->fillForm(['returned_by_name' => 'Team leader', 'notes' => 'Mouse wheel broken'])
            ->call('recordReturn');

        $receipt = HandoverForm::query()->where('kind', HandoverForm::RETURN)->firstOrFail();
        $page->assertRedirect(PrintHandoverForm::getUrl(['form' => $receipt->id]));

        $this->assertSame(AssetStatus::Returned, $mouse->fresh()->status);
        $this->assertSame(AssetCondition::Damaged, $mouse->fresh()->condition);
        $this->assertSame('Team leader', AssetReturn::query()->where('asset_id', $mouse->id)->value('returned_by_name'));
    }

    public function test_the_printed_form_counts_copies_and_shows_contacts_only_to_those_allowed(): void
    {
        $tech = $this->tech();

        $employee = Employee::factory()->withPersonalDetails()->create(['name' => 'Ahmed Hassan']);
        $contact = $employee->emergencyContacts()->where('slot', 1)->first();
        $form = app(AssignAction::class)->handle($employee, [Asset::factory()->create(['serial_number' => 'LT-PRINT'])->id], $tech);
        $url = PrintHandoverForm::getUrl(['form' => $form->id]);

        $this->get($url)
            ->assertSuccessful()
            ->assertSee('IT Asset Handover Form')
            ->assertSee($form->number)
            ->assertSee('Ahmed Hassan')
            ->assertSee('LT-PRINT')
            ->assertDontSee($contact->name)
            ->assertDontSee('COPY');

        $this->actingAs(User::factory()->withPermissions('assignments.view', 'assignments.print', 'employees.view', 'employees.view_sensitive')->create());

        $this->get($url)
            ->assertSuccessful()
            ->assertSee($contact->name)
            ->assertSee('COPY');

        $this->assertSame(2, $form->fresh()->print_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reprinted', 'record_label' => $form->number]);

        // Seeing forms is not printing them.
        $this->actingAs(User::factory()->withPermissions('assignments.view')->create())->get($url)->assertForbidden();
    }

    public function test_the_forms_list_finds_and_reprints_but_never_edits(): void
    {
        $tech = $this->tech();

        $employee = Employee::factory()->create(['name' => 'Ahmed Hassan', 'oid' => '1234567']);
        $form = app(AssignAction::class)->handle($employee, [Asset::factory()->create()->id], $tech);
        $other = app(AssignAction::class)->handle(Employee::factory()->create(), [Asset::factory()->create()->id], $tech);

        Livewire::test(ListHandoverForms::class)
            ->searchTable('1234567')
            ->assertCanSeeTableRecords([$form])
            ->assertCanNotSeeTableRecords([$other])
            ->assertTableActionHasUrl('print', PrintHandoverForm::getUrl(['form' => $form->id]), $form)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');

        $this->assertFalse($tech->can('update', $form));
        $this->assertFalse($tech->can('delete', $form));
    }

    public function test_returned_assets_are_searched_and_filtered(): void
    {
        $tech = $this->tech('assets.export');

        $sara = Employee::factory()->create(['name' => 'Sara Ali', 'oid' => '7654321']);
        $omar = Employee::factory()->create(['name' => 'Omar']);
        $a = Asset::factory()->create(['serial_number' => 'RET-A']);
        $b = Asset::factory()->create(['serial_number' => 'RET-B']);

        app(AssignAction::class)->handle($sara, [$a->id], $tech);
        app(AssignAction::class)->handle($omar, [$b->id], $tech);
        app(\Modules\Assets\Actions\ReturnAssets::class)->handle([$a->id => 'damaged', $b->id => 'good'], [], $tech);

        $saraReturn = AssetReturn::query()->where('asset_id', $a->id)->firstOrFail();
        $omarReturn = AssetReturn::query()->where('asset_id', $b->id)->firstOrFail();

        Livewire::test(ListReturnedAssets::class)
            ->assertCanSeeTableRecords([$saraReturn, $omarReturn])
            ->searchTable('7654321')
            ->assertCanSeeTableRecords([$saraReturn])
            ->assertCanNotSeeTableRecords([$omarReturn])
            ->searchTable('')
            ->filterTable('condition', [AssetCondition::Damaged->value])
            ->assertCanSeeTableRecords([$saraReturn])
            ->assertCanNotSeeTableRecords([$omarReturn])
            ->resetTableFilters()
            ->callAction('export', data: ['format' => 'csv'])
            ->assertFileDownloaded();
    }

    public function test_the_employee_profile_offers_assign_and_return(): void
    {
        $tech = $this->tech();

        $employee = Employee::factory()->create();
        $leaver = Employee::factory()->left()->create();

        Livewire::test(ViewEmployee::class, ['record' => $employee->id])
            ->assertActionVisible('assignAssets')
            ->assertActionHidden('returnAssets');

        app(AssignAction::class)->handle($employee, [Asset::factory()->create()->id], $tech);

        Livewire::test(ViewEmployee::class, ['record' => $employee->id])
            ->assertActionVisible('returnAssets')
            ->assertActionHasUrl('returnAssets', ReturnAssets::getUrl(['employee' => $employee->id]));

        Livewire::test(ViewEmployee::class, ['record' => $leaver->id])
            ->assertActionHidden('assignAssets');
    }
}
