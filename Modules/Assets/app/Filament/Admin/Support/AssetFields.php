<?php

namespace Modules\Assets\Filament\Admin\Support;

use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Employees\Models\Employee;

/**
 * The asset's fields, declared once for the asset form and the Update Assets
 * screen, so a rule — serials unique, an assigned asset has somebody holding
 * it — cannot hold on one and not the other.
 */
class AssetFields
{
    public static function type(): Select
    {
        return Select::make('asset_type_id')
            ->label('Asset Type')
            ->required()
            ->relationship('assetType', 'name', self::activeOrCurrent('asset_type_id'))
            ->searchable()
            ->preload()
            ->live()
            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                $model = AssetModel::query()->find($get('asset_model_id'));

                if ($model && (int) $model->asset_type_id !== (int) $state) {
                    $set('asset_model_id', null);
                }
            });
    }

    public static function model(): Select
    {
        return Select::make('asset_model_id')
            ->label('Model')
            ->relationship('assetModel', 'name', function (Builder $query, ?Model $record, Get $get): Builder {
                self::activeOrCurrent('asset_model_id')($query, $record);

                return $query->with('manufacturer')->where('asset_type_id', $get('asset_type_id'));
            })
            ->getOptionLabelFromRecordUsing(fn (AssetModel $model): string => $model->fullName())
            ->searchable()
            ->preload()
            ->helperText('Pick the type first. New models are added under Settings → Asset Models.');
    }

    public static function serial(): TextInput
    {
        return TextInput::make('serial_number')
            ->label('Serial Number')
            ->required()
            ->maxLength(100)
            ->unique(Asset::class, 'serial_number', ignoreRecord: true);
    }

    public static function tag(): TextInput
    {
        return TextInput::make('asset_tag')
            ->label('Asset Tag')
            ->maxLength(100)
            ->unique(Asset::class, 'asset_tag', ignoreRecord: true);
    }

    /**
     * The cord a headset came with: its own model, serial and state, kept on
     * the headset because that is how it is delivered, used and thrown away.
     *
     * @return array<int, TextInput|Select>
     */
    public static function cord(): array
    {
        return [
            TextInput::make('cord_model')
                ->label('Cord Model')
                ->maxLength(100)
                ->placeholder('Jabra QD to USB-A'),

            TextInput::make('cord_serial')
                ->label('Cord S/N')
                ->maxLength(100)
                ->unique(Asset::class, 'cord_serial', ignoreRecord: true)
                ->helperText('Leave empty when it came without one.'),

            Select::make('cord_condition')
                ->label('Cord Status')
                ->options(AssetCondition::class),
        ];
    }

    /** Only for types that have one: laptops and desktops. */
    public static function computerName(): TextInput
    {
        return TextInput::make('computer_name')
            ->label('Computer Name')
            ->maxLength(100)
            ->visible(fn (Get $get): bool => (bool) AssetType::query()->whereKey($get('asset_type_id'))->value('has_computer_name'));
    }

    /**
     * The statuses on offer depend on whether somebody holds the asset: one
     * that is with somebody can be Assigned (or In Repair, Lost… while still
     * theirs), one that is not cannot be Assigned. Handing over and taking
     * back happen on the Assign and Return screens.
     */
    public static function status(): Select
    {
        return Select::make('status')
            ->label('Status')
            ->options(fn (?Model $record): array => collect(AssetStatus::cases())
                ->filter(fn (AssetStatus $status): bool => self::statusFits($status, (bool) $record?->getAttribute('employee_id')))
                ->mapWithKeys(fn (AssetStatus $status): array => [$status->value => $status->getLabel()])
                ->all())
            ->default(AssetStatus::Available)
            ->required()
            ->live();
    }

    /** Whether an asset can have this status given whether somebody holds it. */
    public static function statusFits(AssetStatus $status, bool $held): bool
    {
        return $held
            ? ! in_array($status, [AssetStatus::Available, AssetStatus::Returned], true)
            : $status !== AssetStatus::Assigned;
    }

    public static function condition(): Select
    {
        return Select::make('condition')
            ->label('Condition')
            ->options(AssetCondition::class);
    }

    public static function site(): Select
    {
        return Select::make('site_id')
            ->label('Site')
            ->relationship('site', 'name', self::activeOrCurrent('site_id'))
            ->searchable()
            ->preload()
            ->live()
            ->afterStateUpdated(fn (Set $set) => $set('location_id', null));
    }

    public static function location(): Select
    {
        return Select::make('location_id')
            ->label('Location')
            ->relationship('location', 'name', function (Builder $query, ?Model $record, Get $get): Builder {
                self::activeOrCurrent('location_id')($query, $record);

                return $query->where('site_id', $get('site_id'));
            })
            ->searchable()
            ->preload();
    }

    public static function account(): Select
    {
        return Select::make('account_id')
            ->label('Account')
            ->relationship('account', 'name', self::activeOrCurrent('account_id'))
            ->searchable()
            ->preload();
    }

    /**
     * Who holds it — shown, never written from a form. It changes on the
     * Assign and Return screens, which make the signed papers and the
     * assignment record.
     */
    public static function employee(): Select
    {
        return Select::make('employee_id')
            ->label('Assigned To')
            ->relationship('employee', 'name')
            ->getOptionLabelFromRecordUsing(fn (Employee $employee): string => $employee->auditLabel())
            ->placeholder('Nobody')
            ->disabled()
            ->dehydrated(false)
            ->helperText('Changed with Assign and Return, which print the forms to sign.');
    }

    public static function purchaseDate(): DatePicker
    {
        return DatePicker::make('purchase_date')->label('Purchase Date');
    }

    public static function warranty(): DatePicker
    {
        return DatePicker::make('warranty_expires_at')
            ->label('Warranty Expiry')
            ->afterOrEqual('purchase_date');
    }

    public static function notes(): Textarea
    {
        return Textarea::make('notes')->label('Notes')->rows(3);
    }

    public static function statusIs(mixed $state, AssetStatus $status): bool
    {
        return ($state instanceof AssetStatus ? $state : AssetStatus::tryFrom((string) $state)) === $status;
    }

    /**
     * The active rows of a list, plus whatever the record already has even if
     * it has since been retired.
     *
     * @return Closure(Builder, ?Model): Builder
     */
    protected static function activeOrCurrent(string $field): Closure
    {
        return fn (Builder $query, ?Model $record = null): Builder => $query
            ->where(fn (Builder $query) => $query
                ->where($query->getModel()->qualifyColumn('is_active'), true)
                ->when($record?->getAttribute($field), fn (Builder $query, int $id) => $query->orWhere($query->getModel()->getQualifiedKeyName(), $id)))
            ->orderBy($query->getModel()->qualifyColumn('name'));
    }
}
