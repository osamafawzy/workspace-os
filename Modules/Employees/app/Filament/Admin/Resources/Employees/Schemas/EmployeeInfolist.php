<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Schemas;

use App\Models\AuditLog;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Collection;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\EmergencyContact;
use Modules\Employees\Models\Employee;

/**
 * The employee profile: who they are, where they work, the people to call,
 * what they hold, and what has happened to their record.
 */
class EmployeeInfolist
{
    /** How many history entries the profile lists. */
    public const HISTORY = 15;

    /** @var list<Closure(): Component> sections other modules add, e.g. the assets an employee holds */
    protected static array $sections = [];

    /**
     * Lets another module put its own section on the profile, after where
     * they work — the Assets module lists what the employee holds.
     *
     * @param  Closure(): Component  $section
     */
    public static function addSection(Closure $section): void
    {
        static::$sections[] = $section;
    }

    public static function configure(Schema $schema): Schema
    {
        $sensitive = EmployeeResource::canViewSensitive();

        return $schema->components(array_values(array_filter([
            Section::make('Employee')
                ->columns(3)
                ->schema([
                    TextEntry::make('oid')->label('OID')->fontFamily(FontFamily::Mono)->copyable(),
                    TextEntry::make('employee_number')->label('Employee Number')->placeholder('-'),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('name')->label('Name')->weight('bold'),
                    TextEntry::make('job_title')->label('Job Title')->placeholder('-'),
                    TextEntry::make('email')->label('Email')->copyable()->placeholder('-'),
                    TextEntry::make('mobile')->label('Mobile')->copyable()->placeholder('-'),
                    TextEntry::make('joined_at')->label('Joining Date')->date()->placeholder('-'),
                    TextEntry::make('left_at')->label('Leaving Date')->date()->placeholder('-'),
                ]),

            Section::make('Where they work')
                ->columns(4)
                ->schema([
                    TextEntry::make('department.name')->label('Department')->placeholder('-'),
                    TextEntry::make('account.name')->label('Account')->placeholder('-'),
                    TextEntry::make('site.name')->label('Site')->placeholder('-'),
                    TextEntry::make('location.name')->label('Location')->placeholder('-'),
                ]),

            $sensitive ? Section::make('Personal')
                ->icon('heroicon-o-lock-closed')
                ->columns(2)
                ->schema([
                    TextEntry::make('national_id')->label('National ID')->fontFamily(FontFamily::Mono)->placeholder('-'),
                    TextEntry::make('address')->label('Home Address')->placeholder('-'),
                ]) : null,

            $sensitive ? Section::make('Emergency contacts')
                ->icon('heroicon-o-lock-closed')
                ->schema([
                    Grid::make(2)->schema([
                        self::contact(1, 'First contact'),
                        self::contact(2, 'Second contact'),
                    ]),
                ]) : null,

            ...array_map(fn (Closure $section): Component => $section(), self::$sections),

            Section::make('Notes')
                ->visible(fn (Employee $record): bool => filled($record->notes))
                ->schema([
                    TextEntry::make('notes')->hiddenLabel(),
                ]),

            auth()->user()?->can('viewAny', AuditLog::class) ? Section::make('History')
                ->schema([
                    ViewEntry::make('history')
                        ->hiddenLabel()
                        ->view('employees::filament.employee-history')
                        ->state(fn (Employee $record): Collection => self::history($record)),
                ]) : null,
        ])));
    }

    protected static function contact(int $slot, string $label): Section
    {
        $value = fn (string $field) => fn (Employee $record): ?string => $record->emergencyContacts->firstWhere('slot', $slot)?->getAttribute($field);

        return Section::make($label)
            ->compact()
            ->schema([
                TextEntry::make("contact{$slot}_name")->label('Name')->state($value('name'))->placeholder('Not recorded'),
                TextEntry::make("contact{$slot}_relationship")->label('Relationship')->state($value('relationship'))->placeholder('-'),
                TextEntry::make("contact{$slot}_phone")->label('Phone')->state($value('phone'))->copyable()->placeholder('-'),
                TextEntry::make("contact{$slot}_address")->label('Address')->state($value('address'))->placeholder('-'),
            ]);
    }

    /**
     * The employee's audit entries and their emergency contacts', newest
     * first. Personal values in them are already redacted when written.
     *
     * @return Collection<int, AuditLog>
     */
    public static function history(Employee $employee): Collection
    {
        $contactIds = EmergencyContact::query()->where('employee_id', $employee->getKey())->pluck('id');

        return AuditLog::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('auditable_type', $employee->getMorphClass())->where('auditable_id', $employee->getKey()))
                ->orWhere(fn ($query) => $query->where('auditable_type', (new EmergencyContact)->getMorphClass())->whereIn('auditable_id', $contactIds)))
            ->latest('id')
            ->limit(self::HISTORY)
            ->get();
    }
}
