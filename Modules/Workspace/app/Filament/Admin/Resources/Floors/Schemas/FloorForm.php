<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FloorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Floor')
                ->description('A physical floor of the building.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->placeholder('Ground Floor'),

                    TextInput::make('level')
                        ->label('Level')
                        ->required()
                        ->integer()
                        ->minValue(-20)
                        ->maxValue(200)
                        ->unique(ignoreRecord: true)
                        // Spelling out the convention here is cheaper than
                        // discovering later that half the building was
                        // numbered from 1 and half from 0.
                        ->helperText('0 is ground level, 1 is the floor above it, -1 a basement.'),

                    TextInput::make('width_m')
                        ->label('Width')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(60)
                        ->suffix('m')
                        ->live(onBlur: true),

                    TextInput::make('depth_m')
                        ->label('Depth')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(40)
                        ->suffix('m')
                        ->live(onBlur: true)
                        // Area and rough capacity are what somebody actually
                        // wants from two dimensions, and neither is obvious
                        // from "60" and "40" sitting side by side.
                        ->helperText(function (Get $get): string {
                            $area = (float) $get('width_m') * (float) $get('depth_m');

                            if ($area <= 0) {
                                return 'The plan and the 3D view both take their shape from these.';
                            }

                            return number_format($area).' m2 - room for roughly '
                                .number_format(floor($area / 8)).' desks at 8 m2 each.';
                        }),

                    Textarea::make('description')
                        ->label('Description')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive floors stay on file with their desks, but are filtered out of day-to-day lists.'),
                ]),

            Section::make('Floor plan')
                ->description('The drawing the desks are positioned against. Until one is here, the Plan tab uses a blank grid — placements made on it stay valid, because they are stored as percentages rather than pixels.')
                ->collapsed(fn ($record): bool => $record === null || ! $record->hasPlan())
                ->schema([
                    FileUpload::make('plan_path')
                        ->label('Plan drawing')
                        ->image()
                        ->disk('public')
                        ->directory('floor-plans')
                        ->imagePreviewHeight('220')
                        ->maxSize(8192)
                        ->helperText('PNG, JPG, WebP or SVG, up to 8 MB. Crop it to the floor outline — the whole image is the coordinate space.'),
                ]),
        ]);
    }
}
