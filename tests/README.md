# Tests

PHPUnit test suite of BadEvent. It runs against a **real PostgreSQL database** built from the real
schema (`vendor/BadLogin/src/db/bl_create.sql` + `database/create.sql` + `database/seed.sql`) and,
for the HTTP tests, through the real `public/index.php` on PHP's built-in web server.

| Suite         | What it covers                                                                                      | Needs DB |
|---------------|-----------------------------------------------------------------------------------------------------|----------|
| `Unit`        | Router, config loading, HTML escaping                                                               | no       |
| `Integration` | Role permissions, users, action workflow + guards, room queue/blocking, queue & reminder notifications, finance totals, export filters, Statistika numbers, `cron/daily.php` | yes |
| `Http`        | Login (password + emailed link), logout, page access per role, creating/editing actions, status changes, economic data, all exports incl. computed values | yes |

## Configuration — `tests/.envConfig/`

Same three files as the app's `.envConfig/` (copied from `.envConfig.template/`), filled in for testing.
The app loads them instead of `.envConfig/` because the test runner sets `BADEVENT_CONFIG_DIR`
(see `app/Core/Config.php`; in production the variable is never set).

- `database.php` — test database `badevent_test`, user/password `badevent_test`. Host and port can be
  overridden by `TEST_DB_HOST` / `TEST_DB_PORT`.
  **The database is dropped and recreated on every run** — the suite refuses to run against a database
  whose name doesn't end with `_test`.
- `app.php` — `base_url` points to the built-in server (`http://127.0.0.1:8765`, port `TEST_HTTP_PORT`).
- `bl_config.php` — unchanged from the template.

No email ever leaves the machine: `run.sh` points PHP's `sendmail_path` at `bin/fake-sendmail`, which
stores each message in `tests/tmp/mail/` (the login-link test reads the link from there).

## Running — pick one of the environments

### A) Everything in Docker (recommended)
Needs only Docker (Docker Engine on Linux is free; Docker Desktop is free for personal use and small organizations).

```bash
sudo apt install docker.io docker-compose-v2      # once (Ubuntu); then: sudo usermod -aG docker $USER && re-login
docker compose -f tests/docker-compose.yml run --rm php                  # all tests
docker compose -f tests/docker-compose.yml run --rm php --testsuite Unit # PHPUnit arguments go at the end
docker compose -f tests/docker-compose.yml down                          # stop the database
```
The first run builds the PHP image and installs PHPUnit into `tests/vendor/`.

### B) PostgreSQL in Docker, PHP local
```bash
sudo apt install php8.3-mbstring php8.3-xml composer   # once
docker compose -f tests/docker-compose.yml up -d db
TEST_DB_PORT=54329 tests/run.sh
```

### C) Everything local, no Docker
```bash
sudo apt install postgresql php8.3-mbstring php8.3-xml composer   # once
sudo -u postgres psql -c "CREATE ROLE badevent_test LOGIN PASSWORD 'badevent_test'"
sudo -u postgres psql -c "CREATE DATABASE badevent_test OWNER badevent_test"
tests/run.sh
```

`tests/run.sh` installs PHPUnit on the first run (`composer install` in `tests/`) and passes any
arguments to PHPUnit, e.g. `tests/run.sh --filter PlaceQueue`.

PHPUnit lives in `tests/vendor/` (own `tests/composer.json`), so the production `vendor/` that gets
uploaded to the server never contains development packages.

## Writing tests

- Extend `Tests\Support\DatabaseTestCase` (fresh seeded DB before every test) or
  `Tests\Support\HttpTestCase` (+ a browser: `$this->loginAs('organizer')`, `$this->http->get/post`).
- Build data with `Tests\Support\Fixtures` (`user`, `action`, `place`, `request`, `confirm`,
  `attendance`, `income`, `cost`, …) — dictionary entries are referenced by name and created when missing.
- Room-queue tests pass the request time explicitly (`Fixtures::request($action, $room, Fixtures::at('-1 hour'))`)
  so the queue order never depends on insert timing.
- `Tests\Support\StatisticsDataset` is a fixed year of data with hand-computed expected numbers, shared
  by the Statistika repository test and the export HTTP test.
- Logs: `tests/tmp/http-server.log` (web server), `storage/logs/error.log` (application errors).
