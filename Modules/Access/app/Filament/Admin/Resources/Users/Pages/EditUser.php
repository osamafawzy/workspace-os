<?php

namespace Modules\Access\Filament\Admin\Resources\Users\Pages;

use App\Support\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Access\Filament\Admin\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<int, string> */
    protected array $rolesBefore = [];

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->record->roles()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Roles are saved through the pivot table, which the model's own audit
     * never sees, so the change is recorded here.
     */
    protected function afterSave(): void
    {
        $this->record->unsetRelation('roles');
        $after = $this->record->roles()->orderBy('name')->pluck('name')->all();

        if ($after !== $this->rolesBefore) {
            app(AuditLogger::class)->log('roles changed', 'Access', $this->record, ['roles' => $this->rolesBefore], ['roles' => $after], $this->record->email);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
