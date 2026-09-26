<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Modules\Assets\Models\Asset;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;

/** The asset's page: what it is, where, with whom, and everything that happened to it. */
class AssetInfolist
{
    /** How many history entries the page lists. */
    public const HISTORY = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asset')
                ->columns(4)
                ->schema([
                    TextEntry::make('assetType.name')->label('Type')->badge(),
                    TextEntry::make('assetModel.name')->label('Model')->state(fn (Asset $record): ?string => $record->assetModel?->fullName())->placeholder('-'),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('condition')->label('Condition')->badge()->placeholder('-'),
                    TextEntry::make('serial_number')->label('Serial Number')->fontFamily(FontFamily::Mono)->copyable(),
                    TextEntry::make('asset_tag')->label('Asset Tag')->fontFamily(FontFamily::Mono)->copyable()->placeholder('-'),
                    TextEntry::make('computer_name')->label('Computer Name')->copyable()->placeholder('-'),
                ]),

            Section::make('Where it is and who has it')
                ->columns(4)
                ->schema([
                    TextEntry::make('site.name')->label('Site')->placeholder('-'),
                    TextEntry::make('location.name')->label('Location')->placeholder('-'),
                    TextEntry::make('account.name')->label('Account')->placeholder('-'),
                    TextEntry::make('employee.name')
                        ->label('Assigned To')
                        ->state(fn (Asset $record): ?string => $record->employee?->auditLabel())
                        ->url(fn (Asset $record): ?string => $record->employee && (auth()->user()?->can('view', $record->employee) ?? false)
                            ? EmployeeResource::getUrl('view', ['record' => $record->employee])
                            : null)
                        ->helperText(fn (Asset $record): ?string => $record->assigned_at ? 'Since '.$record->assigned_at->format('Y-m-d') : null)
                        ->placeholder('Nobody'),
                ]),

            Section::make('Cord')
                ->columns(3)
                ->visible(fn (Asset $record): bool => filled($record->cord_model) || filled($record->cord_serial) || $record->cord_condition !== null)
                ->schema([
                    TextEntry::make('cord_model')->label('Cord Model')->placeholder('-'),
                    TextEntry::make('cord_serial')->label('Cord S/N')->fontFamily(FontFamily::Mono)->copyable()->placeholder('-'),
                    TextEntry::make('cord_condition')->label('Cord Status')->badge()->placeholder('-'),
                ]),

            Section::make('Purchase and warranty')
                ->columns(2)
                ->schema([
                    TextEntry::make('purchase_date')->label('Purchase Date')->date()->placeholder('-'),
                    TextEntry::make('warranty_expires_at')
                        ->label('Warranty Expiry')
                        ->date()
                        ->placeholder('-')
                        ->color(fn (Asset $record): ?string => match (true) {
                            $record->warranty_expires_at === null => null,
                            $record->warrantyExpired() => 'danger',
                            $record->warranty_expires_at->lte(today()->addDays(Asset::WARRANTY_WARNING_DAYS)) => 'warning',
                            default => 'success',
                        })
                        ->helperText(fn (Asset $record): ?string => match (true) {
                            $record->warranty_expires_at === null => null,
                            $record->warrantyExpired() => 'Expired',
                            default => 'Ends '.$record->warranty_expires_at->diffForHumans(),
                        }),
                ]),

            Section::make('Notes')
                ->visible(fn (Asset $record): bool => filled($record->notes))
                ->schema([
                    TextEntry::make('notes')->hiddenLabel(),
                ]),

            Section::make('History')
                ->schema([
                    ViewEntry::make('history')
                        ->hiddenLabel()
                        ->view('assets::filament.asset-history')
                        ->state(fn (Asset $record) => $record->history()->limit(self::HISTORY)->get()),
                ]),
        ]);
    }
}
