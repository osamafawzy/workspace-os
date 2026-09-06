<?php

namespace Modules\Workspace\Filament\Admin\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Actions\CreateWorkstationBatch;
use Modules\Workspace\Models\Floor;

/**
 * "Add many" — a run of numbered desks in one go.
 *
 * Offered wherever you are already looking at a floor, because the alternative
 * on a three-hundred-desk floor is three hundred trips through a form.
 */
class AddManyWorkstations
{
    /**
     * @param  Closure(): Floor  $floor  resolved late: on a relation manager
     *                                   the owner record is not known when the
     *                                   action is built.
     */
    public static function make(Closure $floor, ?Closure $after = null): Action
    {
        return Action::make('addManyWorkstations')
            ->label('Add many')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->modalHeading('Add many workstations')
            ->modalSubmitActionLabel('Create')
            ->schema([
                TextInput::make('prefix')
                    ->label('Prefix')
                    ->default('A-')
                    ->maxLength(20)
                    ->live(onBlur: true)
                    ->helperText('The part of the name before the number. Leave empty for plain numbers.'),

                TextInput::make('start_at')
                    ->label('Start at')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true),

                TextInput::make('count')
                    ->label('How many')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(CreateWorkstationBatch::MAX)
                    ->default(50)
                    ->required()
                    ->live(onBlur: true),

                TextInput::make('pad')
                    ->label('Pad numbers to')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(6)
                    ->default(3)
                    ->required()
                    ->live(onBlur: true)
                    ->suffix('digits')
                    // Padding is what keeps A-9 and A-10 sorting in the order a
                    // person expects, which is invisible until it is wrong.
                    ->helperText('So A-001 sorts before A-010 rather than after it.'),

                Toggle::make('arrange')
                    ->label('Place them on the plan straight away')
                    ->default(true)
                    ->helperText('Lays the new desks out in a grid you can then drag into shape.'),

                // The preview belongs in the schema rather than in the modal
                // description: `Get` is resolved from a schema component, and
                // a modal description has none — it renders outside the form.
                //
                // Showing the first and last name is the whole check somebody
                // needs before creating three hundred rows.
                Placeholder::make('preview')
                    ->label('Will create')
                    ->content(function (Get $get): string {
                        $names = app(CreateWorkstationBatch::class)->names(
                            (string) $get('prefix'),
                            (int) $get('count'),
                            (int) $get('start_at'),
                            (int) $get('pad'),
                        );

                        if ($names === []) {
                            return 'Nothing yet — set how many.';
                        }

                        return count($names) <= 3
                            ? implode(', ', $names)
                            : count($names).' workstations: '.$names[0].' through '.end($names);
                    }),
            ])
            ->modalDescription('Names are built from the prefix and a running number.')
            ->action(function (array $data) use ($floor, $after): void {
                /** @var Floor $record */
                $record = $floor();

                $result = app(CreateWorkstationBatch::class)->handle(
                    $record,
                    (string) ($data['prefix'] ?? ''),
                    (int) $data['count'],
                    (int) $data['start_at'],
                    (int) $data['pad'],
                );

                if ($data['arrange'] ?? false) {
                    app(ArrangeWorkstations::class)->handle($record);
                }

                $skipped = count($result['skipped']);

                Notification::make()
                    ->title($result['created'] === 0
                        ? 'Nothing to create'
                        : $result['created'].' workstations added')
                    ->body($skipped > 0
                        ? $skipped.' name(s) already existed on this floor and were left alone.'
                        : null)
                    ->status($result['created'] === 0 ? 'warning' : 'success')
                    ->send();

                if ($after) {
                    $after();
                }
            });
    }
}
