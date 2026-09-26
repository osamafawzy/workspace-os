<?php

namespace Modules\Workspace\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

/**
 * Finds a desk by anything written on it or on its cable.
 *
 * Somebody standing at a switch reads out a port, somebody on the phone reads
 * a PC name off a sticker, a monitoring alert gives an IP or a MAC. Every one
 * of those has to lead to the same desk, so they are all searched together:
 * the search is split into words, and every word has to match at least one
 * field — "SW-11 Gi1/0/3" finds the desk on that port of that switch.
 *
 * Used by the Search Workstation page, the panel's Ctrl+K search, the map's
 * own find box and the locate link, so they cannot disagree about what a
 * search finds.
 */
class WorkstationSearch
{
    /** The most results one search returns. */
    public const LIMIT = 25;

    /**
     * What is searched: relation (null for the desk itself) => column => label.
     *
     * @var array<string, array<string, string>>
     */
    public const FIELDS = [
        '' => [
            'name' => 'Workstation ID',
            'workstation_number' => 'Workstation Number',
            'computer_name' => 'PC Name',
            'ip_address' => 'IP Address',
            'mac_address' => 'MAC Address',
            'pc_serial' => 'PC Serial Number',
            'monitor_serial' => 'Monitor Serial Number',
            'port_split_number' => 'Port Split Number',
        ],
        'switchPort' => [
            'name' => 'Port',
            'number' => 'Port Number',
        ],
        'switchPort.networkSwitch' => [
            'number' => 'Switch',
            'name' => 'Switch Name',
            'management_ip' => 'Switch IP',
        ],
        'switchPort.networkSwitch.rack' => [
            'number' => 'Rack',
            'name' => 'Rack Name',
        ],
        'vlan' => [
            'number' => 'VLAN',
            'name' => 'VLAN Name',
        ],
    ];

    /** What a result shows, loaded with it rather than per row. */
    public const EAGER_LOADS = [
        'floor.building',
        'area',
        'switchPort.networkSwitch.rack',
        'vlan',
        'mapObject',
    ];

    /**
     * Every desk matching the search, best match first.
     *
     * @return Builder<Workstation>
     */
    public function query(string $term): Builder
    {
        $term = $this->clean($term);
        $query = Workstation::query();

        if ($term === '') {
            return $query->whereRaw('1 = 0');
        }

        foreach ($this->words($term) as $word) {
            $query->where(fn (Builder $desk) => $this->matchWord($desk, $word));
        }

        $lower = mb_strtolower($term);

        // Somebody who typed a whole Workstation ID wants that desk at the top,
        // not wherever it falls among the forty others containing "A-1".
        return $query
            ->orderByRaw(
                "case when lower(name) = ? then 0 when lower(name) like ? escape '!' then 1 "
                .'when lower(computer_name) = ? or ip_address = ? or lower(mac_address) = ? then 2 else 3 end',
                [$lower, $this->escape($lower).'%', $lower, $term, mb_strtolower((string) Workstation::normaliseMac($term))],
            )
            ->orderBy('floor_id')
            ->orderBy('name');
    }

    /**
     * @return Collection<int, Workstation>
     */
    public function search(string $term, int $limit = self::LIMIT): Collection
    {
        return $this->query($term)->with(self::EAGER_LOADS)->limit($limit)->get();
    }

    public function count(string $term): int
    {
        return $this->query($term)->reorder()->count();
    }

    /**
     * The one desk a search means, when it means exactly one.
     *
     * Tried field by field, most specific first — a Workstation ID, then a PC
     * name, an IP, a MAC, a serial — and a field that names two desks (A-01 on
     * two floors) settles nothing, so the answer is null and the caller shows
     * the choice instead of guessing.
     */
    public function locate(string $term, ?Floor $floor = null): ?Workstation
    {
        $term = $this->clean($term);

        if ($term === '') {
            return null;
        }

        $lower = mb_strtolower($term);
        $mac = preg_match(Workstation::MAC_PATTERN, $term) ? Workstation::normaliseMac($term) : null;

        $attempts = [
            fn (Builder $query) => $query->whereRaw('lower(name) = ?', [$lower]),
            fn (Builder $query) => $query->whereRaw('lower(computer_name) = ?', [$lower]),
            fn (Builder $query) => $query->where('ip_address', $term),
            fn (Builder $query) => $mac !== null ? $query->where('mac_address', $mac) : $query->whereRaw('1 = 0'),
            fn (Builder $query) => $query->whereRaw('lower(pc_serial) = ?', [$lower]),
        ];

        foreach ($attempts as $attempt) {
            $matches = Workstation::query()
                ->when($floor, fn (Builder $query) => $query->where('floor_id', $floor->getKey()))
                ->where(fn (Builder $query) => $attempt($query))
                ->with(self::EAGER_LOADS)
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                return null;
            }
        }

        return null;
    }

    /**
     * Why a desk came up: the fields other than its ID that one of the words
     * was found in — "MAC Address: 00:1A:…" beside a result explains a search
     * for "1a2b" that the Workstation ID alone does not.
     *
     * @return list<array{label: string, value: string}>
     */
    public function matchedFields(Workstation $desk, string $term): array
    {
        $words = array_map(mb_strtolower(...), $this->words($this->clean($term)));
        $found = [];

        foreach (self::FIELDS as $relation => $columns) {
            $model = $relation === '' ? $desk : data_get($desk, $relation);

            if (! $model) {
                continue;
            }

            foreach ($columns as $column => $label) {
                if ($relation === '' && $column === 'name') {
                    continue;
                }

                $value = (string) $model->getAttribute($column);

                if ($value === '') {
                    continue;
                }

                foreach ($words as $word) {
                    if (str_contains(mb_strtolower($value), $word) || $this->macFragmentMatches($column, $value, $word)) {
                        $found[$label] = ['label' => $label, 'value' => $value];

                        break;
                    }
                }
            }
        }

        return array_values($found);
    }

    protected function matchWord(Builder $desk, string $word): void
    {
        $pattern = '%'.$this->escape(mb_strtolower($word)).'%';
        $mac = $this->macFragment($word);

        foreach (self::FIELDS as $relation => $columns) {
            $match = function (Builder $query) use ($columns, $pattern, $mac, $relation): void {
                $query->where(function (Builder $query) use ($columns, $pattern, $mac, $relation): void {
                    foreach (array_keys($columns) as $column) {
                        $query->orWhereRaw("lower({$column}) like ? escape '!'", [$pattern]);
                    }

                    // A MAC typed the way the switch prints it (001a.2b3c.4d5e)
                    // or bare, against the canonical 00:1A:2B:3C:4D:5E stored.
                    if ($relation === '' && $mac !== null) {
                        $query->orWhereRaw("lower(mac_address) like ? escape '!'", ['%'.$this->escape($mac).'%']);
                    }
                });
            };

            $relation === ''
                ? $desk->orWhere($match)
                : $desk->orWhereHas($relation, $match);
        }
    }

    /**
     * A word that could be part of a MAC address, in the stored form: an even
     * number of hex digits, at least two pairs, written with or without
     * separators. Null when the word is anything else.
     */
    protected function macFragment(string $word): ?string
    {
        if (! preg_match('/^[0-9a-f:.\-]+$/i', $word)) {
            return null;
        }

        $hex = strtolower((string) preg_replace('/[^0-9a-f]/i', '', $word));

        if (strlen($hex) < 4 || strlen($hex) % 2 !== 0 || strlen($hex) > 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    protected function macFragmentMatches(string $column, string $value, string $word): bool
    {
        $fragment = $column === 'mac_address' ? $this->macFragment($word) : null;

        return $fragment !== null && str_contains(mb_strtolower($value), $fragment);
    }

    /** @return list<string> */
    protected function words(string $term): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $term) ?: [], fn (string $word): bool => $word !== ''));
    }

    protected function clean(string $term): string
    {
        return trim(mb_substr($term, 0, 200));
    }

    /**
     * LIKE wildcards typed by a person are searched for, not obeyed. The escape
     * character is "!" because a backslash means different things to MySQL and
     * SQLite string literals.
     */
    protected function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
