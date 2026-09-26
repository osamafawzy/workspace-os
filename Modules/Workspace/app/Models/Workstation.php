<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Workspace\Database\Factories\WorkstationFactory;
use Modules\Workspace\Enums\WorkstationStatus;

/**
 * One desk, on one floor, in the real building.
 *
 * A workstation is a name (its Workstation ID, e.g. WS-024) and a floor, plus
 * the record of the desk — where on the floor it is, how it is patched back to
 * the network, and what is on it. Every part of that record is optional: a
 * desk exists physically long before anyone has traced the cable.
 *
 * @property int $id
 * @property int $floor_id
 * @property int|null $area_id
 * @property string $name
 * @property WorkstationStatus $status
 * @property string|null $workstation_number
 * @property string|null $desk_row
 * @property string|null $desk_position
 * @property int|null $switch_port_id
 * @property string|null $port_split_number
 * @property int|null $vlan_id
 * @property string|null $computer_name
 * @property string|null $pc_serial
 * @property string|null $monitor_serial
 * @property string|null $ip_address
 * @property string|null $mac_address
 * @property string|null $notes
 * @property-read Floor $floor
 * @property-read Area|null $area
 * @property-read SwitchPort|null $switchPort
 * @property-read Vlan|null $vlan
 * @property-read FloorObject|null $mapObject
 */
class Workstation extends Model
{
    /** @use HasFactory<WorkstationFactory> */
    use Auditable, HasFactory;

    /**
     * The record kept about a desk, grouped the way it is read.
     *
     * Declared once, here, so the admin form, the tables, the "has details"
     * filter, the public modal and {@see hasDetails()} cannot end up
     * disagreeing about what a field is called. Keys are either a column or
     * one of the derived values {@see detailValue()} knows how to read.
     *
     * @var array<string, array<string, string>>
     */
    public const DETAIL_GROUPS = [
        'Location' => [
            'site' => 'Site / Building',
            'area' => 'Area / Zone',
            'desk_row' => 'Row',
            'desk_position' => 'Position',
            'workstation_number' => 'Workstation Number',
        ],
        'Network' => [
            'switch' => 'Switch',
            'port' => 'Port',
            'port_split_number' => 'Port Split Number',
            'rack' => 'Rack',
            'vlan' => 'VLAN',
            'ip_address' => 'IP Address',
            'mac_address' => 'MAC Address',
        ],
        'Machine' => [
            'computer_name' => 'PC Name',
            'pc_serial' => 'PC Serial Number',
            'monitor_serial' => 'Monitor Serial Number',
        ],
        'Notes' => [
            'notes' => 'Notes',
        ],
    ];

    /**
     * What the public building view may show about a desk.
     *
     * Exactly the fields it has always shown — where the desk is, the switch
     * and port it is patched to, the split, the PC name, the MAC and the note.
     * Fields added since (row and position, rack, VLAN, IP address, serial
     * numbers, status) are admin-only until somebody decides otherwise,
     * because the public site has no login.
     *
     * @var list<string>
     */
    public const PUBLIC_DETAILS = [
        'site',
        'area',
        'workstation_number',
        'switch',
        'port',
        'port_split_number',
        'computer_name',
        'mac_address',
        'notes',
    ];

    /**
     * The text columns that count as details, in the order they are asked for.
     *
     * @var list<string>
     */
    public const DETAIL_COLUMNS = [
        'workstation_number',
        'desk_row',
        'desk_position',
        'port_split_number',
        'computer_name',
        'pc_serial',
        'monitor_serial',
        'ip_address',
        'mac_address',
        'notes',
    ];

    /**
     * The links that count as details: the area, the switch port, the VLAN.
     *
     * @var list<string>
     */
    public const DETAIL_RELATIONS = [
        'area_id',
        'switch_port_id',
        'vlan_id',
    ];

    /** The derived values, and the relations each one needs loaded. */
    public const DETAIL_EAGER_LOADS = [
        'floor.building.site',
        'area',
        'switchPort.networkSwitch.rack',
        'vlan',
    ];

    protected $fillable = [
        'floor_id',
        'name',
        'status',
        ...self::DETAIL_RELATIONS,
        ...self::DETAIL_COLUMNS,
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkstationStatus::class,
        ];
    }

    protected static function newFactory(): WorkstationFactory
    {
        return WorkstationFactory::new();
    }

    /**
     * Everything the details form edits: every link, every text field, and the
     * status. The plan's details modal writes exactly these and nothing else.
     *
     * @return list<string>
     */
    public static function editableDetails(): array
    {
        return ['status', ...self::DETAIL_RELATIONS, ...self::DETAIL_COLUMNS];
    }

    public function auditLabel(): string
    {
        return $this->floor ? $this->displayLabel() : (string) $this->name;
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<SwitchPort, $this> */
    public function switchPort(): BelongsTo
    {
        return $this->belongsTo(SwitchPort::class);
    }

    /** @return BelongsTo<Vlan, $this> */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    /** Where the desk is on its floor's map, if it has been put there. */
    public function mapObject(): HasOne
    {
        return $this->hasOne(FloorObject::class);
    }

    /** The switch this desk is patched to, through its port. */
    public function networkSwitch(): ?NetworkSwitch
    {
        return $this->switchPort?->networkSwitch;
    }

    /** The rack this desk's switch stands in. */
    public function rack(): ?Rack
    {
        return $this->switchPort?->networkSwitch?->rack;
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
        return Attribute::set(fn (?string $value): ?string => self::normaliseMac($value));
    }

    /**
     * Twelve hex digits, however they were written down: colon-separated,
     * hyphenated, Cisco's aabb.ccdd.eeff, or bare.
     */
    public const MAC_PATTERN = '/^(?:[0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5}|[0-9A-Fa-f]{4}(?:\.[0-9A-Fa-f]{4}){2}|[0-9A-Fa-f]{12})$/';

    public static function normaliseMac(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $value));

        return strlen($hex) === 12
            ? implode(':', str_split($hex, 2))
            : $value;
    }

    /** The label a field is shown under, wherever it is shown. */
    public static function detailLabel(string $key): string
    {
        foreach (self::DETAIL_GROUPS as $fields) {
            if (isset($fields[$key])) {
                return $fields[$key];
            }
        }

        return str($key)->headline()->toString();
    }

    /** One field of the record, as text, whether it is a column or derived. */
    public function detailValue(string $key): ?string
    {
        $value = match ($key) {
            'site' => $this->floor?->building?->fullName(),
            'area' => $this->area?->name,
            'switch' => $this->networkSwitch()?->label(),
            'port' => $this->switchPort?->name,
            'rack' => $this->rack()?->label(),
            'vlan' => $this->vlan?->label(),
            default => $this->getAttribute($key),
        };

        return filled($value) ? (string) $value : null;
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
     * @param  bool  $public  only the fields the public site may show
     * @return list<array{title: string, rows: list<array{label: string, value: string|null, mono: bool}>}>
     */
    public function detailGroups(bool $public = false): array
    {
        $groups = [];

        foreach (self::DETAIL_GROUPS as $title => $fields) {
            $rows = [];
            $anything = false;

            foreach ($fields as $key => $label) {
                if ($public && ! in_array($key, self::PUBLIC_DETAILS, true)) {
                    continue;
                }

                // The site is where the whole floor is, not something recorded
                // about this desk — it never makes a group worth showing alone.
                $value = $this->detailValue($key);
                $anything = $anything || ($value !== null && $key !== 'site');
                $rows[] = [
                    'label' => $label,
                    'value' => $value,
                    // Read a pair of digits or a segment at a time; proportional
                    // type makes that harder than it needs to be.
                    'mono' => in_array($key, ['mac_address', 'ip_address', 'port'], true),
                ];
            }

            if ($anything) {
                $groups[] = ['title' => $title, 'rows' => $rows];
            }
        }

        return $groups;
    }

    /**
     * Whether this desk is on its floor's map.
     *
     * Lists load this as `is_placed` with `withExists`, so three hundred rows
     * do not each ask; anything else falls back to the relation.
     */
    public function isPlaced(): bool
    {
        if (array_key_exists('is_placed', $this->attributes)) {
            return (bool) $this->attributes['is_placed'];
        }

        return $this->relationLoaded('mapObject')
            ? $this->mapObject !== null
            : $this->mapObject()->exists();
    }

    /**
     * Where the desk is, as percentages of its floor — what the flat plan and
     * the 3D scene on the public site draw with. Null when it is not placed.
     *
     * @return array{0: float, 1: float}|null
     */
    public function planPosition(): ?array
    {
        $object = $this->mapObject;
        $floor = $this->floor;

        if (! $object || ! $floor) {
            return null;
        }

        return [
            round(max(0, min(100, $object->x / max($floor->width_m, 0.01) * 100)), 2),
            round(max(0, min(100, $object->y / max($floor->depth_m, 0.01) * 100)), 2),
        ];
    }

    /**
     * Whether anything at all has been recorded about this desk.
     *
     * Used to show at a glance which desks still have nothing on them, without
     * the caller having to know which fields count as "details". The status
     * does not count: every desk has one.
     */
    public function hasDetails(): bool
    {
        foreach ([...self::DETAIL_RELATIONS, ...self::DETAIL_COLUMNS] as $column) {
            if (filled($this->getAttribute($column))) {
                return true;
            }
        }

        return false;
    }

    /** @param Builder<Workstation> $query */
    public function scopePlaced(Builder $query): void
    {
        $query->whereHas('mapObject');
    }

    /**
     * The desks that still have to be put somewhere: the ones in the map's
     * tray.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeUnplaced(Builder $query): void
    {
        $query->whereDoesntHave('mapObject');
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
            foreach ([...self::DETAIL_RELATIONS, ...self::DETAIL_COLUMNS] as $column) {
                $has
                    ? $nested->orWhereNotNull($column)
                    : $nested->whereNull($column);
            }
        });
    }

    /**
     * Desks patched to a switch — any port on it.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeOnSwitch(Builder $query, int $switchId): void
    {
        $query->whereHas('switchPort', fn (Builder $port) => $port->where('network_switch_id', $switchId));
    }

    /**
     * Desks whose switch stands in a rack.
     *
     * @param  Builder<Workstation>  $query
     */
    public function scopeInRack(Builder $query, int $rackId): void
    {
        $query->whereHas('switchPort.networkSwitch', fn (Builder $switch) => $switch->where('rack_id', $rackId));
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
