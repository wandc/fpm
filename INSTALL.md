# Installing and running Tinuum Networks Fibre Plant Manager (FPM)

This file is a runbook. It is written so that an AI coding agent, or a person, can take a computer with Docker on it
to a working installation, hand it to the people who will use it, and keep it healthy. Follow the sections in
order. Every command can be copied as it stands. Where a decision belongs to the owner of the installation it says
**ASK**.

If you are an agent: do each step, check its "You should see" line, and stop and report if a check fails. Do not
skip the security section. Do not delete data unless the owner said to.

---

## 0. What this software is

FPM is a web application for a small fibre ISP's outside plant: a map of poles, strand, cable, closures, POPs, conduit,
vaults, easements and WiFi access points, with splice diagrams, work orders, install sheets and a photo and note timeline
on each asset. It is PHP 8.3 on Apache with PostgreSQL 16 + PostGIS, run by Docker Compose. It is licensed under the
GNU AGPL v3 or later; copyright Tinuum Networks Inc. (see `LICENSE`, `NOTICE`).

Two containers:

| container | what it is | port |
|---|---|---|
| `fpm-app` | Apache + PHP, serves the web pages and the JSON API | published on `APP_PORT` (default **48731**) |
| `fpm-db`  | PostgreSQL 16 with PostGIS, holds all data | only reachable by the app, inside Docker |

Everything the user sees is data in the database plus the PHP/JS in `src/`.

## 1. Requirements

- Linux (or macOS/Windows with Docker Desktop), 2 CPU cores, 4 GB RAM, 10 GB free disk.
- Docker Engine 24+ with the Compose plugin (`docker compose version` works) and `git`.
- Internet access for the first build (Docker images, map tiles in the browser).
- Nothing else is needed on the host: no PHP, no PostgreSQL, no Node.

Check:
```bash
docker --version && docker compose version && git --version
```
You should see three version lines.

## 2. Get the code

```bash
git clone https://github.com/wandc/fpm.git
cd fpm
```
You should see `docker-compose.yml`, `src/`, `db/`, `app/`, `INSTALL.md` in the folder.

## 3. Configure

```bash
cp .env.example .env
```
Edit `.env`:

| setting | what to put | notes |
|---|---|---|
| `POSTGRES_PASSWORD` | a long random password | `openssl rand -hex 24`. **Required.** |
| `APP_PORT` | `48731` (default) | any free high port |
| `BIND_ADDR` | `127.0.0.1` (default) | this computer only. `0.0.0.0` = reachable from the network. |
| `DEMO_LOGIN_*` | `true` / `demo` / `demo` | demo sign-in, see section 7 |
| `TZ` | your timezone, e.g. `America/Moncton` | |

Generate and fill the password in one go:
```bash
sed -i "s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=$(openssl rand -hex 24)/" .env
```
Never commit `.env` (it is in `.gitignore`).

## 4. Start it

```bash
docker compose up -d --build
```
The first build takes a few minutes. Then:
```bash
docker compose ps
```
You should see `fpm-app` and `fpm-db` both `Up`, and `fpm-db` `(healthy)`.

The database starts **empty**. The first start only creates the extensions (`db/init/01-extensions.sql`).

## 5. Create the tables and load the starting data

Run these three, in this order, once. They read `.env` for the user and database name.

```bash
set -a; . ./.env; set +a
for f in schema reference_data seed_demo; do
  echo "== $f"
  docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -q < db/$f.sql
done
```
- `schema.sql` creates every table, view, function and trigger.
- `reference_data.sql` loads the rows the software cannot run without (change-tracking actors, pole owners, equipment
  catalogues).
- `seed_demo.sql` adds one made-up road (6 poles, strand, a POP, 3 closures, a 24F cable, a WiFi AP) so the map is not
  empty. **Optional.** Skip it for a production install, or delete the demo later (section 10).

You should see three `== name` lines and no `ERROR`. Check:
```bash
docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "select count(*) from app.pole"
```
`6` with the demo data, `0` without it.

## 6. Open it

Browse to `http://127.0.0.1:48731/` (or your `APP_PORT`). On a remote server use an SSH tunnel:
```bash
ssh -L 48731:127.0.0.1:48731 user@server     # then browse to http://127.0.0.1:48731/ on your own computer
```
You should see the **Sign in** page with a user name and password form and a yellow "Demo login is on" notice.

## 7. First sign-in: demo / demo

Sign in with user **demo**, password **demo**. The demo user is an administrator and its changes are recorded under the
change-tracking actor `WEBX` ("web, user unknown").

Then open the **Network Map**. With the demo data it opens on the "Demo Road" (a field in New Brunswick). Use the layer
panel and the Add tools; click any asset for its popup.

> **Security: the demo login is a published password.** It exists so a new install can be tried in a minute.
> **ASK the owner**, then before anyone other than the person installing can reach the app, do one of:
> - turn it off: set `DEMO_LOGIN_ENABLED=false` in `.env`, or
> - change `DEMO_LOGIN_PASS` to a strong password and `DEMO_LOGIN_USER` to something that is not `demo`.
>
> then `docker compose up -d` to apply (section 12). The default `BIND_ADDR=127.0.0.1` keeps the demo reachable only from
> the same computer; do not set `BIND_ADDR=0.0.0.0` while demo login is on unless the network is trusted.

## 8. Real sign-in for real users (Google)

Demo login is not for real use. The app signs people in with Google OAuth.

1. In the Google Cloud console create an **OAuth client ID** of type *Web application*.
2. Add this **authorised redirect URI**, using the address people will really type (HTTPS in production, see section 9):
   `https://fpm.example.com/index.php?page=oauth2_callback`
3. Put the values in `.env`:
   ```
   GOOGLE_OAUTH_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_OAUTH_CLIENT_SECRET=...
   APP_OAUTH_CALLBACK_URL=https://fpm.example.com/index.php?page=oauth2_callback
   BLOCK_NEW_ACCOUNTS=true
   ALLOWED_EMAILS=alice@example.com,bob@example.com
   ```
   With `BLOCK_NEW_ACCOUNTS=true` only the addresses listed in `ALLOWED_EMAILS` can sign in. Use that.
4. `docker compose up -d`. The sign-in page now shows **Sign in with Google**.
5. New Google users get role `user`. Make the first person an administrator (they must have signed in once):
   ```bash
   docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c \
     "UPDATE app.app_user SET role='admin' WHERE email='alice@example.com'"
   ```
   Administrators can see the **Users** page (Admin menu) and edit names and roles there.
6. Turn the demo login off (section 7).

Emergency access without Google: set `DEV_LOGIN_ENABLED=true` and a long random `DEV_LOGIN_SECRET`, open
`/index.php?page=login&dev_login=<secret>` once, then turn it off again. Treat the secret like a root password.

## 9. HTTPS and a public address

The container speaks plain HTTP on port 8080 inside Docker. For anything beyond one computer put a reverse proxy with a
TLS certificate in front of it (Caddy, nginx, Traefik, or your cloud's load balancer) and forward to
`http://127.0.0.1:48731`. Caddy example (`Caddyfile`):
```
fpm.example.com {
    reverse_proxy 127.0.0.1:48731
}
```
Keep `BIND_ADDR=127.0.0.1` so only the proxy can reach the app. Make sure the proxy passes the `Host` header and
`X-Forwarded-Proto`. Use the HTTPS address in `APP_OAUTH_CALLBACK_URL`.

## 10. Make it yours

All organisation-specific text lives in two small files; you never need to edit the PHP pages for this.

1. **Your names and contact details.** Copy `src/www/app/org.php` settings you want to change into a new file
   `src/www/app/org.local.php` that returns an array (it is read after the defaults, and a new snapshot never overwrites it):
   ```php
   <?php
   return [
       'name'          => 'Acme Fibre',
       'long_name'     => 'Acme Fibre',
       'legal_name'    => 'Acme Fibre Ltd.',
       'app_title'     => 'Acme Plant',
       'support_email' => 'support@acme.example',
       'phone'         => '1-555-0100',
       'web'           => 'www.acme.example',
       'app_host'      => 'fpm.example.com',
       'app_domain'    => 'fpm.example.com',
       'address'       => '1 Main Street, Town, NB',
       'region'        => 'Town, NB',
       'logo'          => '/images/acme-logo.png',   // put the file in src/www/public/images/
       'own_name'      => 'Acme',                      // how your network is named as a pole owner (see below)
       'wo_label'      => 'Work Order',                // what you call a permit / work order
   ];
   ```
   The keys and their meaning are commented in `org.php`. Reload the page to see the change (no restart needed).
2. **Your own name as a pole owner.** Poles carry an owner; yours is `own_name`. Keep three places in step:
   ```bash
   docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<'SQL'
   INSERT INTO app.pole_owner (owner) VALUES ('Acme') ON CONFLICT DO NOTHING;
   UPDATE app.org_setting SET value = 'Acme' WHERE key = 'own_name';
   SQL
   ```
   and set `'own_name' => 'Acme'` in `org.local.php`. (`pole_owner.owner` is at most 15 characters.)
3. **Remove the demo data** if you loaded it:
   ```bash
   docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<'SQL'
   BEGIN;
   DELETE FROM app.cable_span WHERE cable_id IN (SELECT cable_id FROM app.cable WHERE name='Demo 24F');
   DELETE FROM app.cable WHERE name='Demo 24F';
   DELETE FROM app.wifi_ap WHERE name='Demo AP';
   DELETE FROM app.fosc WHERE splice_num IN ('DMO0001','DMO0002','DMO0003');
   DELETE FROM app.pop WHERE pop_id=9001;
   DELETE FROM app.messenger_strand WHERE fpm_wor='2026-1-Demo';
   DELETE FROM app.pole WHERE fpm_wor='2026-1-Demo';
   DELETE FROM app.fpm_permit WHERE permit_num='2026-1-Demo';
   COMMIT;
   SQL
   ```
   If a delete is refused by a trigger, use the map's Delete button on that asset instead; the software protects
   data that other data depends on.
4. **Load your real data** with the map's **Add** tools (poles, strand, closures, cable spans) and the **Cables**,
   **POPs**, **Closure Types** and **Documents** pages, or with SQL/CSV imports into the tables described in
   `db/schema.sql`. Work orders (permits) are rows in `app.fpm_permit`; tag an asset with one in its edit dialog.
   Use change tracking when writing with psql: `PGOPTIONS="-c app.actor=CRON -c app.source=psql"`.
5. **Optional public data layers** (parcels, civic addresses, communities, wetlands, OSM roads) are loaded by scripts in
   `src/tools/` (for example `sync_geonb.php`, `import_osm_roads.php`); they cover New Brunswick, Canada. Run them with
   `docker compose exec app php /var/www/html/tools/<script>.php` and read the comment at the top of each first.

## 11. Daily operation

| task | command |
|---|---|
| status | `docker compose ps` |
| logs | `docker compose logs -f app` (or `db`) |
| stop / start | `docker compose stop` / `docker compose start` |
| apply a changed `.env` | `docker compose up -d` |
| psql shell | `docker compose exec db psql -U appuser -d fpm_db` |
| PHP error log | `docker compose logs app \| grep -i "php "` |

The containers restart themselves after a reboot (`restart: unless-stopped`).

### Backups (do this on day one)
Everything is in the database volume. Nightly dump, keeping 14 days:
```bash
mkdir -p ~/fpm-backups
cat > ~/fpm-backup.sh <<'SH'
#!/bin/bash
set -e
cd "${FPM_DIR:-$HOME/fpm}"          # the folder you cloned into
set -a; . ./.env; set +a
F=$HOME/fpm-backups/fpm_$(date +%F_%H%M).dump
docker compose exec -T db pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc > "$F"
[ -s "$F" ] || { echo "WARNING: empty dump $F"; exit 1; }
find "$HOME/fpm-backups" -name 'fpm_*.dump' -mtime +14 -delete
SH
chmod +x ~/fpm-backup.sh
( crontab -l 2>/dev/null; echo "15 3 * * * ~/fpm-backup.sh >> ~/fpm-backups/backup.log 2>&1" ) | crontab -
```
(Set `FPM_DIR` in the crontab line if you cloned somewhere other than `~/fpm`.) Copy the dumps off the machine too. **Test a restore** into a scratch database now and then:
```bash
docker compose exec -T db psql -U appuser -d postgres -c "CREATE DATABASE restore_test"
docker compose exec -T db pg_restore -U appuser -d restore_test --no-owner < ~/fpm-backups/<file>.dump
```

### Updating to a newer snapshot
This repository is published as a fresh snapshot every few months, without history, so update by comparing:
```bash
git fetch origin && git log --oneline origin/main | head          # what is new
git diff HEAD origin/main -- db/ docs/ | less                      # database changes first
```
1. **Back up first** (above).
2. Your own files (`.env`, `src/www/app/org.local.php`, your logo) are not in the repository, so they survive.
3. `git pull`. Snapshots after the first contain new files in `db/migrations/` (numbered, applied in order) for database
   changes; apply new ones with `docker compose exec -T db psql -U appuser -d fpm_db -v ON_ERROR_STOP=1 < db/migrations/NNNN__name.sql`.
4. `docker compose up -d --build`, then open the map and the pages you use.

## 12. Troubleshooting

| symptom | cause and fix |
|---|---|
| `docker compose up` says "set POSTGRES_PASSWORD" | `.env` missing or the line is empty (section 3) |
| page shows "Database connection failed" | db not healthy yet (`docker compose ps`), or `.env` password changed after the database was created. The password is fixed when the volume is first created: either put the old password back, or `docker compose exec db psql -U appuser -d postgres -c "ALTER ROLE appuser PASSWORD '...'"`. |
| sign-in page loops back to itself | the session folder is not writable: `docker compose exec app ls -ld /var/lib/app-sessions` must be owned by `www-data` |
| "Sign in with Google" missing | `GOOGLE_OAUTH_CLIENT_ID` empty; `docker compose up -d` after editing `.env` |
| Google says redirect_uri_mismatch | `APP_OAUTH_CALLBACK_URL` and the URI registered in Google differ (scheme, host, port, path) |
| "account not permitted" after Google | `BLOCK_NEW_ACCOUNTS=true` and the address is not in `ALLOWED_EMAILS` |
| map is blank grey | no internet for map tiles in the browser, or the browser blocked them |
| port already in use | change `APP_PORT` in `.env` and `docker compose up -d` |
| after editing `.env` nothing changed | `docker compose up -d` recreates the container with the new values (a plain restart does not) |
| a map layer button does nothing | it needs data you have not loaded (parcels, addresses): section 10 item 5 |

## 13. Changing the software, and sending changes back

Read `AGENTS.md` (how the code is laid out and the rules for database changes) and `CONTRIBUTING.md` (sign-off, licence of
contributions). In short: branch, keep the change small, check it (`php -l` for PHP files with
`docker compose exec app php -l /var/www/html/www/public/<file>`, `node --check` for JS), commit with `git commit -s`, and
open a pull request on GitHub. If you run a modified version for other people over a network, the AGPL requires you to
offer them its source; sending your changes upstream is the easy way to do that.

## 14. Checklist for the agent finishing an installation

- [ ] `docker compose ps` shows both containers up, db healthy
- [ ] schema, reference data (and demo data if wanted) loaded without errors
- [ ] can sign in; map opens
- [ ] demo login turned off or its password changed (**ask the owner**)
- [ ] Google sign-in set up, `ALLOWED_EMAILS` filled, first administrator promoted
- [ ] HTTPS reverse proxy in place if reachable beyond one computer
- [ ] `org.local.php` has the company's name, contact details, logo and `own_name`
- [ ] nightly backup script installed and one restore tested
- [ ] `.env` is not committed and its permissions are `600` (`chmod 600 .env`)
- [ ] told the owner where everything lives: the folder, `.env`, backups, how to update
