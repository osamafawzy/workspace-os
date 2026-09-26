<?php

namespace Modules\Settings\Filament\Admin\Support;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Modules\Settings\Models\Lookup;

/**
 * The shared screen behind every managed list.
 *
 * A list is small and edited rarely, so it is one page with modal forms rather
 * than separate create and edit screens. A resource for a new list extends
 * this, names its model and navigation key, and adds any columns of its own
 * through {@see extraFields()} and {@see extraColumns()}.
 */
abstract class LookupResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $recordTitleAttribute = 'name';

    /** @return array<int, mixed> */
    protected static function extraFields(): array
    {
        return [];
    }

    /** @return array<int, mixed> */
    protected static function extraColumns(): array
    {
        return [];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(100)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => static::uniqueNameScope($rule, $get),
                    ),

                TextInput::make('code')
                    ->label('Code')
                    ->maxLength(30)
                    ->helperText('Optional short code, e.g. ALX.'),

                ...static::extraFields(),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(2)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Switch off to stop offering it for new records. Records that already use it keep it.'),
            ]);
    }

    /**
     * Narrows the name uniqueness check. Names are unique across the whole
     * list by default; a location only has to be unique within its site.
     */
    protected static function uniqueNameScope(Unique $rule, Get $get): Unique
    {
        return $rule;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Lookup $record): ?string => $record->description),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                ...static::extraColumns(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                // Hidden for a row something still points at; see isInUse().
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Nothing here yet');
    }
}
