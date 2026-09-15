# Workspace OS

Floors, and the workstations that physically sit on them.

Two halves:

- an **admin panel** where you record every floor and every desk, and drag each
  desk to the spot it occupies in the real room;
- a **public site** where anyone can walk the building in 3D and see where the
  desks are, without an account and without being able to change anything.

A desk needs nothing but a name. Give a floor its architect's drawing and the
desks are placed on the drawing itself; give it nothing and they are placed on a
blank grid instead. Positions are percentages either way, so desks placed today
against a grid land in the same relative spot the day a drawing arrives.

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
ddev exec php artisan test          # 135 tests
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
Floor  ──< Workstation
```

**`floors`**

| Column | Notes |
|---|---|
| `name` | Unique. "Ground Floor", "First Floor". |
| `level` | Unique. `0` is ground, `1` above it, `-1` a basement. Sorting by it walks the building bottom to top. |
| `width_m`, `depth_m` | How big the floor really is, in metres. Default 60 x 40 (2,400 m2, about 300 desks). |
| `description` | Free text. |
| `is_active` | Retired floors keep their desks but drop out of day-to-day lists. |
| `plan_path` | The floor-plan drawing this floor is placed against. Optional; without one the plan is a blank metre grid. |

**`workstations`**

| Column | Notes |
|---|---|
| `floor_id` | Cascades on delete — a desk cannot outlive its floor. |
| `name` | Unique **per floor**. "A-01" on the second floor is a different desk and a legal name. |
| `site_location` | Which site or building the desk is in. |
| `zone_number` | The zone it falls in. |
| `workstation_number` | The number on the desk itself, where that differs from the name. |
| `port_split_number` | Which side of a split port it takes. |
| `switch_number` | The switch it lands on. |
| `interface_number` | The interface on that switch — `Gi1/0/24`. |
| `computer_name` | The machine plugged into it. |
| `mac_address` | That machine's NIC, stored canonically as `AA:BB:CC:DD:EE:FF`. |
| `notes` | Free text. |
| `position_x`, `position_y` | Where the desk sits on the plan, as a **percentage** of plan width/height. Null until it is placed. |

Everything below `name` is optional, and empty is a normal state rather than an
unfinished one: a desk exists physically long before anyone has traced the
cable. Which columns make up that record is declared once, as
`Workstation::DETAIL_COLUMNS`, so the modal, the tables, the "has details"
filter and the ring on the plan cannot end up disagreeing about a desk.

They are **strings, not integers, even where the field is called a number**.
Real switch and interface labels are alphanumeric — `Gi1/0/24`, `SW-03`, `A/B` —
and a column that cannot hold what is printed on the equipment is not a record
of it. Nothing here is arithmetic, so nothing is lost.

MAC addresses are the exception that gets normalised: they arrive
colon-separated, hyphenated, as Cisco's `aabb.ccdd.eeff` or as bare hex, and the
model stores all four as `AA:BB:CC:DD:EE:FF` so the same NIC is written the same
way whichever screen it was typed on. Anything that is not twelve hex digits is
refused by the form.

Percentages rather than pixels: the drawings are not here yet, they will not
all be the same size when they are, and a plan re-exported at another
resolution would invalidate every pixel offset. Percentages survive it — and
they survive **resizing a floor** too, which is why adding dimensions moved
nothing that was already placed.

The dimensions are what everything that *draws* a floor needs. The plan takes
its shape from the ratio, the grid behind it is a real five-metre lattice, the
snap step is in metres, and the 3D slab is drawn at true size. Without them a
300-desk floor comes out as a pile of overlapping blocks on a room the size of
a meeting table.

### Scale

A floor can hold hundreds of desks, which changes how each view behaves:

| | |
|---|---|
| **Admin plan** | Pointer and key handling is delegated from the surface, not bound per pin. Past 60 desks the pins drop their name badge to the icon alone and show the name on hover or when selected — the **Names** button forces them back on. The unplaced tray is searchable and capped. |
| **3D** | Each floor's desks are one `InstancedMesh`: 300 desks is one draw call, and hovering one costs the same as hovering one of six. |
| **Public plan** | Past 40 desks the markers collapse to the icon alone, with names on hover. |
| **Arranging** | 300 placements go in one transaction rather than 300 commits. |

## Where things live

```
app/
  Support/ModuleComponents.php          Panel ← module screen discovery
  Providers/Filament/AdminPanelProvider.php
Modules/Workspace/
  app/Models/                           Floor, Workstation
  app/Filament/Admin/Resources/
    Floors/                             + WorkstationsRelationManager
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

## The plan

Each floor has a **Plan** tab beside its **Edit** tab — a 2D view of that floor
where every desk is a pin you position where it physically stands.

| | |
|---|---|
| Move a desk | Drag it |
| Put a desk on the plan | Drag it out of the **Not placed yet** tray, or focus it and press Enter |
| Take a desk off | Drag it off the plan, or select it and press Delete |
| Nudge precisely | Arrow keys on the selected desk |
| Pan / zoom | Drag empty space / scroll wheel, or the −, +, **Fit** buttons |
| Fill the plan in one click | **Auto-arrange remaining** — grids up everything still in the tray, leaving placed desks alone |
| Fill one bank of desks | **Fill an area** — draw a box round it on the drawing and say how many across and down |
| Make the floor match its drawing | **Set size from drawing** — give the real width, the depth follows |
| Start over | **Clear plan** — empties the coordinates, keeps the desks |
| Create a lot of desks | **Add many** — a prefix, a count and a starting number gives you `A-001 … A-300` in one go |
| See or edit a desk's details | **Click it** — opens the whole record |

Each desk is drawn as a small computer. **Grey means nothing has been recorded
about it yet, indigo means something has** — at three hundred desks that is the
only form of "what have we not done" that can be read without opening anything,
which is why it is the tile's colour rather than a ring around it.

**Snap** is on by default, in **metres** (0.5 / 1 / 2 / 5) rather than
percentages — so "snap to 2 m" means the same thing on a 60 m floor as on a
12 m one. The counter reads `n of m placed` beside the floor's size.

Auto-arrange lays desks out in a grid shaped to the room: a long, narrow floor
gets more columns and fewer rows, which a fixed 3:2 assumption would get
backwards.

### Fill an area

Auto-arrange is the right tool for a blank grid and the wrong one for a real
drawing — it grids the whole floor plate, putting desks through walls, lifts and
the atrium. **Fill an area** is the one for a drawing: turn it on, drag a box
round a bank of desks, and say how many go across and down.

- Desks come off the **Not placed yet** tray in name order, so a zone ends up
  holding a contiguous run — `A-001` to `A-024` — rather than a scatter.
- The box's edges are where the outermost desks go, because the box was drawn
  round a bank, not inside one.
- The count starts from the box measured **in metres**, at about 1.6 m across
  and 1.5 m front to back per desk, then you correct it. That default is only as
  good as the floor's recorded size, which is the other half of this.
- Already-placed desks are never touched. Ask for more than the tray holds and
  it places what there is and says how many more need creating with **Add many**.
- Middle-drag still pans while the mode is on. Escape leaves it.

Working a real plan is then: **Add many** to create the desks, **Fill an area**
once per bank, done. That is exactly how the seeded ground floor is built — it
calls the same action — so the demo floor is one anybody using the panel could
have made.

### Desk details

Clicking a pin opens a modal with the desk's whole record — where it is, how
it is patched, what is plugged into it, and free-text notes — grouped into
**Location**, **Patching** and **Machine**. Nine inputs in one flat column is a
wall; three short groups is a form somebody can fill in from a patching sheet
without losing their place. Every field is optional. Dragging is untouched — a press only becomes a drag once the pointer
moves more than four pixels, so a real mouse's wobble still counts as a click
rather than nudging the desk. Keyboard: tab to a pin, arrow keys nudge it,
Enter opens the details, Delete takes it off the plan. In the tray, Enter drops
a desk in the middle and `D` opens its details.

Pins with anything recorded carry a ring, so "which desks have we not done yet"
is answerable by looking rather than by opening three hundred modals. Saving
updates that one pin in place — the plan sits behind `wire:ignore`, so the
modal reports back through an event instead of reloading the page and throwing
away your zoom and pan.

The same fields are on the floor's **Workstations** tab and on the cross-floor
**Workstations** resource, defined once in `WorkstationDetailFields` so they
cannot drift apart. That list carries every one of them as a searchable,
sortable column plus a **Has details** filter; eight columns beside the name,
floor and placement does not fit on a screen, so all of them are toggleable and
only the four that identify one specific machine are on by default.

**All of it is on the public site too**, deliberately — see
[The public site](#the-public-site) for what that means and the one line that
changes it.

### Add many

On the floor's **Workstations** tab and on its **Plan**. Give it a prefix, a
start number, a count and a zero-padding width; it previews the first and last
name before you commit. Names already on that floor are skipped rather than
failing the batch, so re-running it to fill a gap works. Capped at 1,000 per
batch. Optionally arranges them on the plan straight away.

The whole interaction runs in the browser — drag, zoom and pan never touch the
server. Only a settled coordinate is written back, optimistically, and rolled
back in the UI if the write fails. Every write is scoped through the floor's
own relationship, so a desk on another floor cannot be moved from here.

### Placing against a real drawing

Upload one per floor under **Edit → Floor plan**. It becomes the backdrop and
the coordinate space: a desk at 40%, 60% is 40% across and 60% down *the image*.
Pins already placed keep their positions, because a percentage means the same
thing on a grid and on a drawing. Crop each image to the floor outline before
uploading — the whole image is the coordinate space, margins included.

Both the editor and the public page then frame the plan at **the drawing's own
shape**, measured from the file with `Floor::planAspectRatio()`, not at the
floor's width and depth. Those two are rarely the same number, and a 1.4 drawing
in a 1.5 frame is letterboxed — every desk ends up marking the one beside it. An
SVG has no size a header can report and a file can go missing; both fall back to
the floor's metres, which is what the product did before drawings existed.

Nothing about placement changes: a desk goes wherever you drag it, over a desk
bank, a meeting room or the middle of the atrium. The drawing is a backdrop, not
a set of slots.

**Set the floor's size too.** A drawing has a shape but no scale — 1088 x 778
pixels could be a meeting room or a city block — so **Set size from drawing**
asks for the one measurement somebody knows, the real width, and takes the depth
from the drawing. Worth doing rather than leaving the two to disagree: the snap
step is in metres, so is the capacity warning, so is the default grid for a
filled area, and so is the slab in the 3D building. A floor still recorded as
24 x 16 with a full floor plate drawn behind it is wrong in all four at once.

`ddev exec php artisan migrate:fresh --seed` builds a demo building whose ground
floor is placed against a real architect's plan, with its seventy-two desks
standing in the four zones that drawing marks out — the case worth having on
hand, because a grid of desks looks exactly as convincing over a blank page.

`Workstation::isPlaced()`, the `placed()` / `unplaced()` scopes and the **On
plan** column in both tables answer "what have we not positioned yet" at any
point.

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

**Everything a desk carries is public**, including the switch, interface and MAC
— that was an explicit decision, and
`test_the_public_site_carries_the_whole_record_on_purpose` is what makes it one:
the day that record should be private again, it fails and says so, rather than
the data quietly staying visible. To make it private, narrow `detailGroups()` or
put the two public routes behind `auth`.

### How the scene is built

`BuildingGeometry` is the only place Eloquent meets metres. Floors and desks
come out as a plain array embedded in the page — `building.js` never calls an
API. From there:

- a floor is a slab at `level × storey height`, sized in real metres from
  `width_m` / `depth_m`, so `level` drives the stacking, a basement genuinely
  sits below the ground floor, and a big floor plate looks big;
- a desk is a block at `(x%, y%)` of the slab, the same coordinate space it was
  dragged into in the admin;
- **only placed desks are drawn.** An unplaced desk has no position, and
  inventing one would put a desk in the room that is not standing there. The
  count of what is missing is shown instead.

Lighting, fog, zoom limits, label size and the default **Spread** are all
derived from the largest floor in the building rather than fixed: a 60 m floor
plate and a 12 m mezzanine cannot share one set of hand-picked numbers. Camera
framing comes off the field of view for the same reason, so it holds for any
floor size, any number of storeys, and a single focused floor.

Floor names are all drawn at one world size. Scaling each to its own floor made
the small ones unreadable the moment the camera pulled back far enough to fit
the big one — the same reason road signs are not sized to the road.

The **Spread** slider starts above 1. A 60 m floor plate with 3.6 m between
storeys is honestly a stack of paper; the slider returns to 1 for the true
proportions.

Three.js is ~140 KB gzipped, so it is pushed by the building page alone rather
than loaded in the shared layout.

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

A desk is a name and a position — no status, occupancy, assigned person, or
equipment, and it is a marker rather than a sized footprint, so a desk has no
width or rotation. In 3D every desk is therefore the same generic block facing
the same way. Floors have a size but no shape: they are rectangles, so an
L-shaped floor has to be approximated by its bounding box until a plan drawing
is uploaded behind it.

The public site has no access control at all — **anyone with the URL can see
every floor, every desk name, and the whole patching record: switch, interface,
computer name and MAC.** That was a deliberate choice, made explicitly rather
than by omission. It is also the one worth revisiting first: that
combination is a readable map of the network, and if desk names start mapping to
people it is a map of who sits where as well. Putting the two routes in
`Modules/PublicSite/routes/web.php` behind `auth` is the whole change.
