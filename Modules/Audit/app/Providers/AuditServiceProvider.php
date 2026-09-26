<?php

namespace Modules\Audit\Providers;

use App\Models\AuditLog;
use App\Support\Permissions;
use App\Support\Reports\Reports;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Policies\AuditLogPolicy;
use Modules\Audit\Reports\AuditReport;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The audit log viewer.
 *
 * Writing the log is core — App\Support\Audit — because every module writes
 * to it. This module is the screen for reading it.
 */
class AuditServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Audit';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'audit';

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Permissions::class)->register('Audit', [
            'audit.view' => 'View the audit log',
        ]);

        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        $this->app->make(Reports::class)->register(AuditReport::class);
    }
}
