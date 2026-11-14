-- Runs once, on the first start of an empty database (docker-entrypoint-initdb.d).
-- The schema (db/schema.sql) refers to PostGIS types as topology.geometry, so the extensions live in a schema named
-- "topology" and the database search path includes it.
CREATE SCHEMA IF NOT EXISTS app;
CREATE SCHEMA IF NOT EXISTS topology;
CREATE EXTENSION IF NOT EXISTS postgis SCHEMA topology;
CREATE EXTENSION IF NOT EXISTS btree_gist SCHEMA topology;
CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA topology;
CREATE EXTENSION IF NOT EXISTS unaccent SCHEMA topology;
DO $$ BEGIN EXECUTE format('ALTER DATABASE %I SET search_path = "$user", public, topology', current_database()); END $$;
