# Notes for AI coding agents (and people)

This is a PHP 8.3 + PostgreSQL/PostGIS web app. Read this file first, then `README.md`. To install, run and hand over an installation, follow `INSTALL.md` step by step.

## Layout
- `src/www/public/` is the web root. `index.php` routes `?page=<name>` to `pages/<name>.php`. `api/*.php` are JSON endpoints and
  start with `require_once '_common.php'` (login, CSRF, JSON errors). `phpHelpers/` holds shared code.
- `src/www/app/` has bootstrap, config, layout and the menu (`nav.php`). A page with no file in `pages/` gets no menu entry.
- `src/www/public/js/map.helper.js` is the Leaflet map (large, one closure). Run `node --check` on it after editing.
- `db/schema.sql` is the full schema. `db/migrations/` holds changes made after the snapshot.
- Docker: `app` (Apache + PHP, published on `APP_PORT`, default 48731) and `db` (PostGIS, internal only). See `docker-compose.yml` and `.env.example`.
- Organisation settings (name, contacts, logo, host, what a work order is called, your own network's name) are in `src/www/app/org.php`
  (neutral defaults). Put overrides in `src/www/app/org.local.php`; never edit the defaults for one installation. PHP reads them with `org('key')`,
  JavaScript with `window.ORG`, SQL with `app.own_name()` (stored in `app.org_setting`). Do not hard-code a company name in code.
- Sign-in: Google OAuth for real use; `DEMO_LOGIN_*` (demo/demo) is a try-it login that must be off in production.

## Rules that keep the data safe
- **Migrations:** `db/migrations/NNNN__name.sql`, next number from `ls`, applied with `psql -v ON_ERROR_STOP=1 -f -`, and the
  file inserts its own row into `app.schema_migrations`. Wrap multi-statement changes in one transaction.
- **Change tracking:** every write is tied to a `change_set` through `app.actor`. PHP sets it in `app/change_tracking.php`.
  When you write with psql set `PGOPTIONS="-c app.actor=YOUR_CODE -c app.source=psql"`.
- **Store a fact once.** Use foreign keys and CHECK constraints, not only PHP checks. Never `MAX(id)+1`; use sequences.
- Work orders live in `app.fpm_permit` (`permit_num`), and an asset's primary work order is its `fpm_wor` column.
- Keep data in the database, not in page source.
- Do not commit `.env`, keys, or real customer data.

## Making a change you can send back
1. Branch from `main`. Keep the change small and in one area.
2. Check: `php -l` on PHP files, `node --check` on JavaScript, and load the page in a browser.
3. Commit with `git commit -s` (see CONTRIBUTING.md) and open a pull request describing the problem and how you tested it.
4. Do not change `LICENSE` or `NOTICE`. Add no file headers that name another copyright holder for the whole file; contributors
   are credited by the git history.

## Known gaps in this shared edition
- The public data layers (parcels, civic addresses, communities, wetlands, OSM roads, zoning hooks) are for New Brunswick, Canada.
  Support for other regions is welcome.
- Some code for things the first publisher uses privately (Alden One staging poles, town-limit and exchange layers) is present but has no
  button; it needs tables and APIs that are not part of this edition.
- Table and column names such as `fpm_permit` / `fpm_wor` (work order) are historical; renaming them is a migration and welcome only with one.
