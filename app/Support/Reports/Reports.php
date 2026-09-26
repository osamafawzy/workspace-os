<?php

namespace App\Support\Reports;

use App\Models\User;

/**
 * Every report the application has. Each module registers its own from its
 * service provider, the same way it registers its permissions and importers.
 */
class Reports
{
    /** @var array<string, class-string<Report>> */
    protected array $reports = [];

    /** @param  class-string<Report>  $report */
    public function register(string $report): void
    {
        $this->reports[$report::key()] = $report;
    }

    public function get(string $key): ?Report
    {
        return isset($this->reports[$key]) ? app($this->reports[$key]) : null;
    }

    /** @return array<string, class-string<Report>> */
    public function all(): array
    {
        return $this->reports;
    }

    public function canView(User $user, Report $report): bool
    {
        return $user->hasPermission('reports.view') && $report->authorize($user);
    }

    /**
     * The reports this user may open, grouped under their headings, in the
     * order they were registered.
     *
     * @return array<string, list<Report>>
     */
    public function visibleTo(User $user): array
    {
        $groups = [];

        foreach (array_keys($this->reports) as $key) {
            $report = $this->get($key);

            if ($report && $this->canView($user, $report)) {
                $groups[$report::group()][] = $report;
            }
        }

        return $groups;
    }
}
