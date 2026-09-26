<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Schemas;

use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\Employee;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        $sensitive = EmployeeResource::canViewSensitive();

        return $schema->components(array_values(array_filter([
            Section::make('Employee')
                ->columns(3)
                ->schema([
                    TextInput::make('oid')
                        ->label('OID')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('The ID the Workday export and asset forms use.'),

                    TextInput::make('employee_number')
                        ->label('Employee Number')
                        ->maxLength(50)
                        ->unique(ignoreRecord: true),

                    Select::make('status')
                        ->label('Status')
                        ->options(EmployeeStatus::class)
                        ->default(EmployeeStatus::Active)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            if (($state instanceof EmployeeStatus ? $state : EmployeeStatus::tryFrom((string) $state)) === EmployeeStatus::Left && blank($get('left_at'))) {
                                $set('left_at', now()->toDateString());
                            }
                        }),

                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(150)
                        ->columnSpan(2),

                    TextInput::make('job_title')
                        ->label('Job Title')
                        ->maxLength(150),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(150),

                    TextInput::make('mobile')
                        ->label('Mobile')
                        ->tel()
                        ->maxLength(30),

                    DatePicker::make('joined_at')
                        ->label('Joining Date'),

                    DatePicker::make('left_at')
                        ->label('Leaving Date')
                        ->afterOrEqual('joined_at'),
                ]),

            Section::make('Where they work')
                ->columns(2)
                ->schema([
                    self::lookup('department_id', 'department', 'Department'),
                    self::lookup('account_id', 'account', 'Account'),
                    self::lookup('site_id', 'site', 'Site')
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('location_id', null)),
                    self::lookup('location_id', 'location', 'Location', fn (Builder $query, Get $get) => $query->where('site_id', $get('site_id')))
                        ->helperText('Pick the site first.'),
                ]),

            // Built only for somebody allowed to see it. A hidden field would
            // still have its value sent to the browser with the form.
            $sensitive ? Section::make('Personal')
                ->description('Seen only by people allowed to see personal data.')
                ->icon('heroicon-o-lock-closed')
                ->columns(2)
                ->schema([
                    TextInput::make('national_id')
                        ->label('National ID')
                        ->maxLength(50)
                        ->rules([fn (?Employee $record): Closure => self::uniqueNationalId($record)]),

                    Textarea::make('address')
                        ->label('Home Address')
                        ->rows(2),
                ]) : null,

            $sensitive ? Section::make('Emergency contacts')
                ->description('Who to call first, and who to call if they do not answer.')
                ->icon('heroicon-o-lock-closed')
                ->columns(2)
                ->schema([
                    self::contact(1, 'firstContact', 'First contact'),
                    self::contact(2, 'secondContact', 'Second contact'),
                ]) : null,

            Section::make('Notes')
                ->collapsed()
                ->schema([
                    Textarea::make('notes')
                        ->hiddenLabel()
                        ->rows(3),
                ]),
        ])));
    }

    /**
     * A Settings list to pick from: the active rows, plus whatever this
     * employee already has even if it has since been retired.
     */
    protected static function lookup(string $field, string $relationship, string $label, ?Closure $scope = null): Select
    {
        return Select::make($field)
            ->label($label)
            ->relationship(
                $relationship,
                'name',
                function (Builder $query, ?Employee $record, Get $get) use ($field, $scope): Builder {
                    $query
                        ->where(fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->when($record?->getAttribute($field), fn (Builder $query, int $id) => $query->orWhere($query->getModel()->getQualifiedKeyName(), $id)))
                        ->orderBy('name');

                    if ($scope !== null) {
                        $scope($query, $get);
                    }

                    return $query;
                },
            )
            ->searchable()
            ->preload();
    }

    protected static function contact(int $slot, string $relationship, string $label): Fieldset
    {
        return Fieldset::make($label)
            // Clearing the name removes the contact.
            ->relationship($relationship, condition: fn (?array $state): bool => filled($state['name'] ?? null))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'slot' => $slot])
            ->columns(1)
            ->schema([
                TextInput::make('name')->label('Name')->maxLength(150),
                TextInput::make('relationship')->label('Relationship')->maxLength(50)->placeholder('Mother, spouse…'),
                TextInput::make('phone')->label('Phone')->tel()->maxLength(30),
                Textarea::make('address')->label('Address')->rows(2),
            ]);
    }

    /** The same national ID cannot be on two employees; compared by its hash. */
    protected static function uniqueNationalId(?Employee $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $hash = Employee::hashNationalId(is_string($value) ? $value : null);

            if ($hash === null) {
                return;
            }

            $holder = Employee::query()
                ->where('national_id_hash', $hash)
                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                ->first();

            if ($holder) {
                $fail("This national ID already belongs to {$holder->name} ({$holder->oid}).");
            }
        };
    }
}
