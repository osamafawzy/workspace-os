<?php

namespace Modules\Audit\Filament\Admin\Resources\AuditLogs\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Audit\Filament\Admin\Resources\AuditLogs\AuditLogResource;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
