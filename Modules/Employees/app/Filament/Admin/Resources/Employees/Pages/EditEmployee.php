<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\Employee;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * The form is filled from the record's attributes, and that data travels
     * to the browser with the page — whether or not a field shows it. For
     * somebody who may not see personal data it is taken out before it can.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! EmployeeResource::canViewSensitive()) {
            unset($data['national_id'], $data['national_id_hash'], $data['address']);
        }

        unset($data['national_id_hash']);

        return $data;
    }

    /**
     * Only what the form had. Belt and braces: the personal fields are not in
     * the form for somebody who may not see them, so they cannot be written.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! EmployeeResource::canViewSensitive()) {
            foreach (Employee::SENSITIVE as $field) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
