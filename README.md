# Event Manager

Web application for managing events, actions, places, users, notifications, attendance and finances.

## Stack

- PostgreSQL
- PHP
- JavaScript
- HTML
- CSS

## Database

Initial schema is in `database/create.sql`.
You can set different values (e.g. length of name) via `database\meta-settings.json` and run `database\generate_from_meta-settings.py` for generating new `create.sql` file

## Initialization
- Create PostgreSQL database e.g. event_manager
    - Run manualy queries listed in files in ```database/setup.sql```, or run ```psql -U <user> -d event_manager -f database/setup.sql```
- Make a copy of the folder with all included files ```.envConfig.template``` and rename to ```.envConfig```
    - setup all values in those files

- Set root in webhosting to  `public/`, .htaccess redirect all via index.php

### Demo database (showroom)
For showing the app to someone new, load sample people, rooms, actions in every status, attendance and finances on top of a fresh `setup.sql` — into a separate demo database, never into production:

```
psql -U <user> -d event_manager_demo -f database/seed_showroom.sql
```

- Log in as `admin@ad.min` / `admin` (Administrátor, can switch to Organizátor). The other sample accounts (`…@knihovna.example`) use the password `demo`.
- Dates are relative to the day of loading, so past actions wait to be completed/closed and upcoming ones are being planned.
- Loading it a second time is refused.

## Moving to a new server (migration checklist)

Nothing in the PHP code itself has to change — every server-specific value lives in `.envConfig/` (not in git). Go through the list top to bottom.

### 1. Server requirements
- PHP **8.2+** with extensions `pdo_pgsql` and `mbstring` (used for UTF-8 email subjects / notification texts).
- PHP `mail()` must work (sendmail/SMTP configured by the hosting) — it sends login links and notification emails. The sender domain from `mail_from` should be allowed to send from that server (SPF), otherwise emails end up in spam or get rejected.
- Apache with `mod_rewrite` enabled and `.htaccess` allowed (`AllowOverride All`) — `public/.htaccess` routes every URL through `public/index.php`.
- PHP sessions working (writable `session.save_path`) — login is session-based.
- PostgreSQL 12+.

### 2. Files to upload
- The whole project **including `vendor/`** (it's in `.gitignore`; it contains Composer's autoloader and the BadLogin module in `vendor/BadLogin/`). Running `composer install` (or `composer dump-autoload`) on the server regenerates only the autoloader — `vendor/BadLogin/` must be uploaded either way.
- `.envConfig/` — create it from `.envConfig.template/` (see step 4). It is not in git, upload it by hand.
- `storage/logs/` must exist and be **writable by the web server user** (PHP error log `storage/logs/error.log`, cron log). Same for `storage/cache/` and `storage/mail/`.
- Not needed on the server: `database/export/`, `tests/`, `*.drawio`, `*.pdf`, `prompt.md`, `deploy.sh`.

### 3. Web server / domain
- **Point the domain's document root to `public/`** (e.g. `/web/BadEvent/public`). Then the app runs at `https://domain/` and nothing outside `public/` (config with DB password, `app/`, `database/`, `storage/`) is reachable from the web.
- The app also works from a subdirectory (e.g. `https://domain/TEST/BadEvent/public/`) — `APP_BASE` is detected automatically from the location of `index.php`, so links and assets need no change.
- If the hosting does **not** allow changing the document root to `public/`, the project root ends up web-accessible: then add a root `.htaccess` that redirects everything into `public/` and denies access to `.envConfig/`, `app/`, `database/`, `storage/`, `vendor/`, `cron/` — never leave `.envConfig/database.php` downloadable.
- Use HTTPS (the session cookie and login links should not travel over plain HTTP).

### 4. Configuration (`.envConfig/`)
- **`database.php`** — `host`, `port`, `database`, `username`, `password` of the new database.
- **`app.php`**
  - `base_url` — the public URL of the app **up to the folder that contains `index.php`, without a trailing slash**. Login links are built as `base_url + /login` and links in notification emails as `base_url + /actions/{id}`, so it must point exactly where `/login` works:
    - document root = `public/` → `'https://domain'`
    - app in a subfolder → `'https://domain/TEST/BadEvent/public'`
  - `mail_from`, `email_from_name` — sender of all emails (an address of the new domain).
  - `timezone` — keep `Europe/Prague`.
  - `notifications`, `action_defaults`, `action_list_defaults` — optional, defaults are fine.
- **`bl_config.php`** — takes `app_name`, `base_url`, `mail_from` from `app.php`, usually nothing to change (`_linktoken_login_filepath` stays `/login`).

### 5. Database
- Create an empty database and a user for the app.
- Moving existing data:
  - best: `pg_dump -Fc` on the old server + `pg_restore --no-owner` on the new one;
  - with SQL-only access (Adminer): import the Adminer dump into an **empty** database. Adminer exports `boolean` columns as `0`/`1`, which PostgreSQL refuses — the dump has to be adjusted first (boolean columns created as `integer` and converted back at the end, triggers created after the data, everything in one transaction); see `database/export/` for an already adjusted example.
- Fresh install instead: run `database/setup.sql`.
- Run every script in `database/migrations/` that the source database didn't have yet (all of them are idempotent — safe to run again).
- The tables must belong to (or be granted to) the DB user from `database.php` — if you import as another user, `GRANT` the app user `SELECT, INSERT, UPDATE, DELETE` on all tables and `USAGE, SELECT` on all sequences.

### 6. Cron
Set up the daily job with the **new absolute paths** (see the *Cron* section below), e.g.:
```
0 6 * * * /usr/bin/php /web/BadEvent/cron/daily.php >> /web/BadEvent/storage/logs/cron.log 2>&1
```
Check the PHP CLI binary path (`which php`) — it has to be PHP 8.2+ too, CLI often differs from the web PHP.

### 7. Other places that mention the old server (not required for the app to run)
- `deploy.sh` — `SERVER` and `REMOTE_DIR` (sftp target).
- `cron/daily.php` header comment and this README — example paths.
- `app/Views/layout/base.php` — footer link to `badit.cz`.

### 8. Check after the move
- `https://…/login` opens and you can log in with a password.
- "Odkaz na email" login: the email arrives and the link in it leads to the new domain (verifies `base_url` + `mail()`).
- Calendars, an action detail and an export open without errors; `storage/logs/error.log` stays empty.
- `php cron/daily.php` run once from the command line prints `OK` for every task.

## Cron (daily notifications)
`cron/daily.php` checks everything that may need a notification (in-app + email to the user's login email). It runs from the command line only.

- room queue — request reached 1st place; 1st place for 7 days (organizers) / 14 days (admins) without a confirmed room
- still-draft (Rozjednaná) actions 30 and 7 days before their start
- Rozjednaná/Potvrzená actions that already ended (within the last 30 days) and still wait for a status change
- Rozjednaná/Potvrzená actions starting within 7 days with no confirmed room
- retry of emails that failed to send in the last 2 days
- cleanup of building-code-duty rows older than 2 months

The day thresholds above are defaults — they're set in the `notifications` section of `.envConfig/app.php`.

Run it once a day, e.g. at 6:00 (`crontab -e`):

```
0 6 * * * /usr/bin/php /path/to/BadEvent/cron/daily.php >> /path/to/BadEvent/storage/logs/cron.log 2>&1
```

All checks are idempotent (each notification is recorded as sent), so running it more often is safe. The same checks (except the email retry) also run on every "Můj přehled" load, so notifications still go out without cron — just later, when someone opens the app.

## Tests

The automated tests live in `tests/` and run in Docker: one container with PostgreSQL (a throwaway test database) and one with PHP + PHPUnit. Nothing else needs to be installed on your computer, and the tests never touch `.envConfig/` or the production database. The test config is in `tests/.envConfig/`. For what the tests cover and how to write new ones, see [tests/README.md](tests/README.md).

### One-time setup (Ubuntu)

1. Install Docker and the Compose plugin:
   ```bash
   sudo apt update
   sudo apt install -y docker.io docker-compose-v2
   ```
2. Allow your user to run Docker without `sudo`, then **log out and back in** (or run `newgrp docker` in the current terminal):
   ```bash
   sudo usermod -aG docker $USER
   ```
3. Check that it works:
   ```bash
   docker run --rm hello-world
   docker compose version
   ```

### Running the tests

From the project root:

```bash
docker compose -f tests/docker-compose.yml run --rm php                         # all tests
docker compose -f tests/docker-compose.yml run --rm php --testsuite Unit        # one suite: Unit | Integration | Http
docker compose -f tests/docker-compose.yml run --rm php --filter PlaceQueue     # tests whose name matches
docker compose -f tests/docker-compose.yml down                                 # stop the database when done
```

- The first run takes a few minutes. It builds the PHP image and installs PHPUnit into `tests/vendor/`. Later runs take seconds.
- Anything after `php` is passed to PHPUnit.
- The test database is created from `database/create.sql` + `database/seed.sql` on every run and lives only in memory. `down` throws it away.
- Emails are not sent. They are saved to `tests/tmp/mail/`.
- The containers write files (`tests/vendor/`, `tests/tmp/`) as user id 1000. If `id -u` shows a different number on your machine, run `export UID; export GID=$(id -g)` before the commands above.

### When something goes wrong

- `permission denied ... docker.sock`: the group change from step 2 isn't active yet. Log out of the whole desktop session and back in, or reboot. Closing the terminal or VS Code isn't enough. `id -nG` must list `docker`.
- Port `54329` already in use: another test database is still running. Run `docker compose -f tests/docker-compose.yml down`.
- A test fails with a server error: check `tests/tmp/http-server.log` and `storage/logs/error.log`.
- After changing `tests/docker/Dockerfile`, rebuild the image: `docker compose -f tests/docker-compose.yml build --no-cache`.
