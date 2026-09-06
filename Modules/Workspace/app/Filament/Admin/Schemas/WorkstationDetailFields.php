<?php

namespace Modules\Workspace\Filament\Admin\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Modules\Workspace\Models\Workstation;

/**
 * Everything recorded about a desk beyond its name and its floor.
 *
 * Defined once and used by every screen that edits a workstation — the plan's
 * details modal, the floor's Workstations tab, and the cross-floor resource —
 * so the fields never drift apart in label, validation or help text.
 *
 * Grouped into where the desk is, how it is patched, and what is plugged into
 * it. Nine inputs in one flat column is a wall; three short groups is a form
 * somebody can fill in from a patching sheet without losing their place.
 *
 * Every field is optional. Empty is a normal state, not an unfinished one.
 */
class WorkstationDetailFields
{
    /**
     * Twelve hex digits, however they were written down: colon-separated,
     * hyphenated, Cisco's aabb.ccdd.eeff, or bare. The model stores whichever
     * of those arrives as AA:BB:CC:DD:EE:FF.
     */
    private const MAC_PATTERN = '/^(?:[0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5}|[0-9A-Fa-f]{4}(?:\.[0-9A-Fa-f]{4}){2}|[0-9A-Fa-f]{12})$/';

    /** @return array<int, Fieldset|Textarea> */
    public static function make(): array
    {
        return [
            Fieldset::make('Location')
                ->columns(3)
                ->schema([
                    self::text('site_location')
                        ->maxLength(150)
                        ->placeholder('e.g. HQ Tower B'),

                    self::text('zone_number')
                        ->maxLength(50)
                        ->placeholder('e.g. Z3'),

                    self::text('workstation_number')
                        ->maxLength(50)
                        ->placeholder('e.g. 214')
                        ->helperText('The number on the desk itself, if it differs from the name.'),
                ]),

            Fieldset::make('Patching')
                ->columns(3)
                ->schema([
                    self::text('port_split_number')
                        ->maxLength(50)
                        ->placeholder('e.g. A'),

                    self::text('switch_number')
                        ->maxLength(50)
                        ->placeholder('e.g. SW-03'),

                    self::text('interface_number')
                        ->maxLength(50)
                        ->placeholder('e.g. Gi1/0/24'),
                ]),

            Fieldset::make('Machine')
                ->columns(2)
                ->schema([
                    self::text('computer_name')
                        ->maxLength(100)
                        ->placeholder('e.g. HQ-WS-0214'),

                    self::text('mac_address')
                        ->maxLength(23)
                        ->placeholder('e.g. AA:BB:CC:DD:EE:FF')
                        ->rules(['regex:'.self::MAC_PATTERN])
                        ->validationMessages([
                            'regex' => 'Enter twelve hex digits — AA:BB:CC:DD:EE:FF, AA-BB-..., aabb.ccdd.eeff or AABBCCDDEEFF.',
                        ])
                        ->helperText('Saved as AA:BB:CC:DD:EE:FF whichever way it is pasted in.'),
                ]),

            Textarea::make('notes')
                ->label(Workstation::detailLabel('notes'))
                ->rows(3)
                ->maxLength(2000)
                ->dehydrateStateUsing(self::blankIsEmpty())
                ->columnSpanFull(),
        ];
    }

    /**
     * A free-text identifier off a label, a switch table or a floor sheet.
     *
     * Text rather than a number even where the field is called one: real
     * switch and interface labels are alphanumeric — Gi1/0/24, SW-03, A/B —
     * and an input that cannot hold what is printed on the equipment is not a
     * record of it. Nothing here is arithmetic, so nothing is lost.
     */
    private static function text(string $name): TextInput
    {
        return TextInput::make($name)
            // The label comes off the model, which is also where the public
            // site reads it from, so a field cannot end up called one thing in
            // the admin and another on the floor page.
            ->label(Workstation::detailLabel($name))
            ->dehydrateStateUsing(self::blankIsEmpty());
    }

    /**
     * Whitespace and empty strings are stored as null.
     *
     * Otherwise "cleared the field" and "never filled it in" become two
     * different states in the database that look identical on screen, and the
     * "has details" filter and the ring on the plan start disagreeing with
     * each other about the same desk.
     *
     * @return \Closure(?string): ?string
     */
    private static function blankIsEmpty(): \Closure
    {
        return function (?string $state): ?string {
            $state = trim((string) $state);

            return $state === '' ? null : $state;
        };
    }
}
