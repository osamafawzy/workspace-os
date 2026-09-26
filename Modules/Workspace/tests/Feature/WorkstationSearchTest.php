<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Search\WorkstationSearch;
use Tests\TestCase;

/**
 * Every way somebody might name a desk leads to it, and nothing else.
 */
class WorkstationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Workstation $desk;

    protected Workstation $other;

    protected function setUp(): void
    {
        parent::setUp();

        $floor = Floor::factory()->create(['name' => 'First Floor']);
        $rack = Rack::factory()->create(['building_id' => $floor->building_id, 'number' => 'R-07', 'name' => 'Comms East']);
        $switch = NetworkSwitch::factory()->create(['building_id' => $floor->building_id, 'rack_id' => $rack->id, 'number' => 'SW-11', 'management_ip' => '10.0.0.11']);
        $port = SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi1/0/3', 'number' => '3']);
        $vlan = Vlan::factory()->create(['number' => 240, 'name' => 'Voice']);

        $this->desk = Workstation::factory()->for($floor)->create([
            'name' => 'A-03',
            'workstation_number' => '3',
            'computer_name' => 'HQ-L1-WS003',
            'switch_port_id' => $port->id,
            'port_split_number' => 'B',
            'vlan_id' => $vlan->id,
            'ip_address' => '10.20.1.33',
            'mac_address' => '00:1A:2B:3C:4D:5E',
            'pc_serial' => 'PCSN12345',
            'monitor_serial' => 'MONSN678',
        ]);

        $this->other = Workstation::factory()->for($floor)->create([
            'name' => 'B-14',
            'computer_name' => 'HQ-L1-WS114',
            'ip_address' => '10.20.9.14',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    protected function found(string $term): array
    {
        return app(WorkstationSearch::class)->search($term)->pluck('name')->all();
    }

    public function test_a_desk_is_found_by_everything_written_on_it_or_its_cable(): void
    {
        $terms = [
            'A-03',          // Workstation ID
            'a-03',          // in any case
            'hq-l1-ws003',   // PC name
            '10.20.1.33',    // IP
            '00:1A:2B:3C:4D:5E', // MAC as stored
            '001a.2b3c.4d5e',    // MAC as a Cisco switch prints it
            '001A2B3C4D5E',      // MAC bare
            '3c-4d',             // part of a MAC, hyphenated
            'Gi1/0/3',       // port
            'SW-11',         // switch
            '10.0.0.11',     // switch management IP
            'R-07',          // rack
            'Comms East',    // rack name (two words, both in the one field)
            '240',           // VLAN
            'voice',         // VLAN name
            'PCSN12345',     // PC serial
            'MONSN678',      // monitor serial
        ];

        foreach ($terms as $term) {
            $this->assertSame(['A-03'], $this->found($term), "Searching for {$term}");
        }
    }

    public function test_every_word_has_to_match_something(): void
    {
        $this->assertSame(['A-03'], $this->found('SW-11 Gi1/0/3'));
        $this->assertSame([], $this->found('SW-11 B-14'));
        $this->assertEqualsCanonicalizing(['A-03', 'B-14'], $this->found('HQ-L1'));
    }

    public function test_wildcards_typed_in_are_searched_for_not_obeyed(): void
    {
        $this->assertSame([], $this->found('%'));
        $this->assertSame([], $this->found('_'));
        $this->assertSame([], $this->found('A_03'));
        $this->assertSame([], $this->found('   '));
    }

    public function test_an_exact_workstation_id_comes_first(): void
    {
        Workstation::factory()->for($this->desk->floor)->create(['name' => 'A-030']);
        Workstation::factory()->for($this->desk->floor)->create(['name' => 'XA-03']);

        $this->assertSame('A-03', $this->found('A-03')[0]);
        $this->assertSame(['A-03', 'A-030', 'XA-03'], $this->found('A-03'));
    }

    public function test_the_fields_a_word_was_found_in_are_reported(): void
    {
        $matched = app(WorkstationSearch::class)->matchedFields($this->desk->load(WorkstationSearch::EAGER_LOADS), '001a.2b3c SW-11');

        $this->assertContains(['label' => 'MAC Address', 'value' => '00:1A:2B:3C:4D:5E'], $matched);
        $this->assertContains(['label' => 'Switch', 'value' => 'SW-11'], $matched);
        $this->assertNotContains('Workstation ID', array_column($matched, 'label'));
    }

    public function test_locate_settles_on_one_desk_or_on_none(): void
    {
        $search = app(WorkstationSearch::class);

        $this->assertTrue($search->locate('a-03')->is($this->desk));
        $this->assertTrue($search->locate('HQ-L1-WS003')->is($this->desk));
        $this->assertTrue($search->locate('10.20.1.33')->is($this->desk));
        $this->assertTrue($search->locate('001a.2b3c.4d5e')->is($this->desk));
        $this->assertTrue($search->locate('pcsn12345')->is($this->desk));

        // Partial matches are for the search page to show, not to guess from.
        $this->assertNull($search->locate('A-0'));
        $this->assertNull($search->locate(''));

        // The same ID on two floors names neither.
        $second = Floor::factory()->create(['building_id' => $this->desk->floor->building_id]);
        $twin = Workstation::factory()->for($second)->create(['name' => 'A-03']);

        $this->assertNull($search->locate('A-03'));
        $this->assertTrue($search->locate('A-03', $second)->is($twin));
    }
}
