# Tinuum Networks Fibre Plant Manager

> **Status: the first source snapshot has not been published yet.** This repository currently holds only the licence and
> this description. The install steps and the links to CONTRIBUTING, AGENTS and NOTICE below apply once the code is here.

A web app for a small fibre ISP to keep track of its outside plant on a map: poles, strand, cable and splices,
closures and POPs, conduit and vaults, easements, WiFi access points and work orders,
with a photo and note timeline on each asset.

It is the tool Tinuum Networks Inc. (New Brunswick, Canada) builds and runs its own network with. This is the
**shared edition**: the parts that are specific to Tinuum (customers, billing, tenders, regulatory filings,
pole-owner permits and so on) are left out. It is published under the GNU Affero General Public License v3.

> This repository is produced from Tinuum's private repository every few months, as a fresh snapshot.
> It does not have the private history. See `CONTRIBUTING.md` for how changes come back.

## What you get
- **Map** (Leaflet): poles, strand, aerial and buried conduit, vaults and handholes, closures, cable spans with
  fibre counts, dimensions, easements, survey lines, notes, WiFi access points with antenna-based coverage.
- **Splices and closures**: splice diagrams and fibre trace, patch panels and POPs, install sheets.
- **Work orders** (`tinuum_permit` table): every asset can carry one or more work orders and the map filters by them.
- A timeline of notes and photos on every closure, POP, conduit and vault.
- **Public data layers** for New Brunswick: SNB parcels, GeoNB civic addresses, wetlands, railways, OSM roads.

## Requirements
Docker with Compose, and about 4 GB of RAM. PostgreSQL 16 with PostGIS runs in a container.

## Install
```bash
git clone <this repository> fibre-manager && cd fibre-manager
cp .env.example .env            # then edit it: passwords, and DEV_LOGIN_SECRET for first login
docker compose up -d --build
# create the database objects once:
docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 < db/schema.sql
docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 < db/seed_demo.sql   # optional demo data
```
Set `DEV_LOGIN_ENABLED=true` and a long random `DEV_LOGIN_SECRET` in `.env`, then open
`http://localhost:8080/index.php?page=login&dev_login=<DEV_LOGIN_SECRET>` for the first login. Set up Google sign-in
(`GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET` in `.env`) and turn the dev login off.

## Using it as a base for your own network
Read `AGENTS.md`. It is written for AI coding agents as well as people: how the code is laid out,
the rules for database changes (migrations, change tracking), and how to prepare a change so it can be sent back.

## Licence
GNU AGPL v3 or later, see [LICENSE](LICENSE) and `NOTICE`. If you run a modified version for other
people over a network, the AGPL requires you to offer them the source of your version.
