<?php

namespace App\Support;

use Filament\Panel;

/**
 * Lets every nwidart module contribute its own Filament components from
 *   Modules/{Module}/app/Filament/{Panel}/{Resources,Pages,Widgets}
 *
 * Panel providers run during registration, before the modules package has
 * booted, so this reads the module list off disk instead of going through the
 * Module facade — which would come back empty at that point.
 */
class ModuleComponents
{
    public static function discover(Panel $panel, string $panelFolder): Panel
    {
        foreach (self::enabledModules() as $module) {
            $base = base_path("Modules/{$module}/app/Filament/{$panelFolder}");
            $namespace = 'Modules\\'.$module.'\\Filament\\'.$panelFolder;

            if (is_dir($path = "{$base}/Resources")) {
                $panel->discoverResources(in: $path, for: $namespace.'\\Resources');
            }

            if (is_dir($path = "{$base}/Pages")) {
                $panel->discoverPages(in: $path, for: $namespace.'\\Pages');
            }

            if (is_dir($path = "{$base}/Widgets")) {
                $panel->discoverWidgets(in: $path, for: $namespace.'\\Widgets');
            }
        }

        return $panel;
    }

    /**
     * @return array<int, string>
     */
    public static function enabledModules(): array
    {
        $modulesPath = base_path('Modules');

        if (! is_dir($modulesPath)) {
            return [];
        }

        $statuses = [];
        $statusFile = base_path('modules_statuses.json');

        if (is_file($statusFile)) {
            $statuses = json_decode((string) file_get_contents($statusFile), true) ?: [];
        }

        $modules = [];

        foreach ((array) glob($modulesPath.'/*', GLOB_ONLYDIR) as $directory) {
            $name = basename((string) $directory);

            if (! is_file($directory.'/module.json')) {
                continue;
            }

            if (($statuses[$name] ?? true) === false) {
                continue;
            }

            $modules[] = $name;
        }

        sort($modules);

        return $modules;
    }
}
