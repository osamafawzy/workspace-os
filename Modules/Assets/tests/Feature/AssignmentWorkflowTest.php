<?php

namespace Modules\Assets\Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Actions\AssignAssets;
use Modules\Assets\Actions\ReturnAssets;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetAssignment;
use Modules\Assets\Models\AssetReturn;
use Modules\Assets\Models\HandoverForm;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Tests\TestCase;

/**
 * Handing over and taking back, as the actions do it.
 */
class AssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tech = User::factory()->create(['name' => 'Tech One']);
        $this->actingAs($this->tech);
    }

    public function test_assigning_hands_over_every_asset_and_makes_one_form(): void
    {
        $employee = Employee::factory()->withPersonalDetails()->create(['name' => 'Ahmed Hassan', 'oid' => '1234567']);
        $laptop = Asset::factory()->create(['serial_number' => 'LT-1']);
        $monitor = Asset::factory()->create(['serial_number' => 'MON-1', 'status' => AssetStatus::Returned]);

        $form = app(AssignAssets::class)->handle($employee, [$laptop->id, $monitor->id], $this->tech, 'Charger included');

        $this->assertMatchesRegularExpression('/^HO-\d{4}-\d{6}$/', $form->number);
        $this->assertSame(HandoverForm::HANDOVER, $form->kind);
        $this->assertSame('Tech One', $form->generated_by_name);
        $this->assertSame(['LT-1', 'MON-1'], array_column($form->snapshot['assets'], 'serial_number'));
        $this->assertSame('Ahmed Hassan', $form->snapshot['employee']['name']);
        $this->assertCount(2, $form->snapshot['contacts']);
        $this->assertSame('Charger included', $form->snapshot['notes']);

        foreach ([$laptop, $monitor] as $asset) {
            $asset->refresh();
            $this->assertSame($employee->id, $asset->employee_id);
            $this->assertSame(AssetStatus::Assigned, $asset->status);
            $this->assertSame('assigned', $asset->history()->value('event'));

            $assignment = $asset->openAssignment();
            $this->assertSame($form->id, $assignment->handover_form_id);
            $this->assertSame('Tech One', $assignment->assigned_by_name);
            $this->assertSame('Charger included', $assignment->notes);
        }

        $this->assertSame(2, AssetAssignment::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'assets assigned', 'record_label' => 'Ahmed Hassan (1234567)']);
    }

    public function test_the_form_keeps_what_it_said_and_hides_it_at_rest(): void
    {
        $employee = Employee::factory()->withPersonalDetails()->create(['name' => 'Ahmed Hassan']);
        $contact = $employee->emergencyContacts()->first();
        $asset = Asset::factory()->create(['serial_number' => 'LT-1', 'asset_tag' => 'AT-OLD']);

        $form = app(AssignAssets::class)->handle($employee, [$asset->id], $this->tech);

        // Later changes do not rewrite the paper.
        $employee->update(['name' => 'Ahmed M. Hassan']);
        $asset->update(['asset_tag' => 'AT-NEW']);

        $form->refresh();
        $this->assertSame('Ahmed Hassan', $form->snapshot['employee']['name']);
        $this->assertSame('AT-OLD', $form->snapshot['assets'][0]['asset_tag']);

        $raw = (string) DB::table('handover_forms')->where('id', $form->id)->value('snapshot');
        $this->assertStringNotContainsString('Ahmed', $raw);
        $this->assertStringNotContainsString((string) $contact->phone, $raw);

        $logged = AuditLog::query()->where('auditable_type', $form->getMorphClass())->get()->toJson();
        $this->assertStringNotContainsString((string) $contact->name, $logged);
    }

    public function test_assigning_refuses_what_is_not_free_and_does_nothing_at_all(): void
    {
        $employee = Employee::factory()->create();
        $free = Asset::factory()->create(['serial_number' => 'FREE-1']);
        $taken = Asset::factory()->create(['serial_number' => 'TAKEN-1', 'employee_id' => Employee::factory()->create()->id]);
        $broken = Asset::factory()->create(['serial_number' => 'BROKEN-1', 'status' => AssetStatus::InRepair]);

        try {
            app(AssignAssets::class)->handle($employee, [$free->id, $taken->id, $broken->id], $this->tech);
            $this->fail('Assigning taken assets should have been refused.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['assets']);
            $this->assertStringContainsString('TAKEN-1 is assigned to somebody else', $message);
            $this->assertStringContainsString('BROKEN-1 is in repair', $message);
        }

        // All or nothing: the free one was not handed over either.
        $this->assertNull($free->fresh()->employee_id);
        $this->assertSame(0, HandoverForm::query()->count());
    }

    public function test_nobody_who_has_left_gets_assets(): void
    {
        $this->expectException(ValidationException::class);

        app(AssignAssets::class)->handle(Employee::factory()->left()->create(), [Asset::factory()->create()->id], $this->tech);
    }

    public function test_returning_closes_the_assignment_and_makes_a_receipt_per_employee(): void
    {
        $ahmed = Employee::factory()->create(['name' => 'Ahmed']);
        $sara = Employee::factory()->create(['name' => 'Sara']);
        $a1 = Asset::factory()->create(['serial_number' => 'A-1']);
        $a2 = Asset::factory()->create(['serial_number' => 'A-2']);
        $s1 = Asset::factory()->create(['serial_number' => 'S-1']);

        app(AssignAssets::class)->handle($ahmed, [$a1->id, $a2->id], $this->tech);
        app(AssignAssets::class)->handle($sara, [$s1->id], $this->tech);

        $site = Site::factory()->create();
        $store = Location::query()->create(['site_id' => $site->id, 'name' => 'IT Store']);

        $receipts = app(ReturnAssets::class)->handle(
            [$a1->id => 'good', $a2->id => AssetCondition::Damaged, $s1->id => 'fair'],
            ['returned_at' => now()->subHour()->toDateTimeString(), 'site_id' => $site->id, 'location_id' => $store->id, 'notes' => 'Screen cracked on A-2'],
            $this->tech,
        );

        $this->assertCount(2, $receipts);
        $this->assertSame(['Ahmed', 'Sara'], $receipts->map(fn (HandoverForm $receipt) => $receipt->snapshot['employee']['name'])->sort()->values()->all());

        $ahmedsReceipt = $receipts->first(fn (HandoverForm $receipt) => $receipt->employee_id === $ahmed->id);
        $this->assertMatchesRegularExpression('/^RT-\d{4}-\d{6}$/', $ahmedsReceipt->number);
        $this->assertSame(['Good', 'Damaged'], array_column($ahmedsReceipt->snapshot['assets'], 'condition'));
        $this->assertSame('Ahmed', $ahmedsReceipt->snapshot['returned_by']);
        $this->assertSame('Tech One', $ahmedsReceipt->snapshot['received_by']);

        $a2->refresh();
        $this->assertNull($a2->employee_id);
        $this->assertSame(AssetStatus::Returned, $a2->status);
        $this->assertSame(AssetCondition::Damaged, $a2->condition);
        $this->assertSame($store->id, $a2->location_id);
        $this->assertSame('returned', $a2->history()->value('event'));
        $this->assertNull($a2->openAssignment());

        $return = AssetReturn::query()->where('asset_id', $a2->id)->firstOrFail();
        $this->assertSame($ahmed->id, $return->employee_id);
        $this->assertSame('Tech One', $return->received_by_name);
        $this->assertSame($ahmedsReceipt->id, $return->handover_form_id);
        $this->assertNotNull($return->assignment->returned_at);

        // Back in stock, it can go out again.
        $this->assertTrue($a2->isAssignable());
    }

    public function test_returning_refuses_an_asset_nobody_holds(): void
    {
        $free = Asset::factory()->create(['serial_number' => 'FREE-1']);

        $this->expectException(ValidationException::class);

        app(ReturnAssets::class)->handle([$free->id => 'good'], [], $this->tech);
    }

    public function test_returns_cannot_be_dated_in_the_future(): void
    {
        $asset = Asset::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->expectException(ValidationException::class);

        app(ReturnAssets::class)->handle([$asset->id => 'good'], ['returned_at' => now()->addDays(3)->toDateTimeString()], $this->tech);
    }

    public function test_an_asset_given_by_an_import_leaves_the_same_trail_and_can_be_returned(): void
    {
        $employee = Employee::factory()->create();
        $asset = Asset::factory()->create(['employee_id' => $employee->id]);

        $open = $asset->openAssignment();
        $this->assertNotNull($open);
        $this->assertNull($open->handover_form_id);

        app(ReturnAssets::class)->handle([$asset->id => 'good'], [], $this->tech);

        $this->assertNull($asset->fresh()->employee_id);
        $this->assertNotNull($open->fresh()->returned_at);
        $this->assertSame(1, AssetReturn::query()->count());
    }
}
