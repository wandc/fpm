# Tinuum Networks Fibre Plant Manager

A web app for a small fibre ISP to keep track of its outside plant on a map: poles, strand, cable and splices,
closures and POPs, conduit and vaults, easements, WiFi access points and work orders,
with a photo and note timeline on each asset.

It is the tool Tinuum Networks Inc. (New Brunswick, Canada) builds and runs its own network with. This is the
**shared edition**: the parts that are specific to Tinuum (customers, billing, tenders, regulatory filings,
pole-owner permits and so on) are left out. It is published under the GNU Affero General Public License v3.

> This repository is produced from Tinuum's private repository every few months, as a fresh snapshot.
> It does not have the private history. See [CONTRIBUTING.md](CONTRIBUTING.md) for how changes come back.

## What you get
- **Map** (Leaflet): poles, strand, aerial and buried conduit, vaults and handholes, closures, cable spans with
  fibre counts, dimensions, easements, survey lines, notes, WiFi access points with antenna-based coverage.
- **Splices and closures**: splice diagrams and fibre trace, patch panels and POPs, install sheets.
- **Work orders** (`fpm_permit` table): every asset can carry one or more work orders and the map filters by them.
- A timeline of notes and photos on every closure, POP, conduit and vault.
- **Public data layers** for New Brunswick: SNB parcels, GeoNB civic addresses, wetlands, railways, OSM roads.

## Requirements
Docker with Compose, and about 4 GB of RAM. PostgreSQL 16 with PostGIS runs in a container.

## Install in five minutes
```bash
git clone https://github.com/wandc/fpm.git && cd fpm
cp .env.example .env && sed -i "s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=$(openssl rand -hex 24)/" .env
docker compose up -d --build
set -a; . ./.env; set +a
for f in schema reference_data seed_demo; do docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -q < db/$f.sql; done
```
Open **http://127.0.0.1:48731/** and sign in with **demo / demo**. That login is for trying the software out: turn it off
(`DEMO_LOGIN_ENABLED=false`) and set up Google sign-in before anyone else can reach the app.
The full, step-by-step runbook (HTTPS, users, your own name and logo, backups, updating, troubleshooting) is in
[INSTALL.md](INSTALL.md); it is written so an AI coding agent can follow it.

## Using it as a base for your own network
Read [AGENTS.md](AGENTS.md) and [INSTALL.md](INSTALL.md). It is written for AI coding agents as well as people: how the code is laid out,
the rules for database changes (migrations, change tracking), and how to prepare a change so it can be sent back.

## Licence
GNU AGPL v3 or later, see [LICENSE](LICENSE) and [NOTICE](NOTICE). If you run a modified version for other
people over a network, the AGPL requires you to offer them the source of your version.
