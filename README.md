# Concentrix IT Operations

The internal IT Operations & Asset Management application for the Concentrix IT
team, built on what started as Workspace OS: floors, and the workstations that
physically sit on them. It runs **entirely on the company network** — no CDN,
no web fonts, no external API — and grows one module at a time (see
[Foundation](#foundation-phase-0) below for how).

Two halves:

- an **admin panel** where you record every floor and every desk, and drag each
  desk to the spot it occupies in the real room;
- a **public site** where anyone can walk the building in 3D and see where the
  desks are, without an account and without being able to change anything.

A desk needs nothing but a name. Give a floor its architect's drawing and the
desks are placed on the drawing itself; give it nothing and they are placed on a
blank grid instead. Positions are metres on the floor, so desks placed today against a grid land
in the same spot the day a drawing arrives.

## Stack

| | |
|---|---|
| Framework | Laravel 12 |
| Admin UI | Filament 5 |
| Modules | `nwidart/laravel-modules` |
| Frontend | Tailwind 4 + Vite |
| Local env | DDEV (PHP 8.2, MySQL 8.0, nginx-fpm) |

Matches the `garage-os` / `clinic-os` / `wealth-os` setup, so nothing here is a
new tool to learn.

## Running it

```bash
ddev start
ddev composer install
ddev npm install --ignore-scripts && ddev npm run build
ddev exec php artisan migrate --seed
```

Then open:

| | |
|---|---|
| **https://workspace-os.ddev.site** | the public building, no login |
| **https://workspace-os.ddev.site/admin** | the admin panel |

Sign in to the admin with:

```
admin@workspace.test / password
```

The seed builds a four-floor demo building: three small floors (24 x 16 m) with
a handful of desks each, and a **Third Floor of 300 desks on a 60 x 40 m open
plan** — the size this system is built to hold. Re-running the seeder is safe:
it updates rather than duplicates, and does not move desks already placed.

```bash
ddev exec php artisan test          # 300+ tests
ddev exec ./vendor/bin/pint         # code style
```

> The Windows PHP on this machine is 8.1, which is below the 8.2 floor.
> Everything goes through `ddev exec`; running `php artisan` directly from
> PowerShell will fail on a platform check.

To run it on a machine without DDEV — XAMPP, Apache, plain MySQL — see
[INSTALL-XAMPP.md](INSTALL-XAMPP.md). PHP 8.2 is the floor there, and the
two things that quietly break in a hand-rolled setup are `APP_URL` (the floor
plan URLs are built from it) and `php artisan storage:link`.

## The domain

```
Site ──< Building ──< Floor ──< Area
            │           └──< Workstation >── Area
            ├──< Rack ──< Switch ──< Port ──── Workstation (one desk per port)
            └──────────────< Switch
Site ──< VLAN ──< Workstation
```

Sites are a Settings lookup (assets and employees will point at them too). The
rest lives in the Workspace module.

**`buildings`** — a building on a site. Name unique within the site.

**`floors`**

| Column | Notes |
|---|---|
| `building_id` | Required. Nullable in the schema only — see the Floor Setup section. |
| `name` | Unique **within its building**. |
| `level` | Unique within its building. `0` is ground, `1` above it, `-1` a basement. |
| `width_m`, `depth_m` | How big the floor really is, in metres. Default 60 x 40 (2,400 m2, about 300 desks). |
| `description`, `is_active`, `plan_path` | Free text; retired floors drop out of day-to-day lists; the drawing the plan is placed against. |

**`areas`** — named parts of one floor ("Operations Floor", "Zone A"). Unique per
floor. Deleting one leaves its desks on the floor without an area.

**`racks`** / **`network_switches`** / **`switch_ports`** / **`vlans`** — racks and
switches belong to a building (their numbers are unique within it), a switch may
stand in a rack, ports belong to a switch (unique per switch), VLANs belong to a
site (VLAN 123 at two sites is two networks). `NetworkSwitch` because `switch` is
a PHP keyword.

**`floor_objects`** — everything on a floor's map: type, centre `x`/`y`, `width`,
`depth`, `height`, elevation `z` and `rotation`, all in metres and degrees, plus
type settings in `props` and a `locked` flag. A workstation object links one
desk (`workstation_id`, unique) — that is what "on the map" means.

**`workstations`**

| Column | Notes |
|---|---|
| `floor_id` | Cascades on delete — a desk cannot outlive its floor. |
| `name` | The **Workstation ID** (WS-024). Unique per floor. |
| `status` | Active, Available, Offline, Faulty, Under Maintenance, Removed (`WorkstationStatus`). |
| `area_id` | An area of the desk's own floor. |
| `workstation_number`, `desk_row`, `desk_position` | Where on the floor, as written on it. |
| `switch_port_id` | The port it is patched to — **unique**, one port holds one desk. Switch and rack come through it. |
| `port_split_number` | The split on the patch panel. |
| `vlan_id` | A VLAN at the desk's own site. |
| `computer_name`, `pc_serial`, `monitor_serial` | What is on the desk. |
| `ip_address`, `mac_address` | MAC stored canonically as `AA:BB:CC:DD:EE:FF`. |
| `notes` | Free text. |

Everything but the ID, floor and status is optional, and empty is a normal state
rather than an unfinished one: a desk exists physically long before anyone has
traced the cable. The record is declared once on the model —
`Workstation::DETAIL_GROUPS` for labels and grouping, `DETAIL_COLUMNS` and
`DETAIL_RELATIONS` for what counts as "has details" — so the form, the tables,
the filter, the ring on the plan and the public modal cannot disagree about a
desk.

Text fields are **strings, not integers, even where the field is called a
number**: real labels are alphanumeric — `Gi1/0/24`, `SW-03`, `A/B`.

MAC addresses are normalised: colon-separated, hyphenated, Cisco's
`aabb.ccdd.eeff` and bare hex are all stored as `AA:BB:CC:DD:EE:FF`. Anything that
is not twelve hex digits is refused.

Map positions are metres, not pixels: a drawing re-exported at another
resolution changes nothing, and resizing a floor rescales its map so everything
stays where it was over the drawing.

The dimensions are what everything that *draws* a floor needs. The plan takes
its shape from the ratio, the grid behind it is a real five-metre lattice, the
snap step is in metres, and the 3D slab is drawn at true size.

### Scale

A floor can hold hundreds of desks, which changes how each view behaves:

| | |
|---|---|
| **Admin map** | Objects live outside Alpine's reactivity and each change replaces one SVG group; pointer handling is delegated from the canvas. Names hide when zoomed out too far to read. The tray is searchable and capped. |
| **3D** | Each floor's desks are one `InstancedMesh`: 300 desks is one draw call, and hovering one costs the same as hovering one of six. |
| **Public plan** | Past 40 desks the markers collapse to the icon alone, with names on hover. |
| **Arranging** | 300 placements go in one transaction rather than 300 commits. |

## Where things live

```
app/
  Support/ModuleComponents.php          Panel ← module screen discovery
  Support/Permissions.php               the permission catalogue
  Support/Settings.php                  settings table, cached
  Support/Branding.php                  company name, logo, colour
  Support/Navigation/                   sidebar registry + trait for screens
  Support/Audit/                        AuditLogger, Auditable trait
  Support/Import/                       import pipeline: Importer, ImportRunner, Importers
  Support/Spreadsheet/                  reading/writing .xlsx and .csv (openspout)
  Filament/Pages/                       Dashboard, ComingSoon, ImportData
  Filament/Actions/                     SpreadsheetExportAction
  Providers/Filament/AdminPanelProvider.php
config/navigation.php                   the sidebar's default shape
Modules/Access/                         users, roles, default roles
Modules/Settings/                       Company, Navigation, Sites/Locations/Accounts/Departments
Modules/Audit/                          the audit log viewer
Modules/Workspace/
  app/Models/                           Building, Floor, Area, Workstation, Rack, NetworkSwitch, SwitchPort, Vlan
  app/Enums/WorkstationStatus.php
  app/Imports/WorkstationImporter.php   what a workstation spreadsheet means
  app/Exports/WorkstationExport.php
  app/Filament/Admin/Schemas/WorkstationDetailFields.php   the desk form, used everywhere
  app/Filament/Admin/Tables/WorkstationTable.php          columns, filters, actions, used everywhere
  app/Filament/Admin/Resources/
    Buildings/  Racks/  Switches/  Vlans/
    Floors/                             + Areas and Workstations tabs
      Pages/FloorPlan.php               the 2D plan page
    Workstations/                       flat, cross-floor view
  resources/views/filament/pages/
    floor-plan.blade.php                markup, scoped CSS, Alpine component
  database/migrations|factories|seeders/
  tests/Feature/
Modules/PublicSite/
  app/Support/BuildingGeometry.php      Eloquent → the scene payload
  app/Http/Controllers/                 BuildingController
  resources/js/building.js              the Three.js scene
  resources/views/                      building, floor, layout
  tests/Feature/
```

The plan page carries its own scoped `<style>` block rather than utility
classes. A Filament panel compiles its own CSS and would not include classes it
has never seen, so utility classes there render unstyled with no error. The
colours are custom properties redefined under `.dark`, so the page follows the
panel's theme.

`ModuleComponents::discover()` reads `Modules/*/app/Filament/Admin/{Resources,
Pages,Widgets}` off disk and hands them to the panel, so **adding a module
never means editing the panel provider**. It reads the module list from disk
rather than the `Module` facade because panel providers run before the modules
package has booted.

## Using it

Floors first — a workstation has nothing to attach to otherwise, and the "New
workstation" button stays disabled until at least one floor exists.

- **Workspace → Floors** — add a floor, then add its desks on the floor's own
  **Workstations** tab. This is the main path: you are looking at the floor, so
  the floor is not something you have to pick.
- **Workspace → Workstations** — every desk in the building, grouped by floor.
  For searching across floors and moving a desk from one to another.

Deleting a floor deletes its desks; the confirmation says how many.

## The floor map (Phase 2)

Each floor has a **Map** tab beside **Edit**, also reached from Floor Management
→ Floor Mapping. It is a map editor for the real floor: desks (drawn as desk,
monitor, keyboard and chair, with their ID), walls, rooms, doors, racks,
printers, columns, exit signs, text and custom objects — each a rotated
rectangle stored in **metres**, with height.

**View** (the default, and all somebody without `floors.arrange` gets): pan,
zoom, switch between **Plan** and **3D** (isometric), click a desk for its
details. The monitor's colour is the desk's status.

**Edit** (with `floors.arrange`):

| | |
|---|---|
| Add something | Pick it in the left toolbar and click the map. Walls and rooms: drag from corner to corner |
| Put a desk on the map | Drag it from the **Not on the map** tray, click it then the map, or use the Workstation tool (takes the next desk in the tray; offers to create one if the tray is empty) |
| Fill one bank of desks | **Fill area** — drag a box round the bank, say how many across and down and which way they face |
| Everything else in the tray | **Arrange the rest** |
| Select several | Shift-click, Shift-drag a box, or Ctrl+A |
| Move / rotate / resize | Drag it / the round handle / a corner handle, or type values in the right panel |
| Rotate 90°, duplicate, delete | R (Shift+R back), Ctrl+D, Delete — or the buttons |
| Nudge | Arrow keys, one snap step (Shift ×10) |
| Undo / redo | Ctrl+Z / Ctrl+Y |
| Save | **Save** or Ctrl+S |
| Lock something in place | **Locked in place** in the panel |
| Pan / zoom | Drag empty space, middle button or Space+drag / scroll wheel |

Snap (0.1–1 m), grid, names and "low walls" (so the 3D view can see over them)
are toggles in the bars. The bottom bar shows the cursor's position in metres.

**Everything is a draft until Save.** The editor keeps the map in the browser —
that is what makes undo possible — and sends the whole map on Save.
`SaveFloorMap` treats that payload as untrusted: registered types only, numbers
bounded, settings filtered per type, object ids must already be on this floor,
a workstation object must be a desk on this floor and only once. It writes in
one transaction and one audit entry ("map saved", naming the desks moved).
Every save bumps `floors.map_revision`; a save from an editor opened before
somebody else's save (or before "Add many" placed desks, or the floor was
resized) is refused with a reload prompt rather than overwriting theirs.
Leaving the page with unsaved changes asks first.

**Object types are data.** `Modules/Workspace/app/FloorMap/FloorObjectTypes.php`
registers each one: label, toolbar icon, default size and height, colour,
settings it takes, drawing layer, and which renderer draws it (`workstation`,
`desk`, `box`, `room`, `wall`, `door`, `marker`, `text`). A module adds a type —
a fire extinguisher, a camera — with `app(FloorObjectTypes::class)->register(...)`
from its service provider. Only a genuinely new shape needs a renderer in
`floor-map.js`.

**Resizing a floor rescales its map** (`ScaleFloorMap`): positions scale so
everything stays on its spot over the drawing, and walls and rooms stretch;
furniture keeps its size.

**How the editor is built.** `Modules/Workspace/resources/js/floor-map.js` is
one Alpine component with no dependencies, loaded on demand by Filament
(`x-load`) from `public/js/app/components/` — `php artisan filament:assets`
copies it there, and the composer post-install hook runs that. The map objects
are deliberately *not* Alpine-reactive: hundreds of desks behind reactive
proxies would re-run thousands of bindings per drag frame. They live in a plain
Map and each changed object replaces only its own SVG group. Everything written
into the SVG is HTML-escaped — labels, desk names and sign text are typed by
people. The stylesheet is `resources/css/floor-map.css`, dark whatever the
panel's theme.

The public floor page and 3D view still receive desk positions as percentages;
they are computed from the map objects (`Workstation::planPosition()`), so
nothing changed for them.

**Migrating**: every desk that had a position becomes a workstation object at
the same spot (1.4 × 1.5 m, facing up), and `workstations.position_x/y` are
dropped. `FloorMapScalingTest` runs that against old-shaped rows.

## Search and locate (Phase 3)

**One search, everywhere.** `Modules\Workspace\Search\WorkstationSearch` is the
only search for desks: the Search Workstation page, Ctrl+K, the map's find box
and locate links all go through it. It searches the Workstation ID and number,
PC name, IP, MAC, serial numbers, port split, the port (name and number), the
switch (number, name, management IP), the rack and the VLAN. The search is split
into words and every word has to match something, so `SW-11 Gi1/0/3` finds the
desk on that port. A MAC is found however it is typed — `00:1A:2B…`,
`001a.2b3c.4d5e`, bare hex or part of one. LIKE wildcards typed in are searched
for literally. An exact Workstation ID comes first.

**Search Workstation** (`/admin/search-workstation?q=…`): results as you type,
each saying which field matched. `/` focuses the box, the arrow keys move, Enter
opens the details. Needs `workstations.view`.

**Details** (`WorkstationDetailsAction`): a slide-over with the whole record,
whether the desk is on a map, and its recent audit entries (with `audit.view`).
It has buttons for **Locate on map**, **Edit** (with `workstations.update`),
**View history** (the audit log filtered to that desk) and **View asset**, which
stays disabled until Phase 5. It is also a row action on every workstation table.

**Locating.**
- `/admin/floors/{floor}/plan?locate=A-03` opens the map. It flies to the desk
  (pan and zoom over 0.7 s, or instantly with reduced motion), selects it, and
  pulses a halo round it with its name above for 8 seconds. Esc clears the glow.
- `/admin/locate?q=…` is a link for tickets where the floor isn't known. It goes
  straight to the map when the search names exactly one desk that is on one.
  Otherwise it opens the search with that text.
- Ctrl+K results for a desk on a map go to it there.
- The map's own find box looks on its floor first. Then it asks the server and
  switches floors, unless there are unsaved changes.

## Employees (Phase 4)

`Modules/Employees`: Asset Management → **Employees Data**. The OID is the
unique key, and the imports match on it.

**What an employee record holds**
- **Work details:** OID, employee number, name, email, mobile, job title and
  status (Active / On Leave / Left), plus joining and leaving dates.
- **Where they work:** department, account, site and location, picked from the
  Settings lists. A list entry an employee uses can't be deleted.
- **Emergency contacts:** two of them (`emergency_contacts`, slot 1 and 2).
  Clearing a contact's name in the form removes that contact.
- **Profile page:** shows the whole record, the recent history of the employee
  and their contacts (with `audit.view`), and an "Assigned assets" section that
  fills in with Phase 6.

**Personal data** is the national ID, the home address and the emergency
contacts.
- **Who sees it:** only people with `employees.view_sensitive`; by default that
  is only IT Admin and Super Admin. For anyone else the form, profile, list,
  export and import error report are *built without* those fields. A hidden
  Filament field would still send its value to the browser in the Livewire
  state. `EditEmployee::mutateFormDataBeforeFill` strips them as well.
- **National ID storage:** encrypted at rest (`encrypted` cast). Next to it is
  `national_id_hash`, an HMAC with the app key, so the same ID on two employees
  is still caught without the ID being readable. Changing `APP_KEY` makes both
  unreadable.
- **Audit log:** records *that* personal fields changed, never their values.
  People who can read the log may not be allowed to see the data.
- **Lists:** show the ID masked (`•••• 4567`); the profile shows it in full.

**Workday import** (Import from Workday, through the shared pipeline).
- **No live connection:** the app never talks to Workday. Export the worker
  report to Excel and import it here.
- **Headings:** each column also answers to its common Workday names ("Hire
  Date", "Job Profile", "Supervisory Organization", "Program", "Primary
  Emergency Contact"…).
- **Dates:** ISO, day-first (`01/03/2024`), with a month name, or an Excel serial
  number.
- **Settings lists:** departments, accounts, sites and locations that don't
  exist yet are created, and the preview says so first.
- **Re-importing:** by default a row updates the employee with the same OID, and
  empty cells leave existing values alone.
- **Personal columns:** imported only by someone allowed to see them. For anyone
  else they are skipped, with a note on the row.
- **Encryption in the pipeline:** between check and import, the rows wait in
  `import_rows` with personal columns encrypted (`Importer::sensitiveFields()`).
  The preview never shows them.

**Workday columns are a working assumption**: the headings above are what
Workday worker reports commonly use. Send a real export's heading row and the
aliases will be matched to it.

## Assets (Phase 5)

`Modules/Assets`.

**The catalogue** lives under Settings: Asset Types, Manufacturers and Asset
Models, all on the shared list screen.
- **Asset types:** each type says whether it has a computer name (laptops,
  desktops) and whether it is a headset.
- **Models:** a model is one manufacturer's product of one type, and its name is
  unique per manufacturer.
- **In use:** a catalogue entry something points at can't be deleted.
- **Permissions:** `catalogue.*`.

**An asset** is one physical thing, known by its serial number (unique).
- **Identity:** type, model, asset tag (unique) and computer name.
- **State:** status (Available, Assigned, Returned, In Repair, Lost, Retired)
  and condition (New, Good, Fair, Damaged).
- **Place:** site, location and account.
- **Holder:** who holds it and since when.
- **Purchase:** purchase date and warranty expiry.
- **Status follows the holder:** giving an asset to somebody marks an available
  or returned asset Assigned; clearing the holder puts an Assigned asset back to
  Available. An Assigned asset has to be with somebody.
- **Holders can't be deleted:** an employee holding assets is kept.
- **Asset history:** every save writes `asset_history`, whichever screen or
  import made it. It records the event (registered, assigned, unassigned,
  reassigned, moved, relabelled, status changed, updated) and each field before
  and after, *in names*: "Floor 2 → Floor 3", "Ahmed Hassan (1234567)". The
  history can't be edited, and a save that changes nothing writes nothing. The
  audit log records the same writes as well.

**Search For Assets** (`/admin/assets`)
- **Search:** one box, and every word has to match something: serial, tag,
  computer name, holder name or OID, type, model, manufacturer, site, location,
  account.
- **Filters:** status, type, manufacturer, model, condition, site, location,
  account, holder, "with somebody", headsets, warranty (expired / ends within
  30 days / not recorded), and purchase date.
- **Row actions:** View, Edit, and **History** (a slide-over). **Assign** and
  **Return** are shown but disabled: they need the handover form and the
  assignment record, which come in Phase 6.
- **Bulk actions:** set status (it won't mark an asset Assigned without a
  holder), move, export, delete.

**Update Assets** (`/admin/update-assets`) is for going round with a barcode
scanner.
- **Scan:** scan or type a serial, tag, computer name or the **OID of whoever
  holds it** and press Enter. The asset opens with the fields that change day to
  day and its recent history. If the text could mean several assets — an OID
  with two laptops against it, say — it asks which.
- **Why:** pick a **reason** for the change (replacement, damage, upgrade, lost,
  end of life, correction…) and add a note. The reason is kept on the history
  entry that save writes — the id, so it can be counted and filtered, and the
  name as it read at the time, so renaming a reason later does not rewrite
  history. It applies to that one save, not to the next.
- **The list of reasons** is managed in **Settings → Update Reasons**
  (`/admin/update-reasons`, `catalogue.*`): add your own, switch off the ones
  you have stopped using, and see how many times each has been given. A reason
  already on a history entry cannot be deleted — switch it off instead. Anyone
  who may add catalogue rows can also add a reason from the Update Assets screen
  without leaving it.
- **Save:** the box is ready for the next scan. Needs `assets.view` and
  `assets.update`; IT Technicians have both by default.

**Import / export**
- **Matching:** by serial number, with the usual aliases ("S/N", "Service Tag",
  "Make", "Hostname"…).
- **Catalogue and Settings:** missing types, manufacturers and models are
  created (a model needs its manufacturer named), and so are missing sites,
  locations and accounts, when the option is on.
- **Holders:** given by OID and must already be employees.
- **Checks:** tags unique, a model has to match its type, and the warranty
  can't end before the purchase date.
- **Round trip:** an export imports back with no changes and no history noise.

**Links to other modules**, registered by the Assets service provider so the
other modules don't depend on it:
- **Workstation details:** **View asset** / **View monitor** find the desk's PC
  and monitor serials in the register.
- **Employee profile:** gains an **Assigned assets** section.

## Assigning and returning (Phase 6)

**Who holds an asset changes only on two screens**, which leave papers and a
record. The holder field on the asset form and on Update Assets is read-only
now. An asset nobody holds can't be marked Assigned, and one somebody holds
can't be marked Available or Returned.

**Assign Assets** (`/admin/assign-assets`)
- **Pick the employee** by name or OID. People who have left aren't offered.
- **Gather the assets:** scan a serial or tag, search, or arrive from an asset's
  or employee's own **Assign** button. Only free assets (Available or Returned,
  held by nobody) can be added.
- **Assign** hands them all over in one transaction (`Actions\AssignAssets`):
  - every asset is locked and re-checked inside it, so two people can't hand
    out the same laptop, and it's all or nothing;
  - each asset becomes Assigned to the employee, which writes its history and
    opens its assignment;
  - one handover form is made (`HO-2026-000123`) and one audit entry recorded;
  - the printable form opens straight after.
- **Employee panel** beside the list: what they hold now, and their assignment
  history with links to the forms.

**Return Assets** (`/admin/return-assets`)
- **Find what's coming back:** scan, search, or pick an employee and "add
  everything they hold".
- **Condition:** set per asset.
- **Details:** return date and time (not in the future), who brought it back,
  where it's put back, and notes.
- **The return** (`Actions\ReturnAssets`) closes each assignment, records an
  `asset_returns` row (received by the signed-in user), and marks the asset
  Returned with its new condition and location. Assets from several employees
  get a receipt each (`RT-…`).

**The ledger** (`asset_assignments`) is one row per spell an asset spends with
somebody.
- **Kept in step by the asset itself** (`Asset::syncAssignments()`), so an import
  or a pre-Phase-6 holder leaves the same trail and can be returned normally.
- **Migration:** opened a spell for every asset already held.
- **Deleting employees:** anyone who ever signed for an asset can't be deleted.

**Handover forms and receipts** (`handover_forms`)
- **Frozen snapshot:** each keeps what it said at the moment it was made:
  employee, emergency contacts, assets with make/model/serial/tag/condition,
  who issued or received, and notes. A reprint years later is the same paper,
  even if the employee has changed department or an asset has a new tag.
- **Encryption:** the snapshot is encrypted at rest because it holds the
  contacts, and the audit log never records it.
- **Printing:** the print page is a panel page drawn without the panel. It's
  sized for A4 and printed with the browser's print dialog, which also saves a
  PDF. That means no PDF library (the plan named dompdf) and nothing online, and
  Arabic names print correctly.
- **Emergency contacts on paper:** filled in only for someone with
  `employees.view_sensitive`. Anyone else gets blank lines to fill in by hand.
- **Copies:** every opening counts as a print. From the second one the paper
  carries a COPY watermark, and each print is audited (`printed` /
  `reprinted`).
- **Archive:** Asset Management → **Handover Forms** lists them all to search and
  reprint. Nobody can edit or delete a form.

**Search For Returned Assets** (`/admin/returned-assets`)
- **Search:** employee name or OID, serial, tag, who brought it, who received
  it.
- **Filters:** date, condition, employee, receiver, site, location, type.
- **Also:** export, and the receipt to reprint.

**Permissions:** `assignments.view`, `.assign`, `.return` and `.print`. IT
Engineers and IT Technicians assign and return by default; everyone who views
sees the papers.

## Releasing new assets and headsets (Phase 7)

**Release New Assets** (`/admin/release-new-assets`) is a working assumption
built from the legacy button names alone. No screenshots or specs have arrived
yet, so expect it to change once they do.

**A release batch** (`release_batches`, `RB-2026-00001`) is the "new data" of one
delivery.
- **Header dropdowns:** type, model, site, location, account, purchase date,
  warranty.
- **New Data Table** (`release_batch_items`): one row per piece, with serial,
  tag, computer name, the OID of who it's for (empty means stock), condition and
  notes.
- **Adding rows:** **Add New Data** adds them one at a time, or **Paste rows**
  takes lines copied from Excel (tab, comma or semicolon separated). Up to 1,000
  rows per batch.

**The three checks** (`Actions\CheckReleaseBatch`) each write their findings onto
every row. Editing a row marks it unchecked again.
- **Check Employees Data:** each OID must be an employee who hasn't left. A
  missing OID only warns that the row goes into stock.
- **Check New Data:** a serial on every row, no serial or tag used twice in the
  batch, and a warning when a computer type has no computer name.
- **Check New Data with Old Data:** no serial or tag may already be in the
  register.

**Print New Data Forms and Archiving** (`Actions\ReleaseBatchAssets`) needs
`releases.release` and `assignments.assign`.
- **Checks first:** it runs every check and saves the findings, so a refusal
  leaves the problems marked on the rows.
- **Then one transaction,** with the batch locked and the checks run again:
  1. every row becomes an asset with the batch's header values;
  2. rows with an OID are handed out through the same Assign action as the
     Assign screen, one handover form per employee;
  3. the batch is archived.
- **Archived batches** are read-only.

**Printing**
- **Quick Print / PDF:** prints a draft's rows with their check results,
  watermarked NOT RELEASED. Nothing is recorded.
- **Re-Print Specific New Data Report:** prints what a released batch made, with
  each row's form number. Every print is counted and audited.

**Permissions:** `releases.view`, `.manage` and `.release`; IT Engineers stage and
release by default.

**Adding New Headsets Data** (`/admin/headsets`)
- **One at a time:** pick the headset type, model, condition and place once,
  then scan serial after serial. **Save and add another** keeps everything but
  the labels — including the cord's model, but not its serial. A "Headset" type
  is created if no headset type exists yet.
- **The cord** it came with is kept on the headset itself — **Cord Model**,
  **Cord S/N**, **Cord Status** — because that is how it is delivered, used and
  thrown away. A cord serial belongs to one headset, is searchable like any
  other serial, shows on the asset's page and in the Headsets report, and a
  swap is written to the asset's history.
- **Bulk:** **Upload a spreadsheet** uses `HeadsetImporter`, and its
  **Download template** is the ops sheet itself:

  | OID | Name | Headset Model | Headsets S/N | Headset Status | Cord Model | Cord S/N | Cord Status | Site | Received Date | Account |
  |---|---|---|---|---|---|---|---|---|---|---|

  The sheet's habits are taken as read:
  - **A dash** (`-`, `n/a`, `none`…) means nothing is there, not a cord called "-".
  - **The manufacturer is written into the model** — "Jabra BIZ 1500 Direct USB"
    is a Jabra, model "BIZ 1500 Direct USB". A manufacturer already in the
    catalogue is matched however many words its name is; otherwise the first
    word is taken as the maker.
  - **Headset Status** is one word for two things: "New" means Available and in
    New condition, "Damaged" describes the condition and leaves where it is to
    the OID. A word that is neither marks the row invalid and says so.
  - **Site** by name or code ("ALX"), **Received Date** as the purchase date,
    **Account** by name or code, created when missing if the option is on.
  - **OID** decides who holds it; **Name** is the office's own cross-check, so a
    name that disagrees is a warning on the preview and the OID wins. It is
    personal data: it waits encrypted between the check and the import.
  - **Cord S/N** is checked for being on another headset, or twice in the file.
  - Files with the older asset headings ("Serial Number", "Manufacturer",
    "Asset Tag", "Location", "Notes"…) still import: those columns are
    understood, just left out of the template.
- It reports **Total / Successful / Duplicates / Failed**, and the error report
  lists every row that didn't go in.
- **Every import now reports this way:** duplicates are rows already in the
  application or repeated in the file.

**The Release Data Form** (`/admin/import?importer=release-form`, or **Upload the
release form** on a draft's New Data Table) fills a whole release from the
office's own sheet. **Download template** is that form:

| Serial | Employee_ID | Employee_Name | Mobile_No | Laptop_Name | Laptop_Model | RAM | Laptop_Service_Tag | Laptop_Owner | Account | LOB | Site | Delivery_Date | Emergency_Contact1 | Emergency_Contact2 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|

- **Which release:** the rows go onto a **draft** batch, chosen on the upload
  screen — the batch screen's own button fills it in. The batch's type, model,
  site, location, account and dates are what the rows are released as, so start
  the release first.
- **Serial** is the line number in the sheet; the machine's serial is
  **Laptop_Service_Tag**, and that is what the asset is created with.
- **RAM**, **Laptop_Owner**, **LOB** and **Delivery_Date** belong to the
  delivery rather than to the asset, so they are kept on the batch's row, shown
  in the New Data Table and printed on the report.
- **Laptop_Model**, **Account** and **Site** are read as a cross-check: a row
  that disagrees with the release says so on the preview, and the release
  decides. **Employee_ID** decides who a laptop is for; **Employee_Name**,
  **Mobile_No** and the two emergency contacts are checked against the employee
  record the handover form will print — a name or mobile that disagrees, or
  contacts that are missing from the record, is a warning. Nothing is written to
  the employees: the OID must already be one.
- **Re-uploading a corrected form** onto the same release updates its rows,
  matched by service tag, rather than adding them twice (empty cells leave what
  is there alone).
- Rows are checked exactly as typed ones are — serials already in the register,
  tags on another asset, OIDs that are not employees or have left — and then the
  usual **Check / Print / Release** buttons do the rest.

## Reports (Phase 8)

Reports → **All Reports** (`/admin/reports`) lists every report the signed-in
user may open, grouped under headings.

**Opening a report** (`/admin/reports/{key}`)
- **The table:** a searchable, filterable, sortable table with columns you can
  switch on and off.
- **Export:** every matching row and every column to Excel or CSV. Needs
  `reports.export`.
- **Print / PDF:** needs `reports.print`. It opens the same table as paper.
  Search, filters and sort travel in the address bar (`?search=`, `?filters[…]`,
  `?sort=`), so the printout is exactly what the screen showed. The page records
  the row count, search and filters, prints up to 2,000 rows, and is saved as a
  PDF from the browser's print dialog. Each print is audited.
- **Who can open it:** anyone with `reports.view`, plus the permission to see
  what the report lists (`assets.view`, `workstations.view`, `audit.view`…).

**The reports**

| Group | Report | Rows |
|---|---|---|
| Assets | Asset Inventory | every asset |
| Assets | Asset Counts | **how many**: one row per account and asset type, with columns for the total, how many are with employees and how many in each status, and a grand total under every column on screen and on paper. Pick an account and a type to count just those |
| Assets | Assigned Assets | assets with somebody, with the handover form |
| Assets | Available Assets | nobody holds it and it can go out |
| Assets | Returned Assets | every recorded return |
| Assets | Non-Returned Assets | held by employees who have **left**, and days since they left. A working definition until the DIF MSA spec arrives |
| Assets | Employee Assets | one row per employee: how many assets and which |
| Assets | Headsets | assets of headset types, with the cord each came with |
| Assets | Movement History | every asset history event with before → after, and the reason given (filterable) |
| Floors and network | Workstations | the full patching record; the search is Search Workstation's |
| Floors and network | Floors | desks, desks on the map, and desks per status, per floor |
| Floors and network | Switch Ports | every port and what is patched into it (filter free ports) |
| Floors and network | Racks | switches, ports recorded / patched / free |
| Admin | Audit Trail | the audit log, with changes |

**Adding a report** takes one class and no page. Extend `App\Support\Reports\Report`:
- **key, label, group:** the report's identity and heading.
- **`authorize()`:** who may open it.
- **`query()`:** the rows.
- **`columns()`:** a list of `ReportColumn`. Each column reads its value once
  for screen, export and print.
- **Optional:** `filters()` (plain Filament table filters), `search()`,
  `landscape()` for a wide printout, and, on a column, `totalled()` for a sum
  under the rows (screen and print) or `sortableByAlias()` to sort by something
  the query works out itself, such as a count.

Register the class with `app(Reports::class)->register(...)` from the owning
module's service provider. The Reports module only holds the screens.

## Dashboard (Phase 10)

The panel home (`/admin`) is built from widgets that live in the modules that own
the numbers. Each widget checks that the signed-in user may see what it counts,
so a Viewer with only workstation rights sees workstation widgets and nothing
else. Charts use Filament's bundled Chart.js, served from this server.

| Row | Widget | Module | Needs |
|---|---|---|---|
| 1 | **Quick actions**: find a workstation, floor maps, assign, return, update assets, release new assets, add headsets, import from Workday, reports | core; each module registers its own tiles (`App\Support\Dashboard\QuickActions`) | whatever each tile's page needs |
| 2 | **Assets**: total and headsets, assigned, ready to hand out, in repair or lost, held by leavers, warranty ending in 30 days | Assets | `assets.view` |
| 3 | **Workstations**: total and floors, active, need attention (faulty / offline / maintenance), % on the map | Workspace | `workstations.view` |
| 4 | **Employees**: active, on leave, joined and left in the last 30 days | Employees | `employees.view` |
| 5 | **Assets by status** (doughnut) · **Assignments and returns** (last 12 weeks or months) | Assets | `assets.view` (+ `assignments.view`) |
| 6 | **Workstation status per floor** (stacked bars in the map's status colours) | Workspace | `workstations.view`, `floors.view` |
| 7 | **Recently assigned** · **Recently returned** | Assets | `assets.view`, `assignments.view` |
| 8 | **Recent activity** (latest audit entries) | Audit | `audit.view` |

Each summary card links to the list, already filtered to what it counts.
Widget order comes from each widget's `$sort`; the layout is documented on
`App\Filament\Pages\Dashboard`.

## Demo data

```bash
ddev exec php artisan db:seed
```

Fills every application table through the same actions the screens use:
- **Logins:** `admin@workspace.test` (Super Admin), plus `itadmin@`, `engineer@`,
  `technician@` and `viewer@workspace.test`, all with password `password`. The
  four role logins are never created when `APP_ENV=production`.
- **Settings:** company branding (only where not already set), two sites with
  their locations, client accounts (one retired) and departments.
- **Building:** the HQ Tower B building, its floor maps and patching record, as
  before.
- **Employees:** 90 employees, some on leave and some gone, with emergency
  contacts. Six more arrive through a Workday-style import, so there is an
  import batch to look at.
- **Assets:**
  - **Catalogue:** asset types, manufacturers and models, and ten update
    reasons to choose from on Update Assets.
  - **Register:** a PC and monitor for each traced desk on floors 0–2 (serials
    match the desks, so *View asset* works), and store stock of laptops,
    headsets, docks, webcams and keyboards.
  - **A year of use,** dated across the past year: handovers with forms, returns
    with receipts (one damaged), three leavers who kept their kit, a released
    delivery batch plus a draft with problems the checks point out, assets
    moved, repaired, lost and retired — each with the reason it was given — and
    a webcam stock-take import.

Model events are on while seeding, so asset history, the assignment ledger,
form numbers and the audit log are all written, attributed to Admin. Seeding
is idempotent: running it again adds only what is missing, and never resizes
a floor or rewrites an existing record.

## The public site

Server-rendered, no account, nothing writable.

| Route | |
|---|---|
| `/` | The building in 3D — every active floor stacked by storey number, desks standing on them |
| `/floors/{floor}` | One floor, flat |

**The building.** Drag to orbit, scroll to zoom, hover a desk for its name and
floor. Clicking a floor — in the scene or in the list beside it — flies the
camera to it and fades the rest; **Show all floors** returns. The **Spread**
slider pulls the storeys apart to see past the one on top. Once a floor is
focused, clicking a desk standing on it opens that desk's record.

**A floor.** The same plan the admin edits, drawn read-only, plus a list of
every workstation. Both the pins and the list rows open the same record. It is
a plain page: it is what a browser without WebGL gets, so it never loads the 3D
bundle — the modal script is a separate 1.6 KB entry.

Both follow the viewer's OS theme, with a toggle that overrides it and persists.

### The workstation modal

One `<dialog>`, one script, used by the flat plan and the 3D scene alike. Native
`showModal()` rather than a hand-rolled overlay: Escape, the backdrop, the focus
trap and returning focus to whatever opened it all come with the element.

What it shows is decided on the server. `Workstation::DETAIL_GROUPS` declares
the fields, their groups and their labels once, and both the admin form and this
modal read from it — a field cannot end up called one thing in the admin and
another on the floor page, and adding one never means editing JavaScript.

A group with nothing in it is left out. A blank *inside* a group that has
something is kept and shown as a dash, because "we have not traced this one" is
worth seeing where "no such field" is not. A desk with nothing recorded at all
says so in a sentence rather than showing nine dashes.

The payload is one `<script type="application/json">` block per page rather than
an attribute per desk — the same desk appears twice on a floor page, and a floor
can carry three hundred of them. It is encoded with `JSON_HEX_TAG`
(`BuildingGeometry::json()`), not Blade's `@json`: desk names and notes are
typed by an admin, and `@json` leaves `<` and `>` alone, so a note holding a
closing script tag would end the block early and run whatever followed it on a
page any visitor can open.

**The public site shows the patching record deliberately, and only that.**
`Workstation::PUBLIC_DETAILS` lists what it may show: site and building, area,
workstation number, switch, port, split, PC name, MAC and notes — what it showed
before Floor Setup existed. Fields added since (row and position, rack, VLAN, IP
address, serial numbers, status) are admin-only.
`test_the_public_site_carries_the_patching_record_on_purpose` and
`test_the_fields_added_since_stay_off_the_public_site` hold both halves of that
decision. To publish another field, add it to `PUBLIC_DETAILS`; to make the site
private, put the two public routes behind `auth`.

With more than one building the public site shows one at a time, with a switcher
in the header — the scene stacks floors by level, and two buildings' first
floors are not one on top of the other.

## Foundation (Phase 0)

The groundwork every later module stands on.

**Offline.** Nothing the browser loads may come from the internet.
`tests/Feature/OfflineAssetsTest.php` scans every view, stylesheet and script —
including the built and published assets — and fails on any CDN, web font or
remote script. Its one exception is dead code inside Filament's markdown editor,
with a test proving it stays dead.

**The sidebar** is `config/navigation.php`: groups (Floor Management, Asset
Management, Reports, Admin, Settings) and their entries, each with a stable
key. A screen claims its entry with the `HasConfigurableNavigation` trait and a
`$navigationKey`. Entries with a `phase` have no screen yet and open a "planned
for phase N" page. **Settings → Navigation** renames, reorders, regroups and
hides entries without touching code; only the differences from the config are
stored. Hiding an entry does not grant or remove access — policies still decide.

Filament allows icons on a group or on its entries, not both, so groups carry
the icons and only top-level entries (Dashboard) have their own.

**Settings → Company** sets the company and application name, the logo (light
and dark, stored on the server) and the primary colour. They apply to the panel
and the public building view on the next page load.

**Lookups** — Sites, Locations (within a site), Accounts, Departments — are real
tables with foreign keys, for the asset and employee modules to point at. A row
that something still uses cannot be deleted; switch it off instead.

**Audit log** (Admin → Audit Log). Any model using `App\Support\Audit\Auditable`
records creates, updates and deletes with only the changed fields, before and
after, plus user, IP and — if switched on in Company settings — the computer's
hostname. Hidden attributes such as passwords are recorded as `(hidden)`.
Query-level bulk writes skip model events, so the actions that make them log
their own entry ("arranged", "workstations added", "plan cleared"), as do role
changes. Entries cannot be edited or deleted, from the panel or the model.

**Default roles.** A migration creates Super Admin, IT Admin, IT Engineer, IT
Technician and Viewer. They are defined as patterns over the permission
catalogue in `Modules/Access/app/Support/DefaultRoles.php` — Viewer is `*.view`
minus users, roles, settings and audit — so a new module's permissions fall
into place by name. Existing roles are never overwritten. After deploying a new
module:

```bash
ddev exec php artisan access:sync-default-roles
```

adds the new module's matching permissions to the default roles and removes
nothing.

## Floor Setup (Phase 1)

**Buildings** (Floor Management → Buildings) sit on a site; floors belong to a
building, and each floor's edit screen has **Areas** and **Workstations** tabs.
**Network** → Switches, Racks and VLANs are the records desks are patched to. A
switch's screen lists its ports and which desk each one feeds, and **Add a
range** creates Gi1/0/1–48 in one go. Nothing that still has something pointing
at it can be deleted — a site with buildings, a building with floors, a rack
with switches, a switch or port with a desk on it, a VLAN desks are on.

**Workstations** list: filter by status, floor, switch, rack, VLAN, has details
and on the plan; search by ID, workstation number, PC name, switch, port, IP,
MAC or serial. Row actions Edit, **Duplicate** (keeps floor, area, row, split and
VLAN; clears port, IP, MAC, PC and serials; suggests the next free ID) and Delete.
Bulk: change status, set VLAN (skips desks at another site), export, delete.

**Export** writes what the list shows — filters and search applied — to Excel or
CSV, on the server, straight to the browser. Filament's own exporter needs a
queue worker and database notifications; the internal server runs neither.
Cells starting with `=`, `+`, `-` or `@` are written with a leading apostrophe so
Excel shows them as text instead of running them as formulas.

**Import** is shared infrastructure (`app/Support/Import`) that later modules
reuse:

1. Upload `.xlsx` or `.csv` (semicolon-separated CSVs from Excel are detected).
   Headings are matched loosely — "PC Name", "Computer Name" and "hostname" all
   work; unknown columns are listed and ignored; a missing required column
   refuses the whole file.
2. **Check** stores every row in `import_rows` and writes nothing else. Each row
   is New, Will update, Already exists, Duplicate (same record as an earlier
   row) or Invalid, with the reasons — no such floor, a floor name used in two
   buildings without a Building column, a port another desk or row already
   holds, a bad IP, MAC, VLAN or status.
3. **Import** writes only New and Will update rows, checking each again first
   (somebody may have changed things since the preview). Areas, switches, ports,
   racks and VLANs a row names are created if the option is on. When updating,
   empty cells keep the current value.
4. **Download error report** gives every row that did not make it in, with why.

The export's headings are the import's, so export → fix in Excel → import with
"update existing" changes exactly what was fixed. An importer is one class — see
`Modules/Workspace/app/Imports/WorkstationImporter.php` — registered in its
module's service provider.

New permissions: `network.*`, `workstations.import`, `workstations.export`. A
migration added them to the default roles that match (IT Admin everything, IT
Engineer import/export and network create/update, Technician and Viewer
network view).

**Migrating an existing install** carries the old free-text patching into the
new records rather than dropping it: every floor goes into one building (named
after the desks' site location if they all agreed on one), each zone becomes an
area, each switch and interface a switch and port the desk is patched to, and a
desk becomes Active if it had a PC name, Available if not. Anything without a
place of its own — a second desk on the same port, a switch with no interface, a
site location that differs — is appended to the desk's notes.
`FloorSetupMigrationTest` runs it against old-shaped rows.

One schema note: `floors.building_id` is nullable in the database and required
everywhere in the application. Making it NOT NULL, or adding its foreign key
through the schema builder, makes SQLite rebuild the floors table — and dropping
the old table cascades to every workstation on it. MySQL is unaffected, but a
migration that deletes desks on one database engine is not shipped.

## Users, roles and permissions

The `Access` module adds **Users** and **Roles** to the admin panel.

- A role is a name plus a set of permissions, ticked on the role screen in
  groups: Floors, Workstations, Users, Roles. A user can hold several roles;
  their permissions add up.
- A role with **Super admin** switched on skips the permission checks entirely,
  so it also covers permissions added in future. `admin@workspace.test` holds
  the seeded **Super Admin** role.
- **An account with no role cannot sign in.** The upgrade migration gave every
  account that existed before roles the Super Admin role, because until then
  every account could do everything.
- `floors.view` alone gives a read-only plan: pins open their details, nothing
  drags. `floors.arrange` is what lets desks move. The plan's write methods
  check it on the server, not just by hiding buttons.

Permissions are declared in code — each module registers its own in its service
provider through `App\Support\Permissions`, and a policy checks them. A
permission that only existed as a database row would be a checkbox that does
nothing.

Guards against taking over or locking the panel:

- only a super admin can switch the flag on, assign a super admin role, or
  edit or delete a super admin user or role;
- nobody can delete their own account;
- the last super admin cannot be deleted, lose the role, or have the flag
  taken off their only super admin role.

Two permissions are powerful on their own and worth giving sparingly:
`roles.update` lets somebody add permissions to any non-super role — including
one they hold — and `users.update` lets them hand any non-super role to anyone.

Locked out anyway (a database restored from elsewhere, say)?

```bash
ddev exec php artisan access:grant-super-admin admin@workspace.test
```

## Deliberately not built yet

A desk has no occupancy or assigned person yet, and on the plan it is a marker
rather than a sized footprint on the public 3D view, which still draws every
desk as the same block facing the same way — the admin map has real sizes and
rotation. Floors have a size but no shape: they are rectangles, so an
L-shaped floor has to be approximated by its bounding box until a plan drawing
is uploaded behind it.

The public site has no access control at all — **anyone with the URL can see
every floor, every desk name, and the patching record: switch, port, PC name
and MAC.** That was a deliberate choice, made explicitly rather
than by omission. It is also the one worth revisiting first: that
combination is a readable map of the network, and if desk names start mapping to
people it is a map of who sits where as well. Putting the two routes in
`Modules/PublicSite/routes/web.php` behind `auth` is the whole change.
