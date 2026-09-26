<?php

namespace Modules\Workspace\Filament\Admin\Resources\Switches\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;

/** The ports on this switch, and which desk each one is patched to. */
class PortsRelationManager extends RelationManager
{
    protected static string $relationship = 'ports';

    protected static ?string $title = 'Ports';

    /** More in one go than any real switch has is a typo. */
    public const MAX_PORTS = 500;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Port name')
                    ->required()
                    ->maxLength(50)
                    ->placeholder('Gi2/0/24')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('network_switch_id', $this->getOwnerRecord()->getKey())),
                TextInput::make('number')->label('Port number')->maxLength(20)->placeholder('24'),
                TextInput::make('description')->label('Description')->maxLength(255)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('workstation.floor')->orderByRaw('LENGTH(name), name'))
            ->columns([
                TextColumn::make('name')->label('Port name')->weight('bold')->fontFamily(FontFamily::Mono)->searchable(),
                TextColumn::make('number')->label('Port number')->placeholder('-'),
                TextColumn::make('workstation.name')
                    ->label('Patched to')
                    ->placeholder('Free')
                    ->description(fn (SwitchPort $record): ?string => $record->workstation?->floor?->name)
                    ->url(fn (SwitchPort $record): ?string => $record->workstation
                        ? WorkstationResource::getUrl('edit', ['record' => $record->workstation])
                        : null),
                TextColumn::make('description')->label('Description')->placeholder('-')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('patched')
                    ->label('Patched')
                    ->queries(
                        true: fn (Builder $query) => $query->has('workstation'),
                        false: fn (Builder $query) => $query->doesntHave('workstation'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->headerActions([
                $this->addPortsAction(),
                CreateAction::make()->label('Add port'),
            ])
            ->recordActions([
                EditAction::make(),
                // Hidden while a desk is patched to it.
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No ports recorded')
            ->emptyStateDescription('Use "Add a range" to create Gi1/0/1 to Gi1/0/48 in one go.');
    }

    /** A run of ports — Gi1/0/1 to Gi1/0/48 — rather than 48 trips through a form. */
    protected function addPortsAction(): Action
    {
        return Action::make('addPorts')
            ->label('Add a range')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->authorize('create', SwitchPort::class)
            ->fillForm(fn (): array => [
                'prefix' => 'Gi1/0/',
                'from' => 1,
                'to' => $this->getOwnerRecord()->port_count ?: 48,
            ])
            ->schema([
                TextInput::make('prefix')->label('Prefix')->maxLength(40)->helperText('The part before the number, e.g. Gi1/0/'),
                TextInput::make('from')->label('From')->required()->integer()->minValue(0)->live(onBlur: true),
                TextInput::make('to')
                    ->label('To')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->gte('from')
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => 'Creates '.max(0, (int) $get('to') - (int) $get('from') + 1).' port(s). Ports that already exist are skipped.'),
            ])
            ->action(function (array $data): void {
                /** @var NetworkSwitch $switch */
                $switch = $this->getOwnerRecord();
                $from = (int) $data['from'];
                $to = min((int) $data['to'], $from + self::MAX_PORTS - 1);
                $existing = $switch->ports()->pluck('name')->flip();
                $created = 0;

                for ($number = $from; $number <= $to; $number++) {
                    $name = ($data['prefix'] ?? '').$number;

                    if (isset($existing[$name])) {
                        continue;
                    }

                    $switch->ports()->create(['name' => $name, 'number' => (string) $number]);
                    $created++;
                }

                Notification::make()
                    ->title($created.' port(s) added')
                    ->body($created < ($to - $from + 1) ? 'The rest already existed.' : null)
                    ->success()
                    ->send();
            });
    }
}
