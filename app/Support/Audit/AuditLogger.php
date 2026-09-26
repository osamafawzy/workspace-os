<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Writes the audit log.
 *
 * Model changes arrive here automatically through the {@see Auditable} trait.
 * Things that are not a single model changing — "auto-arranged 40 desks",
 * "assigned 3 assets to an employee" — are logged by calling {@see log()}
 * from the action that does them.
 */
class AuditLogger
{
    public const RESOLVE_HOSTNAMES = 'audit.resolve_hostnames';

    public function __construct(protected Settings $settings) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function log(
        string $action,
        string $module,
        ?Model $record = null,
        array $old = [],
        array $new = [],
        ?string $label = null,
    ): AuditLog {
        $user = auth()->user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $ip = $request?->ip();

        return AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name ?? (app()->runningInConsole() ? 'System (console)' : null),
            'action' => $action,
            'module' => $module,
            'auditable_type' => $record?->getMorphClass(),
            'auditable_id' => $record?->getKey(),
            'record_label' => $label !== null ? Str::limit($label, 250) : null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $ip,
            'hostname' => $ip ? $this->hostname($ip) : null,
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250) : null,
        ]);
    }

    /**
     * The machine's name from reverse DNS, when that is switched on.
     *
     * Off by default: a lookup that finds nothing can take seconds, and it
     * would happen on a save. Answers — including "no name" — are cached for
     * a day, so each address is only ever looked up once in a while.
     */
    protected function hostname(string $ip): ?string
    {
        if (! $this->settings->get(self::RESOLVE_HOSTNAMES, false)) {
            return null;
        }

        return Cache::remember("audit.hostname.{$ip}", now()->addDay(), function () use ($ip): string {
            $name = @gethostbyaddr($ip);

            return is_string($name) && $name !== $ip ? $name : '';
        }) ?: null;
    }
}
