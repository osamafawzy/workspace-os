<?php

/*
|--------------------------------------------------------------------------
| The admin sidebar
|--------------------------------------------------------------------------
|
| The default shape of the sidebar. Every entry has a stable key; the screens
| that exist claim their key (see App\Support\Navigation\HasConfigurableNavigation)
| and everything else here is a planned module, shown with a "coming in phase N"
| page so the sidebar already has the shape the application is growing into.
|
| Labels, order, group and visibility can all be changed from
| Settings → Navigation without touching this file; those overrides are stored
| in the database and laid over these defaults.
|
| Groups carry the icons. Filament does not allow a group and the items inside
| it to both have icons, so grouped items have none; an item outside a group
| (Dashboard) has its own.
|
| To add a module: add its group or item here, and give its resource or page
| the same key. To retire a planned item: delete it from here.
|
*/

return [

    'groups' => [
        'floor-management' => ['label' => 'Floor Management', 'icon' => 'heroicon-o-building-office-2', 'sort' => 10],
        'network' => ['label' => 'Network', 'icon' => 'heroicon-o-server-stack', 'sort' => 15],
        'asset-management' => ['label' => 'Asset Management', 'icon' => 'heroicon-o-computer-desktop', 'sort' => 20],
        'reports' => ['label' => 'Reports', 'icon' => 'heroicon-o-chart-bar', 'sort' => 30],
        'admin' => ['label' => 'Admin', 'icon' => 'heroicon-o-shield-check', 'sort' => 40],
        'settings' => ['label' => 'Settings', 'icon' => 'heroicon-o-cog-6-tooth', 'sort' => 50],
    ],

    'items' => [
        'dashboard' => ['label' => 'Dashboard', 'group' => null, 'icon' => 'heroicon-o-home', 'sort' => -100],

        // Floor Management
        'buildings' => ['label' => 'Buildings', 'group' => 'floor-management', 'sort' => 5],
        'floor-setup' => ['label' => 'Floor Setup', 'group' => 'floor-management', 'sort' => 10],
        'workstations' => ['label' => 'Workstations', 'group' => 'floor-management', 'sort' => 15],
        'floor-mapping' => ['label' => 'Floor Mapping', 'group' => 'floor-management', 'sort' => 20],
        'search-workstation' => ['label' => 'Search Workstation', 'group' => 'floor-management', 'sort' => 30],

        // Network — what desks are patched into
        'switches' => ['label' => 'Switches', 'group' => 'network', 'sort' => 10],
        'racks' => ['label' => 'Racks', 'group' => 'network', 'sort' => 20],
        'vlans' => ['label' => 'VLANs', 'group' => 'network', 'sort' => 30],

        // Asset Management
        'release-new-assets' => ['label' => 'Release New Assets', 'group' => 'asset-management', 'sort' => 10],
        'search-assets' => ['label' => 'Search For Assets', 'group' => 'asset-management', 'sort' => 20],
        'update-assets' => ['label' => 'Update Assets', 'group' => 'asset-management', 'sort' => 30],
        'assign-assets' => ['label' => 'Assign Assets', 'group' => 'asset-management', 'sort' => 35],
        'return-assets' => ['label' => 'Return Assets', 'group' => 'asset-management', 'sort' => 40],
        'returned-assets' => ['label' => 'Search For Returned Assets', 'group' => 'asset-management', 'sort' => 50],
        'handover-forms' => ['label' => 'Handover Forms', 'group' => 'asset-management', 'sort' => 55],
        'employees' => ['label' => 'Employees Data', 'group' => 'asset-management', 'sort' => 60],
        'headsets' => ['label' => 'Adding New Headsets Data', 'group' => 'asset-management', 'sort' => 70],

        // Reports
        'reports' => ['label' => 'All Reports', 'group' => 'reports', 'sort' => 10],

        // Admin
        'amt-data-preparation' => [
            'label' => 'AMT Data Preparation', 'group' => 'admin', 'sort' => 10, 'phase' => 9,
            'summary' => 'Waiting on a description of the AMT data this should prepare.',
        ],
        'non-returned-assets' => [
            'label' => 'Non Returned Assets / DIF MSA', 'group' => 'admin', 'sort' => 20, 'phase' => 9,
            'summary' => 'Waiting on a definition of "non-returned" and of the DIF MSA output.',
        ],
        'user-permission' => ['label' => 'User Permission', 'group' => 'admin', 'sort' => 30],
        'roles' => ['label' => 'Roles', 'group' => 'admin', 'sort' => 40],
        'audit-log' => ['label' => 'Audit Log', 'group' => 'admin', 'sort' => 50],

        // Settings
        'company' => ['label' => 'Company', 'group' => 'settings', 'sort' => 10],
        'navigation' => ['label' => 'Navigation', 'group' => 'settings', 'sort' => 20],
        'sites' => ['label' => 'Sites', 'group' => 'settings', 'sort' => 30],
        'locations' => ['label' => 'Locations', 'group' => 'settings', 'sort' => 40],
        'accounts' => ['label' => 'Accounts', 'group' => 'settings', 'sort' => 50],
        'departments' => ['label' => 'Departments', 'group' => 'settings', 'sort' => 60],
        'asset-types' => ['label' => 'Asset Types', 'group' => 'settings', 'sort' => 70],
        'manufacturers' => ['label' => 'Manufacturers', 'group' => 'settings', 'sort' => 80],
        'asset-models' => ['label' => 'Asset Models', 'group' => 'settings', 'sort' => 90],
        'update-reasons' => ['label' => 'Update Reasons', 'group' => 'settings', 'sort' => 95],
    ],

];
