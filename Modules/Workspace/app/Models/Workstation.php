<?php

namespace Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workspace\Database\Factories\WorkstationFactory;

/**
 * One desk, on one floor, in the real building.
 *
 * A workstation is a name and a floor, plus the patching record for the desk —
 * where it is, which switch interface it lands on, and what is plugged into
 * it. Every part of that record is optional: a desk exists physically long
 * before anyone has traced the cable.
 *
 * @property int $id
 * @property int $floor_id
 * @property string $name
 * @property string|null $site_location
 * @property string|null $zone_number
 * @property string|null $workstation_number
 * @property string|null $port_split_number
 * @property string|null $switch_number
 * @property string|null $interface_number
 * @property string|null $computer_name
 * @property string|null $mac_address
 * @property string|null $notes
 * @property float|null $position_x
 * @property float|null $position_y
 * @property-read Floor $floor
 */
class Workstation extends Model
{
    /** @use HasFactory<WorkstationFactory> */
    use HasFactory;

    /**
     * The optional record kept about a desk, grouped the way it is read.
     *
     * Where it is, how it is patched back to the rack, and what is plugged
     * into it. Declared once, here, so the admin form, the two tables, the
     * "has details" filter, the public modal and {@see hasDetails()} cannot
     * end up disagreeing about which columns count or what they are called.
     *
     * @var array<string, array<string, string>>
     */
    public const DETAIL_GROUPS = [
        'Location' => [
            'site_location' => 'Site Location',
            'zone_number' => 'Zone Number',
            'workstation_number' => 'Workstation Number',
        ],
        'Patching' => [
            'port_split_number' => 'Port Split Number',
            'switch_number' => 'Switch Number',
            'interface_number' => 'Interface Number',
        ],
        'Machine' => [
            'computer_name' => 'Computer Name',
            'mac_address' => 'MAC Address',
        ],
        'Notes' => [
            'notes' => 'Notes',
        ],
    ];

    /**
     * Every detail column, flattened, in the order it is asked for.
     *
     * @var list<string>
     */
    public const DETAIL_COLUMNS = [
        'site_location',
        'zone_number',
        'workstation_number',
        'port_split_number',
        'switch_number',
        'interface_number',
        'computer_name',
        'mac_address',
        'notes',
    ];

    protected $fillable = [
        'floor_id',
        'name',
        ...self::DETAIL_COLUMNS,
        'position_x',
        'position_y',
    ];

    protected function casts(): array
    {
        return [
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }

    protected static function newFactory(): WorkstationFactory
    {
        return WorkstationFactory::new();
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /**
     * MAC addresses are stored canonically as AA:BB:CC:DD:EE:FF.
     *
     * They get copied out of a switch table, a label, or an ipconfig dump, so
     * they arrive colon-separated, hyphenated, in Cisco's aabb.ccdd.eeff, or
     * as bare hex. Normalising on the way in means the same NIC is written the
     * same way whichever screen it was typed on, and searching for it works.
     *
     * Anything that is not twelve hex digits is stored as typed — the form
     * rejects it before it reaches here, and silently mangling a value the
     * validator let through would hide the bug rather than surface it.
     */
    protected function macAddress(): Attribute
    {
        return Attribute::set(function (?string $value): ?string {
            if (blank($value)) {
                return null;
            }

            $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $value));

            return strlen($hex) === 12
                ? implode(':', str_split($hex, 2))
                : $value;
        });
    }

    /** The label a column is shown under, wherever it is shown. */
    public static function detailLabel(string $column): string
    {
        foreach (self::DETAIL_GROUPS as $columns) {
            if (isset($columns[$column])) {
                return $columns[$column];
            }
        }

        return str($column)->headline()->toString();
    }

    /**
     * The record as it is read rather than as it is stored: groups in order,
     * each with its labelled rows, and the empty ones left out.
     *
     * A group where nothing has been filled in is dropped entirely, but a
     * blank field inside a group that does have something is kept and shown as
     * a dash — "we have not traced this one" is worth seeing, whereas a whole
     * empty section is just noise.
     *
     * @return list<array{title: string, rows: list<array{label: string, value: string|null, mono: bool}>}>
     */
    public function detailGroups(): array
    {
        $groups = [];

        foreach (self::DETAIL_GROUPS as $title => $columns) {
            $rows = [];
            $anything = false;

            foreach ($columns as $column => $label) {
                $value = filled($this->{$column}) ? (string) $this->{$column} : null;
                $anything = $anything || $value !== null;
                $rows[] = [
                    'label' => $label,
                    'value' => $value,
                    // A MAC is read a pair of digits at a time. Proportional
                    // type makes that harder than it needs to be.
                    'mono' => $column === 'mac_address',
                ];
            }

            if ($anything) {
                $groups[] = ['title' => $title, 'rows' => $rows];
            }
        }

        return $groups;
    }

    /**
     * Whether this desk has been pinned to a spot on its floor plan.
     *
     * Both coordinates are required to be a placement — a desk with only an x
     * is a half-finished drag, not a location.
     */
    public function isPlaced(): bool
    {
        return $this->position_x !== null && $this->position_y !== null;
    }

    /**
     * Whether anything at all has been recorded about this desk.
     *
     * Used to show at a glance which desks still have nothing on them, without
     * the caller having to know which columns count as "details".
     */
    public function hasDetails(): bool
    {
        foreach (self::DETAIL_COLUMNS as $column) {
            if (filled($this->{$column})) {
                return true;
            }
        }

        return false;
    }

    /** @param Builder<Workstation> $query */
    public function scopePlaced(Builder $query): void
    {
        $query->whereNotNull('position_x')->whereNotNull('position_y');
    }

    /**
     * The desks that still have to be put somewhere.
     *
     * The two null checks are wrapped in their own group deliberately. Bare
     * `orWhereNull` calls would bind at the top level of whatever query this
     * scope lands in, so `$floor->workstations()->unplaced()` would read as
     * "(this floor AND no x) OR (no y)" and quietly drag in unplaced desks
     * from every other floor.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeUnplaced(Builder $query): void
    {
        $query->where(fn (Builder $nested) => $nested
            ->whereNull('position_x')
            ->orWhereNull('position_y'));
    }

    /**
     * Desks with, or without, any part of the record filled in.
     *
     * Grouped for the same reason as {@see scopeUnplaced()}: this runs inside
     * a floor's relationship often enough that a loose `orWhereNull` would
     * escape the floor constraint.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeWithDetails(Builder $query, bool $has = true): void
    {
        $query->where(function (Builder $nested) use ($has): void {
            foreach (self::DETAIL_COLUMNS as $column) {
                $has
                    ? $nested->orWhereNotNull($column)
                    : $nested->whereNull($column);
            }
        });
    }

    /**
     * How the desk is referred to away from its own floor — "Desk A-12" on the
     * second floor is only unambiguous with the floor said out loud with it.
     */
    public function displayLabel(): string
    {
        return $this->floor?->name
            ? "{$this->floor->name} · {$this->name}"
            : $this->name;
    }
}
