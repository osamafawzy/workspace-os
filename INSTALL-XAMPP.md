# Installing Workspace OS on XAMPP (Windows)

The development machine runs this on DDEV. XAMPP works too, but nothing DDEV
provided comes with it — you supply the PHP version, the database, Composer and
Node yourself. This is every step, in order.

## 0. Before you start: check the PHP version

```
composer.json  →  "php": "^8.2"
```

**PHP 8.2 is enough.** The project was downgraded from Laravel 13 to Laravel 12
specifically so it runs on the PHP 8.2 that XAMPP ships. On the new machine:

```bat
C:\xampp\php\php.exe -v
```

8.2, 8.3, 8.4 — any of these work. Only **8.1 or lower** is a hard stop, and no
current XAMPP ships anything that old.

Why this matters: Composer writes the floor into
`vendor\composer\platform_check.php`, and that file aborts the request before
Laravel even loads. It now reads `PHP_VERSION_ID >= 80200`. If you ever bump
`laravel/framework` back to `^13`, the floor returns to 8.3 and this machine
stops working — see *What you give up* at the end.

## 1. Install the pieces

1. **XAMPP** (PHP 8.2 or newer) — gives you Apache, MySQL/MariaDB, phpMyAdmin.
2. **Composer** — https://getcomposer.org/Composer-Setup.exe. When it asks for
   the PHP executable, point it at `C:\xampp\php\php.exe`.
3. **Node.js 20+** — only needed if you want to rebuild the CSS/JS. If you copy
   the already-built `public\build` folder across (step 2), you can skip Node
   entirely and install it later.

Add `C:\xampp\php` to your `PATH` so `php` works in any terminal. Open a **new**
terminal afterwards, then check:

```bat
php -v
composer -V
```

## 2. Copy the project across

There is no git repository here, so this is a folder copy. Put it anywhere — it
does **not** have to live under `htdocs`. `C:\projects\workspace-os` is fine.

**Do not copy** these. They are machine-specific and get rebuilt:

```
vendor\           composer install rebuilds it
node_modules\     npm install rebuilds it
.ddev\            Docker config, meaningless under XAMPP
public\storage    a symlink; you recreate it in step 6
```

**Do copy** these, they are the easy ones to forget:

```
.env                    you will edit it in step 4
public\build\           the compiled CSS/JS — copying it means you can skip Node
storage\app\public\     the uploaded floor plan drawings; the database rows
                        point at these filenames
```

From PowerShell on the old machine, a copy that skips the heavy folders:

```powershell
robocopy "C:\Hard Disk\work\mine\workspace-os" "D:\transfer\workspace-os" /E `
  /XD vendor node_modules .ddev
```

Afterwards, confirm `.env` and `public\build` actually made it — they are the
two whose absence you will not notice until step 11.

## 3. Create the database

Start **Apache** and **MySQL** from the XAMPP Control Panel, then open
http://localhost/phpmyadmin and create an empty database:

```
Name:      workspace_os
Collation: utf8mb4_unicode_ci
```

XAMPP ships MariaDB rather than MySQL 8. The migrations run fine on it.

## 4. Point .env at XAMPP

Open `.env` in the project root and change these. Everything else can stay as
it is.

```dotenv
APP_URL=http://workspace-os.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=workspace_os
DB_USERNAME=root
DB_PASSWORD=
```

`root` with an empty password is the XAMPP default. The current values point at
DDEV's container (`DB_HOST="db"`), which does not exist on this machine.

**`APP_URL` is not cosmetic here.** `config/filesystems.php` builds the URL for
the floor plan drawings out of it:

```php
'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
```

Get it wrong and every floor plan image 404s while the rest of the site looks
perfectly fine. Whatever address you settle on in step 7, `APP_URL` must match
it exactly — including the port, if you use one.

## 5. Install the PHP dependencies

From the project root:

```bat
composer install
php artisan key:generate
```

`key:generate` writes a fresh `APP_KEY`. Safe to do — nothing in this database
is encrypted with it. (If you are importing the old database in step 8b and
want existing logged-in sessions to survive, keep the old key and skip this.)

If Composer complains about a missing extension, see the extension table near
the bottom of this file.

## 6. Link the storage folder

```bat
php artisan storage:link
```

This makes `public\storage` a symlink to `storage\app\public`, which is how the
uploaded floor plan drawings become reachable over HTTP. It is deliberately not
in the copy — `.gitignore` lists `/public/storage` because it is created per
machine.

**On Windows this needs privileges.** Either run the terminal as Administrator,
or turn on Settings → System → For developers → Developer Mode. If it still
refuses, a plain copy works as a fallback — you just have to redo it whenever a
new plan is uploaded through the panel:

```bat
xcopy /E /I storage\app\public public\storage
```

## 7. Serve it

Two ways. The second is more reliable; the first is more "XAMPP".

### 7a. Apache virtual host — recommended for a real install

Apache must point at the project's `public` folder, **not** at the project root.
Serving it from a subfolder like `htdocs\workspace-os\public` breaks the
compiled asset URLs, which are absolute `/build/...` paths.

Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf` and add:

```apache
<VirtualHost *:80>
    ServerName workspace-os.test
    DocumentRoot "C:/projects/workspace-os/public"

    <Directory "C:/projects/workspace-os/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Two details in there earn their place. `AllowOverride All` is what lets Apache
read `public\.htaccess`; without it every route except the homepage 404s.
`FollowSymLinks` is what lets Apache follow the storage link from step 6 to the
plan drawings.

Then add the hostname to `C:\Windows\System32\drivers\etc\hosts` (open the
editor as Administrator):

```
127.0.0.1    workspace-os.test
```

Restart Apache from the XAMPP Control Panel.

If Apache will not start, something else already holds port 80 — usually IIS or
an old Skype. Either stop it, or change `Listen 80` in `httpd.conf` and the
vhost to `8080`, then use `http://workspace-os.test:8080` and put that in
`APP_URL`.

### 7b. Laravel's own server — fastest to get running

Skip Apache entirely and let XAMPP provide only MySQL:

```bat
php artisan serve
```

Then set `APP_URL=http://127.0.0.1:8000` and the site is at that address.
Nothing is lost — this is what the dev workflow and the tests use anyway.

## 8. Get the data in

Pick **one** of these.

### 8a. Fresh demo data

```bat
php artisan migrate --seed
```

Builds the four-floor demo building: three small floors, a 300-desk Third Floor
on a 60 x 40 m open plan, and a Ground Floor of 72 desks placed against a real
architectural drawing. The seeder is idempotent — re-running it updates rather
than duplicates, and never moves a desk you have already placed.

Sign in at `/admin` with:

```
admin@workspace.test / password
```

### 8b. Bring the old database with you

Do this if the old machine has floors, desks or patching records worth keeping.
On the **old** machine, with DDEV running:

```bash
ddev export-db --gzip=false --file=D:\transfer\workspace-os.sql
```

On the **new** machine, after `composer install`, instead of `migrate --seed`:

```bat
C:\xampp\mysql\bin\mysql.exe -u root workspace_os < D:\transfer\workspace-os.sql
php artisan migrate
```

The trailing `migrate` is a no-op if the dump is current and catches you up if
it is not.

Then confirm the uploaded drawings came across, because the database rows point
at filenames that have to exist on disk:

```bat
dir storage\app\public\floor-plans
```

You should see the `.png` files. A floor whose drawing is missing falls back to
a blank grid silently — the desks stay exactly where they are, because positions
are stored as percentages, but the drawing behind them is gone.

## 9. Build the front end — only if you skipped `public\build`

```bat
npm install --ignore-scripts
npm run build
```

`--ignore-scripts` is deliberate; there is an `.npmrc` in the project setting it
as the default too.

For live development instead of a one-off build:

```bat
npm run dev
```

## 10. Clear the caches from the old machine

`bootstrap\cache` travels with the folder copy and holds paths from the old
machine. Always do this after a move:

```bat
php artisan optimize:clear
```

## 11. Check it worked

```bat
php artisan test
```

135 tests. They run against an in-memory SQLite database, so they need the
`pdo_sqlite` and `sqlite3` extensions but nothing from your MySQL setup — if
they pass, PHP itself is correctly configured.

Then in a browser:

| | |
|---|---|
| `http://workspace-os.test/` | the public building in 3D, no login |
| `http://workspace-os.test/admin` | the admin panel |
| a floor's **Plan** tab | the drawing should appear behind the desks |

That last one is the real test. If the desks are there but the drawing is not,
it is `APP_URL` (step 4) or the storage link (step 6), in that order.

## Logins and addresses

### There is exactly one user

The seeder creates one account and that is the whole user list:

| | |
|---|---|
| **Email** | `admin@workspace.test` |
| **Password** | `password` |
| **Name** | Admin |

It is created with `updateOrCreate`, so re-seeding a working database resets
that password back to `password` rather than failing on the unique email.

### Everyone else needs no login at all

The public site has **no authentication and no accounts**. Anyone who can reach
the address sees every floor, every desk and the full patching record — switch,
interface, computer name, MAC. That is deliberate and there is a test that fails
if it ever stops being true. It also means that on a shared office network,
putting this on port 80 publishes all of it to everyone on that network.

### The addresses

Under the Apache vhost from step 7a:

| Address | Who it is for | Login |
|---|---|---|
| `http://workspace-os.test/` | everyone — the building in 3D | none |
| `http://workspace-os.test/floors/1` | everyone — one floor's plan | none |
| `http://workspace-os.test/admin` | you — the panel | `admin@workspace.test` / `password` |
| `http://workspace-os.test/admin/login` | the sign-in form itself | — |
| `http://localhost/phpmyadmin` | you — the database | `root`, empty password |

Under `php artisan serve` from step 7b, swap the host for
`http://127.0.0.1:8000`. On the old DDEV machine the same pages are at
`https://workspace-os.ddev.site`.

The floor ids in `/floors/{id}` come from the database — follow the links from
the homepage rather than guessing them.

### Adding more people

There is no registration page and no Users screen in the panel, so accounts are
made from the command line:

```bat
php artisan tinker
```

```php
App\Models\User::create([
    'name' => 'Osama',
    'email' => 'you@example.com',
    'password' => Hash::make('a-real-password'),
    'email_verified_at' => now(),
]);
```

Same way to change a password:

```php
App\Models\User::where('email', 'admin@workspace.test')
    ->first()
    ->update(['password' => Hash::make('a-real-password')]);
```

**Every account is a full administrator.** `User::canAccessPanel()` returns
`true` unconditionally — there are no roles and no permissions, so anyone you
add can edit and delete any floor and any desk. If you want a read-only person,
give them the public site address and no account.

Before this goes anywhere but a laptop: change the admin password, and set
`APP_DEBUG=false` and `APP_ENV=production` in `.env`. With debug on, a stack
trace on any error shows your database credentials to whoever triggered it.

## PHP extensions

Most of these are on by default in XAMPP. To enable one, open
`C:\xampp\php\php.ini`, find the line, remove the leading `;`, restart Apache.

| Extension | Needed for |
|---|---|
| `pdo_mysql`, `mysqli` | the database |
| `mbstring`, `openssl`, `tokenizer`, `ctype`, `fileinfo`, `filter`, `session` | Laravel itself |
| `curl`, `zip` | Composer |
| `xml`, `dom`, `simplexml` | Laravel and PHPUnit |
| `gd` | image handling behind the plan upload |
| `bcmath` | Laravel helpers |
| `intl` | Filament formatting — commonly commented out in XAMPP, the one you are most likely to have to enable by hand |
| `pdo_sqlite`, `sqlite3` | `php artisan test` only |

See what is actually loaded:

```bat
php -m
```

## When something is wrong

| What you see | What it is |
|---|---|
| Composer: "requires php ^8.2 but your php version is 8.1" | Step 0. Upgrade PHP; the project cannot go below 8.2. |
| 404 on everything but the homepage | `AllowOverride All` missing from the vhost, or `mod_rewrite` off. |
| 500 with no message | `storage\logs\laravel.log`. Usually a missing extension or a database it cannot reach. |
| "could not find driver" | `pdo_mysql` not enabled in `php.ini`. |
| "Access denied for user 'root'" | XAMPP's root has an empty password — make sure `DB_PASSWORD=` is genuinely empty, not the word `null`. |
| Site loads, all styling missing | `public\build` never copied and `npm run build` never run. Step 9. |
| Plan drawings missing, desks fine | `APP_URL` mismatch, or `storage:link` never ran. Steps 4 and 6. |
| Changes to `.env` do nothing | `php artisan optimize:clear`. Step 10. |
| Apache will not start | Port 80 is taken. Step 7a. |

## What you give up

DDEV pinned PHP 8.2 and MySQL 8.0 for everyone. XAMPP pins nothing — the PHP
version, the extension set and the MariaDB version are now whatever that machine
happens to have. If the two machines ever disagree about a result, that is the
first place to look.

The project also sits on **Laravel 12, not 13**, and `composer.json` pins
`config.platform.php` to `8.2` so Composer keeps resolving for 8.2 even when it
runs on a newer PHP. Laravel 13 requires PHP 8.3, so `composer update` will never
quietly pull it in. Undoing that pin is what would break this machine.
