--
-- PostgreSQL database dump
--

-- Dumped from database version 16.9 (Debian 16.9-1.pgdg110+1)
-- Dumped by pg_dump version 16.9 (Debian 16.9-1.pgdg110+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: app; Type: SCHEMA; Schema: -; Owner: -
--



--
-- Name: anchor_status; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.anchor_status AS ENUM (
    'plan',
    'construction',
    'active',
    'retired'
);


--
-- Name: bell_permit_status; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.bell_permit_status AS ENUM (
    'draft',
    'submitted',
    'search',
    'make_ready',
    'granted',
    'inspection_started',
    'inspection_complete',
    'active',
    'expired'
);


--
-- Name: conduit_status; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.conduit_status AS ENUM (
    'plan',
    'active',
    'retired'
);


--
-- Name: construction_stage; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.construction_stage AS ENUM (
    'not_started',
    'make_ready',
    'fibre_install',
    'splicing',
    'commissioning',
    'complete'
);


--
-- Name: enclosure_rating; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.enclosure_rating AS ENUM (
    'inside',
    'outside'
);


--
-- Name: fosc_kind; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.fosc_kind AS ENUM (
    'closure',
    'mst',
    'terminal',
    'cabinet',
    'optitap',
    'patch_panel',
    'slack'
);


--
-- Name: fosc_status; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.fosc_status AS ENUM (
    'active',
    'plan',
    'sent to manufacture',
    'being manufactured',
    'shipped',
    'on site',
    'slack loop',
    'retired',
    'under construction'
);


--
-- Name: join_side; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.join_side AS ENUM (
    'from',
    'to',
    'device'
);


--
-- Name: join_target; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.join_target AS ENUM (
    'cable_span',
    'fosc_device'
);


--
-- Name: span_end; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.span_end AS ENUM (
    'from',
    'to'
);


--
-- Name: splice_join_kind; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.splice_join_kind AS ENUM (
    'single',
    'ribbon',
    'pass_through',
    'connector'
);


--
-- Name: vault_status; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.vault_status AS ENUM (
    'plan',
    'active',
    'retired'
);


--
-- Name: vault_type; Type: TYPE; Schema: app; Owner: -
--

CREATE TYPE app.vault_type AS ENUM (
    'vault',
    'handhole',
    'pullbox',
    'manhole'
);


--
-- Name: _assemble_span(topology.geometry, topology.geometry, topology.geometry); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._assemble_span(middle topology.geometry, p_from topology.geometry, p_to topology.geometry) RETURNS topology.geometry
    LANGUAGE sql IMMUTABLE
    AS $$
  SELECT CASE
    WHEN middle IS NULL OR ST_NPoints(middle) = 0
      THEN ST_MakeLine(p_from, p_to)
    ELSE ST_LineMerge(ST_MakeLine(ARRAY[p_from, middle, p_to]))
  END;
$$;


--
-- Name: _clamp01(double precision); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._clamp01(x double precision) RETURNS double precision
    LANGUAGE sql IMMUTABLE STRICT
    AS $$ SELECT CASE WHEN x < 0 THEN 0 WHEN x > 1 THEN 1 ELSE x END $$;


--
-- Name: _decompose_span(topology.geometry, topology.geometry, topology.geometry, double precision); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._decompose_span(full_line topology.geometry, p_from topology.geometry, p_to topology.geometry, tol_m double precision) RETURNS topology.geometry
    LANGUAGE plpgsql IMMUTABLE
    AS $$
DECLARE
  n int;
  s geometry(Point,4326);
  e geometry(Point,4326);
  mid geometry(LineString,4326);
BEGIN
  IF full_line IS NULL OR GeometryType(full_line) <> 'LINESTRING' THEN
    RAISE EXCEPTION 'Expected a LINESTRING geometry.';
  END IF;

  n := ST_NPoints(full_line);
  IF n < 2 THEN
    RAISE EXCEPTION 'Line must have at least 2 vertices.';
  END IF;

  s := ST_StartPoint(full_line);
  e := ST_EndPoint(full_line);

  IF ST_Distance(s::geography, p_from::geography) > tol_m THEN
    RAISE EXCEPTION 'Start vertex (%.%) not within %m of FOSC-from (%.%).',
      ST_Y(s), ST_X(s), tol_m, ST_Y(p_from), ST_X(p_from);
  END IF;
  IF ST_Distance(e::geography, p_to::geography) > tol_m THEN
    RAISE EXCEPTION 'End vertex (%.%) not within %m of FOSC-to (%.%).',
      ST_Y(e), ST_X(e), tol_m, ST_Y(p_to), ST_X(p_to);
  END IF;

  -- Build middle from interior vertices (indices 2..n-1)
  IF n <= 2 THEN
    RETURN NULL; -- no middle
  END IF;

  SELECT CASE
           WHEN COUNT(*) < 2 THEN NULL
           ELSE ST_MakeLine(ARRAY_AGG(dp.geom ORDER BY (dp.path)[1]))
         END
  INTO mid
  FROM (
    SELECT (dp).geom AS geom, (dp).path AS path
    FROM ST_DumpPoints(full_line) AS dp
  ) AS dp
  WHERE (dp.path)[1] BETWEEN 2 AND n-1;

  RETURN mid;
END;
$$;


--
-- Name: _endpoint_fosc(bigint, app.span_end); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._endpoint_fosc(p_span_id bigint, p_end app.span_end) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
  SELECT CASE p_end
           WHEN 'from' THEN from_fosc_id
           WHEN 'to'   THEN to_fosc_id
         END
  FROM app.cable_span
  WHERE cable_span_id = p_span_id
$$;


--
-- Name: _endpoints(integer, integer); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._endpoints(from_id integer, to_id integer, OUT p_from topology.geometry, OUT p_to topology.geometry) RETURNS record
    LANGUAGE sql STABLE
    AS $$
  SELECT f1.location, f2.location
  FROM app.fosc f1, app.fosc f2
  WHERE f1.fosc_id = from_id AND f2.fosc_id = to_id;
$$;


--
-- Name: _endpoints(bigint, bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._endpoints(p_from_id bigint, p_to_id bigint) RETURNS TABLE(from_pt topology.geometry, to_pt topology.geometry)
    LANGUAGE sql STABLE
    AS $$
  SELECT
    f1.location::geometry(Point,4326) AS from_pt,
    f2.location::geometry(Point,4326) AS to_pt
  FROM app.fosc f1
  JOIN app.fosc f2 ON f2.fosc_id = p_to_id
  WHERE f1.fosc_id = p_from_id
$$;


--
-- Name: _strip_endpoints(topology.geometry, topology.geometry, topology.geometry, double precision); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app._strip_endpoints(g topology.geometry, from_pt topology.geometry, to_pt topology.geometry, tol double precision DEFAULT 0.00002) RETURNS topology.geometry
    LANGUAGE plpgsql IMMUTABLE
    AS $$
DECLARE
  n int;
  snapped geometry(LineString,4326);
  mid geometry;
BEGIN
  IF g IS NULL THEN
    RETURN NULL;
  END IF;

  -- Snap to endpoints to kill tiny digitizing gaps
  snapped := ST_Snap(g, ST_Collect(from_pt, to_pt), tol)::geometry(LineString,4326);

  n := ST_NPoints(snapped);
  IF n <= 2 THEN
    -- nothing (or only endpoints) to keep
    RETURN NULL;
  END IF;

  -- Drop last point, then first point
  mid := ST_RemovePoint(snapped, n-1);
  mid := ST_RemovePoint(mid, 0);

  IF ST_NPoints(mid) < 2 THEN
    -- degenerated middle, store NULL
    RETURN NULL;
  END IF;

  RETURN mid;
END
$$;


--
-- Name: add_drop_span(bigint, text, topology.geometry, text, integer, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.add_drop_span(p_mst_fosc_id bigint, p_drop_label text, p_geom topology.geometry, p_cable_name text, p_fiber_ct integer DEFAULT 4, p_attrs jsonb DEFAULT '{"endpoint": "optitap"}'::jsonb) RETURNS TABLE(terminal_fosc_id bigint, cable_id bigint, cable_span_id bigint)
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_term_id  bigint;
  v_cable_id bigint;
  v_line     geometry(LineString);
  v_line_srid integer;
  g_mst  geometry(Point);
  g_term geometry(Point);
BEGIN
  -- ensure/reuse terminal and cable
  v_term_id := app.ensure_terminal_fosc(p_drop_label, p_geom, p_attrs);
  v_cable_id := app.ensure_cable(
    p_cable_name,
    jsonb_build_object('fiber_ct', p_fiber_ct) || COALESCE(p_attrs,'{}'::jsonb),
    p_fiber_ct
  );

  -- try to write a geom if cable_span.geom exists
  BEGIN
    -- target SRID for cable_span.geom (if column exists and is typed)
    SELECT Find_SRID('app','cable_span','geom') INTO v_line_srid;

    SELECT geom INTO g_mst  FROM app.fosc WHERE fosc_id = p_mst_fosc_id;
    SELECT geom INTO g_term FROM app.fosc WHERE fosc_id = v_term_id;

    IF v_line_srid IS NOT NULL AND v_line_srid > 0 THEN
      IF ST_SRID(g_mst)  <> v_line_srid THEN g_mst  := ST_Transform(g_mst,  v_line_srid); END IF;
      IF ST_SRID(g_term) <> v_line_srid THEN g_term := ST_Transform(g_term, v_line_srid); END IF;
      v_line := ST_SetSRID(ST_MakeLine(g_mst, g_term), v_line_srid);

      INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, geom)
      VALUES (v_cable_id, p_mst_fosc_id, v_term_id, v_line)
      RETURNING cable_span_id INTO cable_span_id;
    ELSE
      -- no geom column or unknown SRID
      INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id)
      VALUES (v_cable_id, p_mst_fosc_id, v_term_id)
      RETURNING cable_span_id INTO cable_span_id;
    END IF;

  EXCEPTION WHEN undefined_column THEN
    -- cable_span.geom doesn’t exist: insert without geometry
    INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id)
    VALUES (v_cable_id, p_mst_fosc_id, v_term_id)
    RETURNING cable_span_id INTO cable_span_id;
  END;

  terminal_fosc_id := v_term_id;
  cable_id         := v_cable_id;
  RETURN NEXT;
END;
$$;


--
-- Name: add_drop_span(bigint, text, double precision, double precision, text, integer, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.add_drop_span(p_mst_fosc_id bigint, p_drop_label text, p_lon double precision, p_lat double precision, p_cable_name text, p_fiber_ct integer DEFAULT 4, p_attrs jsonb DEFAULT '{"endpoint": "optitap"}'::jsonb) RETURNS TABLE(terminal_fosc_id bigint, cable_id bigint, cable_span_id bigint)
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_term_id  bigint;
  v_cable_id bigint;
BEGIN
  -- 1) Ensure the terminal FOSC exists (your function)
  v_term_id := app.ensure_terminal_fosc(p_drop_label, p_lon, p_lat, p_attrs);

  -- 2) Ensure the cable exists
  v_cable_id := app.ensure_cable(
    p_cable_name,
    jsonb_build_object('fiber_ct', p_fiber_ct) || COALESCE(p_attrs,'{}'::jsonb),
    p_fiber_ct
  );

  -- 3) Insert the span (prefer with geom if column exists)
  BEGIN
    INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, geom)
    VALUES (
      v_cable_id,
      p_mst_fosc_id,
      v_term_id,
      ST_SetSRID(
        ST_MakeLine(
          (SELECT location FROM app.fosc WHERE fosc_id = p_mst_fosc_id),
          ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326)
        ), 4326
      )
    )
    RETURNING cable_span_id INTO cable_span_id;
  EXCEPTION WHEN undefined_column THEN
    INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id)
    VALUES (v_cable_id, p_mst_fosc_id, v_term_id)
    RETURNING cable_span_id INTO cable_span_id;
  END;

  terminal_fosc_id := v_term_id;
  cable_id         := v_cable_id;
  RETURN NEXT;
END;
$$;


--
-- Name: add_drop_span_wkt(bigint, text, text, integer, text, integer, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.add_drop_span_wkt(p_mst_fosc_id bigint, p_drop_label text, p_wkt text, p_wkt_srid integer, p_cable_name text, p_fiber_ct integer DEFAULT 4, p_attrs jsonb DEFAULT '{"endpoint": "optitap"}'::jsonb) RETURNS TABLE(terminal_fosc_id bigint, cable_id bigint, cable_span_id bigint)
    LANGUAGE plpgsql
    AS $$
BEGIN
  RETURN QUERY
  SELECT * FROM app.add_drop_span(
    p_mst_fosc_id,
    p_drop_label,
    ST_SetSRID(ST_GeomFromText(p_wkt), p_wkt_srid)::geometry(Point),
    p_cable_name,
    p_fiber_ct,
    p_attrs
  );
END;
$$;


--
-- Name: audit_pk_value(oid, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.audit_pk_value(p_relid oid, p_row jsonb) RETURNS text
    LANGUAGE sql STABLE
    AS $$
    SELECT COALESCE(
        (SELECT string_agg(p_row ->> a.attname, '|' ORDER BY k.ord)
           FROM pg_index i
           CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
           JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
          WHERE i.indrelid = p_relid AND i.indisprimary),
        -- no primary key: fall back to the historical guess
        COALESCE(p_row ->> 'id', p_row ->> 'fosc_id',
                 p_row ->> 'cable_id', p_row ->> 'splice_join_id')
    )
$$;


--
-- Name: audit_redact(jsonb, oid); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.audit_redact(p_row jsonb, p_relid oid) RETURNS jsonb
    LANGUAGE sql STABLE
    AS $$
    SELECT CASE
        WHEN p_row IS NULL THEN NULL
        ELSE COALESCE((
            SELECT jsonb_object_agg(
                     e.key,
                     CASE WHEN e.key IN (
                            SELECT a.attname
                              FROM pg_attribute a
                              JOIN pg_type t ON t.oid = a.atttypid
                             WHERE a.attrelid = p_relid
                               AND a.attnum > 0
                               AND NOT a.attisdropped
                               AND t.typname = 'bytea')
                          THEN to_jsonb('[bytea omitted]'::text)
                          ELSE e.value
                     END)
              FROM jsonb_each(p_row) e
        ), p_row)
    END
$$;


--
-- Name: audit_row_change(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.audit_row_change() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_cid  text := app.current_change();
  v_actor_uid bigint;
  v_actor text;
  v_pk text := NULL;
BEGIN
  SELECT cs.app_user_id, cs.actor_code INTO v_actor_uid, v_actor FROM app.change_set cs WHERE cs.change_id = v_cid;

  IF TG_OP = 'INSERT' THEN
    v_pk := app.audit_pk_value(TG_RELID, to_jsonb(NEW));
    INSERT INTO app.audit_log(actor_user_id, actor_username, action, schema_name, table_name, row_pk, old_data, new_data, change_id)
    VALUES (v_actor_uid, v_actor, TG_OP, TG_TABLE_SCHEMA, TG_TABLE_NAME, v_pk, NULL, app.audit_redact(to_jsonb(NEW), TG_RELID), v_cid);
    RETURN NEW;
  ELSIF TG_OP = 'UPDATE' THEN
    v_pk := app.audit_pk_value(TG_RELID, to_jsonb(NEW));
    INSERT INTO app.audit_log(actor_user_id, actor_username, action, schema_name, table_name, row_pk, old_data, new_data, change_id)
    VALUES (v_actor_uid, v_actor, TG_OP, TG_TABLE_SCHEMA, TG_TABLE_NAME, v_pk,
            app.audit_redact(to_jsonb(OLD), TG_RELID), app.audit_redact(to_jsonb(NEW), TG_RELID), v_cid);
    RETURN NEW;
  ELSIF TG_OP = 'DELETE' THEN
    v_pk := app.audit_pk_value(TG_RELID, to_jsonb(OLD));
    INSERT INTO app.audit_log(actor_user_id, actor_username, action, schema_name, table_name, row_pk, old_data, new_data, change_id)
    VALUES (v_actor_uid, v_actor, TG_OP, TG_TABLE_SCHEMA, TG_TABLE_NAME, v_pk, app.audit_redact(to_jsonb(OLD), TG_RELID), NULL, v_cid);
    RETURN OLD;
  END IF;
  RETURN NULL;
END;
$$;


--
-- Name: begin_change(text, text, text); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.begin_change(p_actor text, p_source text, p_note text DEFAULT NULL::text) RETURNS text
    LANGUAGE plpgsql
    AS $$
BEGIN
    PERFORM set_config('app.actor',  p_actor,  false);
    PERFORM set_config('app.source', p_source, false);
    PERFORM set_config('app.note',   COALESCE(p_note, ''), false);
    PERFORM set_config('app.change_id', '', false);
    RETURN app.current_change();
END $$;


--
-- Name: bom_price_landed_cad(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.bom_price_landed_cad() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF NEW.foreign_price IS NOT NULL THEN
    NEW.price_per_unit := round(NEW.foreign_price * NEW.fx_rate * (1 + COALESCE(NEW.freight_pct, 0) / 100), 4);
    NEW.currency := 'CAD';
  END IF;
  RETURN NEW;
END $$;


--
-- Name: cable_span_enforce_endpoints(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.cable_span_enforce_endpoints() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  tol_m       double precision := 2.0;               -- endpoint tolerance (meters)
  snap_policy text := 'snap';                         -- 'snap' or 'error'

  g         geometry(LineString,4326);
  from_loc  geometry(Point,4326);
  to_loc    geometry(Point,4326);
  start_pt  geometry(Point,4326);
  end_pt    geometry(Point,4326);
  d_sf      double precision;
  d_et      double precision;
  d_st      double precision;
  d_ef      double precision;
BEGIN
  IF NEW.from_fosc_id IS NULL OR NEW.to_fosc_id IS NULL THEN
    RAISE EXCEPTION 'from_fosc_id and to_fosc_id are required';
  END IF;
  IF NEW.from_fosc_id = NEW.to_fosc_id THEN
    RAISE EXCEPTION 'from_fosc_id must differ from to_fosc_id';
  END IF;

  SELECT location INTO from_loc FROM app.fosc WHERE fosc_id = NEW.from_fosc_id;
  SELECT location INTO to_loc   FROM app.fosc WHERE fosc_id = NEW.to_fosc_id;
  IF from_loc IS NULL OR to_loc IS NULL THEN
    RAISE EXCEPTION 'Referenced FOSC endpoints not found (% -> %)', NEW.from_fosc_id, NEW.to_fosc_id;
  END IF;

  -- Take the authoritative geometry from NEW.geom_snap; if missing, assemble from middle+endpoints
  IF NEW.geom_snap IS NULL OR ST_IsEmpty(NEW.geom_snap) THEN
    IF NEW.geom_middle IS NOT NULL AND NOT ST_IsEmpty(NEW.geom_middle) THEN
      g := app._assemble_span(NEW.geom_middle, from_loc, to_loc);
    ELSE
      -- as a last resort, build a straight segment between FOSCs
      g := ST_MakeLine(from_loc, to_loc);
    END IF;
  ELSE
    g := NEW.geom_snap;
  END IF;

  IF ST_SRID(g) <> 4326 THEN
    g := ST_SetSRID(g, 4326);
  END IF;
  IF GeometryType(g) <> 'LINESTRING' OR ST_NPoints(g) < 2 THEN
    RAISE EXCEPTION 'geom_snap must be a non-empty LINESTRING with >= 2 points';
  END IF;

  start_pt := ST_StartPoint(g);
  end_pt   := ST_EndPoint(g);

  -- compute distances to decide flip/snap
  d_sf := ST_Distance(start_pt::geography, from_loc::geography);
  d_et := ST_Distance(end_pt::geography,   to_loc::geography);
  d_st := ST_Distance(start_pt::geography, to_loc::geography);
  d_ef := ST_Distance(end_pt::geography,   from_loc::geography);

  -- if drawn backwards relative to the given FOSCs, flip
  IF NOT (d_sf <= tol_m AND d_et <= tol_m) AND (d_st <= tol_m AND d_ef <= tol_m) THEN
    g := ST_Reverse(g);
    start_pt := ST_StartPoint(g);
    end_pt   := ST_EndPoint(g);
    d_sf := ST_Distance(start_pt::geography, from_loc::geography);
    d_et := ST_Distance(end_pt::geography,   to_loc::geography);
  END IF;

  -- snap (or error) to exact endpoints
  IF d_sf > tol_m OR d_et > tol_m THEN
    IF snap_policy = 'snap' THEN
      g := ST_SetPoint(g, 0, from_loc);
      g := ST_SetPoint(g, ST_NPoints(g)-1, to_loc);
    ELSE
      RAISE EXCEPTION
        'Span endpoints must lie within % m of their FOSC points (start=%.2f m, end=%.2f m)',
        tol_m, d_sf, d_et;
    END IF;
  END IF;

  NEW.geom_snap := g;  -- write back the authoritative geometry

  -- geom_middle is the interior of the final route (NULL for a two-point span), so it cannot go stale.
  NEW.geom_middle := CASE WHEN ST_NPoints(g) > 2
    THEN ST_SetSRID(ST_MakeLine(ARRAY(SELECT (dp).geom FROM ST_DumpPoints(g) dp
                                       WHERE (dp).path[1] BETWEEN 2 AND ST_NPoints(g) - 1
                                       ORDER BY (dp).path[1])), 4326)
    END;
  RETURN NEW;
END
$$;


--
-- Name: check_slack_loop_cables(bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.check_slack_loop_cables(_fosc_id bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_kind     text;
  v_n_spans  integer;
  v_n_cables integer;
BEGIN
  SELECT kind INTO v_kind
  FROM app.fosc
  WHERE fosc_id = _fosc_id;

  IF v_kind IS NULL OR v_kind <> 'slack' THEN
    RETURN;
  END IF;

  SELECT count(*) AS n_spans,
         count(DISTINCT cable_id) AS n_cables
  INTO v_n_spans, v_n_cables
  FROM app.cable_span
  WHERE from_fosc_id = _fosc_id
     OR to_fosc_id   = _fosc_id;

  IF v_n_spans > 2 THEN
    RAISE EXCEPTION
      'Slack-loop FOSC % cannot have more than 2 cable spans (found %).',
      _fosc_id, v_n_spans
      USING ERRCODE = 'check_violation';
  END IF;

  IF v_n_spans = 2 AND v_n_cables > 1 THEN
    RAISE EXCEPTION
      'Slack-loop FOSC % must have both spans on the same cable_id (found % different cable_ids).',
      _fosc_id, v_n_cables
      USING ERRCODE = 'check_violation';
  END IF;

  -- note: v_n_spans = 0 or 1 is allowed while designing
END;
$$;


--
-- Name: closure_is_housed(topology.geometry, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.closure_is_housed(p_loc topology.geometry, p_attrs jsonb) RETURNS boolean
    LANGUAGE sql STABLE
    AS $$
    SELECT COALESCE((p_attrs ->> 'direct_buried')::boolean, false)
        OR COALESCE((p_attrs ->> 'indoor')::boolean, false)
        OR EXISTS (SELECT 1 FROM app.pole p WHERE ST_DWithin(p.geom::geography, p_loc::geography, 20))
        OR EXISTS (SELECT 1 FROM app.vault v WHERE ST_DWithin(v.geom::geography, p_loc::geography, 5))
$$;


--
-- Name: current_change(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.current_change() RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    v  text := NULLIF(current_setting('app.change_id', true), '');
    a  text;
    s  text;
    n  bigint;
    u  bigint;
BEGIN
    -- Reuse the open change, unless it was opened inside a transaction that rolled back.
    IF v IS NOT NULL AND EXISTS (SELECT 1 FROM app.change_set WHERE change_id = v) THEN
        RETURN v;
    END IF;
    a := COALESCE(NULLIF(current_setting('app.actor', true), ''), 'UNKN');
    IF NOT EXISTS (SELECT 1 FROM app.actor WHERE actor_code = a AND active) THEN a := 'UNKN'; END IF;
    s := COALESCE(NULLIF(current_setting('app.source', true), ''), 'untracked');
    IF s NOT IN ('web', 'api', 'psql', 'cron', 'script', 'migration', 'untracked') THEN s := 'untracked'; END IF;
    BEGIN u := NULLIF(current_setting('app.user_id', true), '')::bigint; EXCEPTION WHEN others THEN u := NULL; END;
    IF u IS NOT NULL AND NOT EXISTS (SELECT 1 FROM app.app_user WHERE app_user_id = u) THEN u := NULL; END IF;
    n := nextval('app.change_set_seq');
    v := a || '_' || n;
    INSERT INTO app.change_set (change_id, change_no, actor_code, app_user_id, source, note)
    VALUES (v, n, a, u, s,
            left(COALESCE(NULLIF(current_setting('app.note', true), ''),
                          NULLIF(current_setting('application_name', true), ''), s), 300));
    PERFORM set_config('app.change_id', v, false);
    RETURN v;
END $$;


--
-- Name: ensure_cable(text, jsonb, integer); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.ensure_cable(p_name text, p_attrs jsonb DEFAULT '{}'::jsonb, p_fiber_ct integer DEFAULT NULL::integer) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_id bigint;
BEGIN
  SELECT cable_id INTO v_id
  FROM app.cable
  WHERE name = p_name
  LIMIT 1;

  IF v_id IS NULL THEN
    -- Try insert including fiber_count if the column exists
    BEGIN
      INSERT INTO app.cable (name, attrs, fiber_count)
      VALUES (p_name, COALESCE(p_attrs,'{}'::jsonb), p_fiber_ct)
      RETURNING cable_id INTO v_id;
    EXCEPTION WHEN undefined_column THEN
      -- No fiber_count column — store the count in attrs instead
      INSERT INTO app.cable (name, attrs)
      VALUES (p_name, COALESCE(jsonb_build_object('fiber_ct', p_fiber_ct) || p_attrs, '{}'::jsonb))
      RETURNING cable_id INTO v_id;
    END;
  END IF;

  RETURN v_id;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: splice_join; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.splice_join (
    splice_join_id bigint NOT NULL,
    fosc_id bigint NOT NULL,
    a_target app.join_target NOT NULL,
    a_span_id bigint NOT NULL,
    a_end app.join_side NOT NULL,
    a_range int4range NOT NULL,
    b_target app.join_target NOT NULL,
    b_span_id bigint NOT NULL,
    b_end app.join_side NOT NULL,
    b_range int4range NOT NULL,
    notes text,
    is_plan boolean DEFAULT false NOT NULL,
    join_kind app.splice_join_kind DEFAULT 'single'::app.splice_join_kind NOT NULL,
    retired_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now(),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: ensure_slack_loop_splice(bigint, int4range, app.splice_join_kind); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.ensure_slack_loop_splice(p_fosc_id bigint, p_range int4range, p_kind app.splice_join_kind DEFAULT 'pass_through'::app.splice_join_kind) RETURNS app.splice_join
    LANGUAGE plpgsql
    AS $$
DECLARE
    span_ids bigint[];
    span1    bigint;
    span2    bigint;
    end1     app.join_side;
    end2     app.join_side;
    sj_row   app.splice_join%ROWTYPE;
BEGIN
    -- Find exactly two spans on this FOSC
    SELECT ARRAY_AGG(cs.cable_span_id ORDER BY cs.cable_span_id)
      INTO span_ids
    FROM app.cable_span cs
    WHERE cs.from_fosc_id = p_fosc_id
       OR cs.to_fosc_id   = p_fosc_id;

    IF span_ids IS NULL OR array_length(span_ids, 1) <> 2 THEN
        RAISE EXCEPTION 'FOSC % does not have exactly two cable_spans', p_fosc_id;
    END IF;

    span1 := span_ids[1];
    span2 := span_ids[2];

    -- Derive correct end: which end of each span is attached to this FOSC
    SELECT CASE WHEN cs.from_fosc_id = p_fosc_id THEN 'from' ELSE 'to' END::app.join_side
      INTO end1
    FROM app.cable_span cs WHERE cs.cable_span_id = span1;

    SELECT CASE WHEN cs.from_fosc_id = p_fosc_id THEN 'from' ELSE 'to' END::app.join_side
      INTO end2
    FROM app.cable_span cs WHERE cs.cable_span_id = span2;

    -- Return existing record if one already exists for this fosc + span pair + range
    SELECT *
      INTO sj_row
    FROM app.splice_join j
    WHERE j.fosc_id  = p_fosc_id
      AND j.a_target = 'cable_span'
      AND j.b_target = 'cable_span'
      AND (
            (j.a_span_id = span1 AND j.b_span_id = span2)
         OR (j.a_span_id = span2 AND j.b_span_id = span1)
          )
      AND j.a_range  = p_range
      AND j.b_range  = p_range
    LIMIT 1;

    IF FOUND THEN
        RETURN sj_row;
    END IF;

    -- Insert new pass-through join
    INSERT INTO app.splice_join (
        fosc_id,
        a_target,  a_span_id, a_end, a_range,
        b_target,  b_span_id, b_end, b_range,
        notes, is_plan, join_kind
    )
    VALUES (
        p_fosc_id,
        'cable_span', span1, end1, p_range,
        'cable_span', span2, end2, p_range,
        'auto slack-loop join', false, p_kind
    )
    RETURNING * INTO sj_row;

    RETURN sj_row;
END;
$$;


--
-- Name: ensure_terminal_fosc(text, topology.geometry, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.ensure_terminal_fosc(p_label text, p_geom topology.geometry, p_attrs jsonb DEFAULT '{}'::jsonb) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_id   bigint;
  v_srid integer;
  v_geom geometry(Point);
BEGIN
  -- reuse by label if it already exists
  SELECT fosc_id INTO v_id
  FROM app.fosc
  WHERE kind = 'terminal' AND splice_num = p_label
  LIMIT 1;

  IF v_id IS NOT NULL THEN
    RETURN v_id;
  END IF;

  -- normalize SRID to match app.fosc.geom
  SELECT Find_SRID('app','fosc','geom') INTO v_srid;
  IF COALESCE(ST_SRID(p_geom),0) = 0 THEN
    v_geom := ST_SetSRID(p_geom, v_srid);
  ELSIF ST_SRID(p_geom) <> v_srid THEN
    v_geom := ST_Transform(p_geom, v_srid);
  ELSE
    v_geom := p_geom;
  END IF;

  INSERT INTO app.fosc (splice_num, geom, kind, attrs)
  VALUES (p_label, v_geom, 'terminal', COALESCE(p_attrs,'{}'::jsonb))
  RETURNING fosc_id INTO v_id;

  RETURN v_id;
END;
$$;


--
-- Name: ensure_terminal_fosc(text, double precision, double precision, jsonb); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.ensure_terminal_fosc(p_label text, p_lon double precision, p_lat double precision, p_attrs jsonb DEFAULT '{}'::jsonb) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE v_id bigint;
BEGIN
  SELECT fosc_id INTO v_id
  FROM app.fosc
  WHERE kind='terminal' AND splice_num = p_label
  LIMIT 1;

  IF v_id IS NULL THEN
    INSERT INTO app.fosc (splice_num, location, kind, attrs)
    VALUES (p_label, ST_SetSRID(ST_MakePoint(p_lon,p_lat),4326), 'terminal', COALESCE(p_attrs,'{}'::jsonb))
    RETURNING fosc_id INTO v_id;
  END IF;

  RETURN v_id;
END;
$$;


--
-- Name: expansion_overbuild(numeric, text); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.expansion_overbuild(p_min_m numeric DEFAULT 300, p_wo text DEFAULT '2026-15-CRTCExpansion'::text) RETURNS TABLE(seg_id integer, road_name text, kind text, len_m numeric, competitors text[], run_id text, run_m numeric, is_overbuild boolean)
    LANGUAGE sql STABLE
    AS $$
    WITH cab AS MATERIALIZED (
        SELECT coalesce(ST_Union(ST_Transform(cs.geom_snap, 2953)), ST_GeomFromText('GEOMETRYCOLLECTION EMPTY', 2953)) AS g
        FROM app.cable_span cs
        WHERE cs.fpm_wor = p_wo
           OR EXISTS (SELECT 1 FROM app.cable_span_work_order w WHERE w.cable_span_id = cs.cable_span_id AND w.work_order = p_wo)
    ),
    seg AS MATERIALIZED (
        SELECT rc.seg_id, coalesce(rc.road_name, '') AS road_name, rc.geom, ST_Transform(rc.geom, 2953) AS g,
               ST_Length(rc.geom::geography)::numeric AS len_m,
               (EXISTS (SELECT 1 FROM app.road_coverage_service s WHERE s.seg_id = rc.seg_id AND s.provider = app.own_name() AND s.technology = 'fiber_expansion')
                AND NOT EXISTS (SELECT 1 FROM app.road_coverage_service s WHERE s.seg_id = rc.seg_id AND s.provider = app.own_name()
                                AND s.technology IN ('fiber_ftth', 'fiber_mainline', 'fiber_coming_soon'))) AS is_exp,
               array(SELECT DISTINCT s.provider || ' ' || s.technology
                     FROM app.road_coverage_service s
                     WHERE s.seg_id = rc.seg_id
                       AND ((s.provider = 'Bell Aliant' AND s.technology = 'fiber_ftth')
                         OR (s.provider = 'Rogers' AND s.technology IN ('fiber_ftth', 'hybrid_coax')))
                     ORDER BY 1) AS competitors
        FROM app.road_coverage rc
    ),
    -- Cable that runs ALONG a segment (about 60 m inside a 20 m corridor), not just
    -- crossing it at a junction.
    cand AS MATERIALIZED (
        SELECT s.*, (cardinality(s.competitors) > 0
                     AND ST_Length(ST_Intersection(ST_Buffer(s.g, 20), cab.g)) >= least(60, 0.4 * s.len_m)) AS on_cable
        FROM seg s, cab
        WHERE s.is_exp OR (cardinality(s.competitors) > 0 AND ST_DWithin(s.g, cab.g, 20))
    ),
    flagged AS (
        SELECT c.*, ST_ClusterDBSCAN(c.g, 3, 1) OVER (PARTITION BY c.road_name) AS cid
        FROM cand c
        WHERE cardinality(c.competitors) > 0 AND (c.is_exp OR c.on_cable)
    ),
    runs AS (SELECT f.road_name, f.cid, sum(f.len_m) AS run_m FROM flagged f GROUP BY 1, 2)
    SELECT c.seg_id::integer, c.road_name, CASE WHEN c.is_exp THEN 'expansion' ELSE 'feeder' END,
           round(c.len_m, 1), c.competitors,
           r.road_name || '#' || r.cid, round(r.run_m, 1), coalesce(r.run_m >= p_min_m, false)
    FROM cand c
    LEFT JOIN flagged f ON f.seg_id = c.seg_id
    LEFT JOIN runs r ON r.road_name = f.road_name AND r.cid = f.cid
    WHERE c.is_exp OR f.seg_id IS NOT NULL
$$;


--
-- Name: fosc_fdc_span(bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.fosc_fdc_span(p_fosc_id bigint) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
    SELECT fdc_span_id FROM app.fosc_trace_stats(p_fosc_id);
$$;


--
-- Name: fosc_path_loss(bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.fosc_path_loss(p_fosc_id bigint) RETURNS numeric
    LANGUAGE sql STABLE
    AS $$
    SELECT path_loss_db FROM app.fosc_trace_stats(p_fosc_id);
$$;


--
-- Name: fosc_trace_stats(bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.fosc_trace_stats(p_fosc_id bigint) RETURNS TABLE(path_loss_db numeric, fdc_span_id bigint, parent_closure_label text, parent_fosc_id bigint)
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    v_span_id    bigint;
    v_fn         int;
    v_dir        app.span_end;
    v_fc         int;
    v_loss       numeric;
    v_fdc        bigint;
    v_parent     text;
    v_parent_id  bigint;
BEGIN
    FOR v_span_id, v_dir, v_fc IN
        SELECT cs.cable_span_id,
               CASE WHEN cs.from_fosc_id = p_fosc_id
                    THEN 'to'::app.span_end
                    ELSE 'from'::app.span_end END,
               c.fibre_count
          FROM app.cable_span cs
          JOIN app.cable c ON c.cable_id = cs.cable_id
         WHERE cs.from_fosc_id = p_fosc_id
            OR cs.to_fosc_id   = p_fosc_id
    LOOP
        FOR v_fn IN 1..v_fc LOOP
            WITH tr AS (
                SELECT t.hop_num, t.fosc_id AS tr_fosc_id, t.cable_span_id,
                       t.cumulative_loss_db, t.device_type,
                       f.kind AS fosc_kind, f.splice_num
                  FROM app.trace_light_path(v_span_id, v_fn, v_dir, false, 300) t
                  LEFT JOIN app.fosc f ON f.fosc_id = t.fosc_id
            )
            SELECT
                (SELECT MIN(cumulative_loss_db) FROM tr WHERE device_type = 'olt_pon'),
                (SELECT cable_span_id FROM tr
                  WHERE fosc_kind IN ('cabinet', 'patch_panel')
                  ORDER BY cumulative_loss_db ASC LIMIT 1),
                (SELECT splice_num  FROM tr
                  WHERE fosc_kind NOT IN ('optitap') AND splice_num IS NOT NULL
                  ORDER BY hop_num ASC LIMIT 1),
                (SELECT tr_fosc_id FROM tr
                  WHERE fosc_kind NOT IN ('optitap') AND splice_num IS NOT NULL
                  ORDER BY hop_num ASC LIMIT 1)
            INTO v_loss, v_fdc, v_parent, v_parent_id;

            IF v_loss IS NOT NULL OR v_fdc IS NOT NULL THEN
                RETURN QUERY SELECT v_loss, v_fdc, v_parent, v_parent_id;
                RETURN;
            END IF;
        END LOOP;
    END LOOP;

    RETURN QUERY SELECT NULL::numeric, NULL::bigint, NULL::text, NULL::bigint;
END;
$$;


--
-- Name: fosc_upper_splice_trg(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.fosc_upper_splice_trg() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.splice_num := UPPER(NEW.splice_num);
  RETURN NEW;
END; $$;


--
-- Name: get_connected_spans(bigint, integer); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.get_connected_spans(p_start_span_id bigint, p_max_hops integer DEFAULT 500) RETURNS jsonb
    LANGUAGE sql STABLE
    AS $$
WITH RECURSIVE connected_spans AS (
  -- Base case: starting span
  SELECT
    p_start_span_id as span_id,
    1 as hop,
    ARRAY[p_start_span_id] as visited_spans
  
  UNION ALL
  
  -- Recursive case: find connected spans through splice_join
  SELECT DISTINCT
    CASE 
      -- If current span is on side A, get side B
      WHEN sj.a_span_id = cs.span_id AND sj.a_target = 'cable_span' 
           AND sj.b_target = 'cable_span'
        THEN sj.b_span_id
      -- If current span is on side B, get side A
      WHEN sj.b_span_id = cs.span_id AND sj.b_target = 'cable_span' 
           AND sj.a_target = 'cable_span'
        THEN sj.a_span_id
      ELSE NULL
    END as next_span_id,
    cs.hop + 1,
    cs.visited_spans || ARRAY[
      CASE 
        WHEN sj.a_span_id = cs.span_id AND sj.a_target = 'cable_span' 
             AND sj.b_target = 'cable_span'
          THEN sj.b_span_id
        WHEN sj.b_span_id = cs.span_id AND sj.b_target = 'cable_span' 
             AND sj.a_target = 'cable_span'
          THEN sj.a_span_id
      END
    ]
  FROM connected_spans cs
  JOIN app.splice_join sj ON (
    (sj.a_span_id = cs.span_id AND sj.a_target = 'cable_span')
    OR 
    (sj.b_span_id = cs.span_id AND sj.b_target = 'cable_span')
  )
  WHERE cs.hop < p_max_hops
    AND sj.is_plan = false  -- Only follow actual splices
    AND (
      -- Prevent loops: don't revisit spans
      (sj.a_span_id = cs.span_id AND sj.a_target = 'cable_span' 
       AND sj.b_target = 'cable_span' AND NOT (sj.b_span_id = ANY(cs.visited_spans)))
      OR
      (sj.b_span_id = cs.span_id AND sj.b_target = 'cable_span' 
       AND sj.a_target = 'cable_span' AND NOT (sj.a_span_id = ANY(cs.visited_spans)))
    )
),
-- Get unique spans
unique_spans AS (
  SELECT DISTINCT span_id
  FROM connected_spans
  WHERE span_id IS NOT NULL
),
-- Gather full span details with geometry
span_details AS (
  SELECT 
    cs.cable_span_id,
    cs.cable_id,
    c.name as cable_name,
    c.fibre_count,
    cs.from_fosc_id,
    f1.splice_num as from_fosc_label,
    cs.to_fosc_id,
    f2.splice_num as to_fosc_label,
    cs.length_m,
    cs.notes,
    cs.geom,
    topology.ST_Y(topology.ST_StartPoint(cs.geom)) as start_lat,
    topology.ST_X(topology.ST_StartPoint(cs.geom)) as start_lon,
    topology.ST_Y(topology.ST_EndPoint(cs.geom)) as end_lat,
    topology.ST_X(topology.ST_EndPoint(cs.geom)) as end_lon
  FROM unique_spans us
  JOIN app.cable_span cs ON cs.cable_span_id = us.span_id
  JOIN app.cable c ON c.cable_id = cs.cable_id
  LEFT JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
  LEFT JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
  ORDER BY cs.cable_span_id
)
SELECT jsonb_agg(
  jsonb_build_object(
    'span_id', cable_span_id,
    'cable_id', cable_id,
    'cable_name', cable_name,
    'fibre_count', fibre_count,
    'from_fosc_id', from_fosc_id,
    'from_fosc_label', from_fosc_label,
    'to_fosc_id', to_fosc_id,
    'to_fosc_label', to_fosc_label,
    'length_m', ROUND(length_m::numeric, 2),
    'notes', notes,
    'start_lat', start_lat,
    'start_lon', start_lon,
    'end_lat', end_lat,
    'end_lon', end_lon,
    'geometry', topology.ST_AsGeoJSON(geom)::jsonb
  )
)
FROM span_details;
$$;


--
-- Name: get_span_fiber_info(bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.get_span_fiber_info(p_span_id bigint) RETURNS TABLE(cable_span_id bigint, cable_id bigint, cable_name text, fiber_count integer, from_fosc_id bigint, from_label text, to_fosc_id bigint, to_label text, length_m double precision)
    LANGUAGE sql STABLE
    AS $$
  SELECT 
    cs.cable_span_id,
    cs.cable_id,
    c.name,
    c.fibre_count,
    cs.from_fosc_id,
    f1.splice_num,
    cs.to_fosc_id,
    f2.splice_num,
    cs.length_m
  FROM app.cable_span cs
  JOIN app.cable c ON c.cable_id = cs.cable_id
  JOIN app.fosc f1 ON f1.fosc_id = cs.from_fosc_id
  JOIN app.fosc f2 ON f2.fosc_id = cs.to_fosc_id
  WHERE cs.cable_span_id = p_span_id;
$$;


--
-- Name: int_range_bounds_display(int4range); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.int_range_bounds_display(r int4range) RETURNS TABLE(lo integer, hi integer)
    LANGUAGE sql IMMUTABLE
    AS $$
  SELECT lower(r) AS lo,
         upper(r) AS hi;
$$;


--
-- Name: join_conduits(integer, integer); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.join_conduits(p_a integer, p_b integer) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    a app.conduit%ROWTYPE;
    b app.conduit%ROWTYPE;
    d_ss double precision; d_se double precision; d_es double precision; d_ee double precision;
    best double precision;
    ga geometry; gb geometry; g geometry;
BEGIN
    IF p_a = p_b THEN RAISE EXCEPTION 'Pick two different conduits'; END IF;
    SELECT * INTO a FROM app.conduit WHERE conduit_id = p_a FOR UPDATE;
    SELECT * INTO b FROM app.conduit WHERE conduit_id = p_b FOR UPDATE;
    IF a.conduit_id IS NULL OR b.conduit_id IS NULL OR a.geom IS NULL OR b.geom IS NULL THEN
        RAISE EXCEPTION 'Conduit not found or has no line';
    END IF;
    IF a.owner IS DISTINCT FROM b.owner OR a.status IS DISTINCT FROM b.status
       OR a.microduct_type_id IS DISTINCT FROM b.microduct_type_id THEN
        RAISE EXCEPTION 'Conduits % and % differ in owner, status or microduct type; make them match first', p_a, p_b;
    END IF;
    d_ss := ST_Distance(ST_StartPoint(a.geom)::geography, ST_StartPoint(b.geom)::geography);
    d_se := ST_Distance(ST_StartPoint(a.geom)::geography, ST_EndPoint(b.geom)::geography);
    d_es := ST_Distance(ST_EndPoint(a.geom)::geography,   ST_StartPoint(b.geom)::geography);
    d_ee := ST_Distance(ST_EndPoint(a.geom)::geography,   ST_EndPoint(b.geom)::geography);
    best := LEAST(d_ss, d_se, d_es, d_ee);
    IF best > 5 THEN RAISE EXCEPTION 'Conduits % and % do not meet end to end (closest ends are % m apart)', p_a, p_b, round(best::numeric, 1); END IF;
    ga := a.geom; gb := b.geom;
    IF best = d_es THEN NULL;                              -- a end meets b start: as drawn
    ELSIF best = d_ee THEN gb := ST_Reverse(gb);
    ELSIF best = d_ss THEN ga := ST_Reverse(ga);
    ELSE ga := ST_Reverse(ga); gb := ST_Reverse(gb);        -- a start meets b end
    END IF;
    g := ST_RemoveRepeatedPoints(ST_MakeLine(ga, gb));

    PERFORM set_config('app.skip_carry', 'on', true);
    INSERT INTO app.conduit_work_order (conduit_id, work_order, is_primary)
    SELECT p_a, work_order, false FROM app.conduit_work_order WHERE conduit_id = p_b
    ON CONFLICT (conduit_id, work_order) DO NOTHING;
    DELETE FROM app.conduit WHERE conduit_id = p_b;
    UPDATE app.conduit SET geom = g,
           known_length_m = CASE WHEN a.known_length_m IS NOT NULL AND b.known_length_m IS NOT NULL THEN a.known_length_m + b.known_length_m END,
           known_length_tol_m = CASE WHEN a.known_length_m IS NOT NULL AND b.known_length_m IS NOT NULL THEN coalesce(a.known_length_tol_m, 0) + coalesce(b.known_length_tol_m, 0) END
     WHERE conduit_id = p_a;
    PERFORM set_config('app.skip_carry', '', true);
    RETURN p_a;
END $$;


--
-- Name: no_splices_in_terminal(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.no_splices_in_terminal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE k app.fosc_kind;
BEGIN
  SELECT kind INTO k FROM app.fosc WHERE fosc_id = NEW.fosc_id;
  IF k = 'terminal' THEN
    RAISE EXCEPTION 'Cannot record splices inside a terminal FOSC (id=%)', NEW.fosc_id;
  END IF;
  RETURN NEW;
END;
$$;


--
-- Name: own_name(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.own_name() RETURNS text
    LANGUAGE sql STABLE
    AS $$ SELECT COALESCE((SELECT value FROM app.org_setting WHERE key = 'own_name'), 'Own network') $$;


--
-- Name: pole_defaults(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.pole_defaults() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF NEW.owner IS NULL OR NEW.owner = '' THEN NEW.owner := 'Unknown'; END IF;
  IF NEW.source IS NULL OR NEW.source = '' THEN NEW.source := 'user'; END IF;
  RETURN NEW;
END $$;


--
-- Name: pole_work_order_mirror(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.pole_work_order_mirror() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF COALESCE(NEW.fpm_wor, '') = '' THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM app.fpm_permit WHERE permit_num = NEW.fpm_wor) THEN
        RETURN NULL;
    END IF;
    UPDATE app.pole_work_order
       SET is_primary = false
     WHERE pole_fid = NEW.fid AND is_primary AND work_order <> NEW.fpm_wor;
    INSERT INTO app.pole_work_order (pole_fid, work_order, is_primary)
    VALUES (NEW.fid, NEW.fpm_wor, true)
    ON CONFLICT (pole_fid, work_order) DO UPDATE SET is_primary = true;
    RETURN NULL;
END;
$$;


--
-- Name: range_series(int4range); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.range_series(r int4range) RETURNS SETOF integer
    LANGUAGE sql IMMUTABLE
    AS $$
  SELECT generate_series(
           lower(r),
           CASE WHEN upper_inc(r) THEN upper(r) ELSE upper(r)-1 END
         )
$$;


--
-- Name: rebuild_span_support(text, bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.rebuild_span_support(p_kind text, p_id bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF p_kind = 'span' THEN
        DELETE FROM app.cable_span_strand  WHERE cable_span_id = p_id;
        DELETE FROM app.cable_span_conduit WHERE cable_span_id = p_id;
        INSERT INTO app.cable_span_strand (cable_span_id, strand_id, along_m)
        SELECT s.cable_span_id, ms.id,
               round(ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(ms.geom, 2953)))::numeric, 1)
          FROM app.cable_span s
          JOIN app.messenger_strand ms ON ms.geom IS NOT NULL AND ST_DWithin(s.geom_snap, ms.geom, 0.0001)
         WHERE s.cable_span_id = p_id AND s.geom_snap IS NOT NULL
           AND ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(ms.geom, 2953)))
               >= 0.5 * ST_Length(ST_Transform(ms.geom, 2953))
           AND ST_Length(ST_Transform(ms.geom, 2953)) > 0;
        INSERT INTO app.cable_span_conduit (cable_span_id, conduit_id, along_m)
        SELECT s.cable_span_id, k.conduit_id,
               round(ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(k.geom, 2953)))::numeric, 1)
          FROM app.cable_span s
          JOIN app.conduit k ON k.geom IS NOT NULL AND ST_DWithin(s.geom_snap, k.geom, 0.0001)
         WHERE s.cable_span_id = p_id AND s.geom_snap IS NOT NULL
           AND ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(k.geom, 2953)))
               >= 0.5 * ST_Length(ST_Transform(k.geom, 2953))
           AND ST_Length(ST_Transform(k.geom, 2953)) > 0;
    ELSIF p_kind = 'strand' THEN
        DELETE FROM app.cable_span_strand WHERE strand_id = p_id;
        INSERT INTO app.cable_span_strand (cable_span_id, strand_id, along_m)
        SELECT s.cable_span_id, ms.id,
               round(ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(ms.geom, 2953)))::numeric, 1)
          FROM app.messenger_strand ms
          JOIN app.cable_span s ON s.geom_snap IS NOT NULL AND ST_DWithin(s.geom_snap, ms.geom, 0.0001)
         WHERE ms.id = p_id AND ms.geom IS NOT NULL AND ST_Length(ST_Transform(ms.geom, 2953)) > 0
           AND ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(ms.geom, 2953)))
               >= 0.5 * ST_Length(ST_Transform(ms.geom, 2953));
    ELSIF p_kind = 'conduit' THEN
        DELETE FROM app.cable_span_conduit WHERE conduit_id = p_id;
        INSERT INTO app.cable_span_conduit (cable_span_id, conduit_id, along_m)
        SELECT s.cable_span_id, k.conduit_id,
               round(ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(k.geom, 2953)))::numeric, 1)
          FROM app.conduit k
          JOIN app.cable_span s ON s.geom_snap IS NOT NULL AND ST_DWithin(s.geom_snap, k.geom, 0.0001)
         WHERE k.conduit_id = p_id AND k.geom IS NOT NULL AND ST_Length(ST_Transform(k.geom, 2953)) > 0
           AND ST_Length(ST_Intersection(ST_Buffer(ST_Transform(s.geom_snap, 2953), 5), ST_Transform(k.geom, 2953)))
               >= 0.5 * ST_Length(ST_Transform(k.geom, 2953));
    END IF;
END $$;


--
-- Name: refresh_alden_density(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.refresh_alden_density() RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    z   int;
    sz  double precision;
    ext geometry;
BEGIN
    -- Un-imported Alden staging points (poles + vaults), projected to Web Mercator.
    DROP TABLE IF EXISTS _aldh_pts;
    CREATE TEMP TABLE _aldh_pts ON COMMIT DROP AS
    SELECT ST_Transform(g, 3857) AS g FROM (
        SELECT ap.geom AS g
          FROM app.alden_pole ap
          LEFT JOIN app.pole p ON p.alden_pole_id = ap.pole_id
         WHERE ap.geom IS NOT NULL AND NOT ST_IsEmpty(ap.geom) AND p.fid IS NULL
        UNION ALL
        SELECT av.geom AS g
          FROM app.alden_vault av
          LEFT JOIN app.vault v ON v.alden_vault_id = av.vault_id_alden
         WHERE av.geom IS NOT NULL AND NOT ST_IsEmpty(av.geom) AND v.vault_id IS NULL
    ) s;
    CREATE INDEX ON _aldh_pts USING gist (g);

    SELECT ST_SetSRID(ST_Extent(g)::geometry, 3857) INTO ext FROM _aldh_pts;

    TRUNCATE app.alden_density_hex;

    IF ext IS NOT NULL THEN
        -- Same integer zoom bands (8..14) and hex sizing as refresh_asset_density().
        FOR z IN 8..14 LOOP
            sz := 9392580.0 / (2 ^ z);
            INSERT INTO app.alden_density_hex (zoom, geom, cnt)
            SELECT z, ST_Transform(h.geom, 4326), count(*)
              FROM ST_HexagonGrid(sz, ext) AS h
              JOIN _aldh_pts p ON p.g && h.geom AND ST_Intersects(p.g, h.geom)
             GROUP BY h.geom;
        END LOOP;
    END IF;

    INSERT INTO app.alden_density_meta (singleton, refreshed_at) VALUES (true, now())
        ON CONFLICT (singleton) DO UPDATE SET refreshed_at = EXCLUDED.refreshed_at;
END;
$$;


--
-- Name: refresh_asset_density(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.refresh_asset_density() RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    z   int;
    sz  double precision;
    ext geometry;
BEGIN
    -- Combined point set of every DB-resident point asset, projected to Web Mercator.
    DROP TABLE IF EXISTS _adh_pts;
    CREATE TEMP TABLE _adh_pts ON COMMIT DROP AS
    SELECT ST_Transform(g, 3857) AS g FROM (
        SELECT geom     AS g FROM app.pole  WHERE geom     IS NOT NULL AND NOT ST_IsEmpty(geom)
        UNION ALL
        SELECT location AS g FROM app.fosc  WHERE location IS NOT NULL AND NOT ST_IsEmpty(location)
        UNION ALL
        SELECT location AS g FROM app.pop   WHERE location IS NOT NULL AND NOT ST_IsEmpty(location)
        UNION ALL
        SELECT geom     AS g FROM app.vault WHERE geom     IS NOT NULL AND NOT ST_IsEmpty(geom)
        UNION ALL
        SELECT p.geom   AS g FROM app.anchor a JOIN app.pole p ON p.fid = a.pole_fid
            WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
        UNION ALL
        SELECT p.geom   AS g FROM app.uguard u JOIN app.pole p ON p.fid = u.pole_fid
            WHERE p.geom IS NOT NULL AND NOT ST_IsEmpty(p.geom)
    ) s;
    CREATE INDEX ON _adh_pts USING gist (g);

    SELECT ST_SetSRID(ST_Extent(g)::geometry, 3857) INTO ext FROM _adh_pts;

    TRUNCATE app.asset_density_hex;

    IF ext IS NOT NULL THEN
        -- Integer zoom bands 8..14. Hex size ~60 CSS px at that zoom in Web-Mercator units.
        FOR z IN 8..14 LOOP
            sz := 9392580.0 / (2 ^ z);
            INSERT INTO app.asset_density_hex (zoom, geom, cnt)
            SELECT z, ST_Transform(h.geom, 4326), count(*)
              FROM ST_HexagonGrid(sz, ext) AS h
              JOIN _adh_pts p ON p.g && h.geom AND ST_Intersects(p.g, h.geom)
             GROUP BY h.geom;
        END LOOP;
    END IF;

    INSERT INTO app.asset_density_meta (singleton, refreshed_at) VALUES (true, now())
        ON CONFLICT (singleton) DO UPDATE SET refreshed_at = EXCLUDED.refreshed_at;
END;
$$;


--
-- Name: refresh_live_optitaps(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.refresh_live_optitaps() RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    r   record;
    ids bigint[] := '{}';
BEGIN
    FOR r IN
        SELECT
            cs.cable_span_id,
            f.fosc_id,
            COALESCE(
                -- Intermediate tap: has outgoing pass-through splice.
                (SELECT lower(sj.a_range) - 1
                   FROM app.splice_join sj
                  WHERE sj.fosc_id      = f.fosc_id
                    AND sj.a_target     = 'cable_span'
                    AND sj.a_span_id    = cs.cable_span_id
                    AND sj.join_kind    = 'pass_through'
                    AND sj.retired_at  IS NULL
                  LIMIT 1),
                -- Last tap: incoming pass-through at the previous tap has
                -- b_span_id = this span, b_range = '[tap, N+1)'.
                (SELECT upper(sj2.b_range) - 1
                   FROM app.splice_join sj2
                  WHERE sj2.b_target    = 'cable_span'
                    AND sj2.b_span_id   = cs.cable_span_id
                    AND sj2.join_kind   = 'pass_through'
                    AND sj2.retired_at IS NULL
                  LIMIT 1),
                -- Single-tap FlexTIN (no pass-throughs at all): fiber 1.
                1
            ) AS sample_fiber
        FROM app.fosc f
        JOIN app.cable_span cs ON cs.to_fosc_id = f.fosc_id
        WHERE f.kind = 'optitap'
    LOOP
        -- Trace from the 'from' end of the optitap's span, i.e. upstream toward
        -- the OLT. Reaching any source device means this tap has light.
        IF EXISTS (
            SELECT 1
              FROM app.trace_light_path(r.cable_span_id, r.sample_fiber,
                                        'from'::app.span_end, false, 50) t
             WHERE t.device_type IN ('olt_pon', 'sfp', 'dwdm')
        ) THEN
            IF NOT (r.fosc_id = ANY(ids)) THEN
                ids := ids || r.fosc_id;
            END IF;
        END IF;
    END LOOP;

    DELETE FROM app.live_optitap;
    INSERT INTO app.live_optitap (fosc_id)
    SELECT DISTINCT x FROM unnest(ids) x;

    INSERT INTO app.live_optitap_meta (singleton, refreshed_at)
         VALUES (true, now())
    ON CONFLICT (singleton) DO UPDATE SET refreshed_at = EXCLUDED.refreshed_at;
END $$;


--
-- Name: road_service_ftth_supersedes_expansion(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.road_service_ftth_supersedes_expansion() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.provider = app.own_name() AND NEW.technology = 'fiber_expansion'
       AND EXISTS (SELECT 1 FROM app.road_coverage_service
                   WHERE seg_id = NEW.seg_id AND provider = app.own_name() AND technology = 'fiber_ftth') THEN
        RETURN NULL;                                  -- already built: nothing to expand
    END IF;
    IF NEW.provider = app.own_name() AND NEW.technology = 'fiber_ftth' THEN
        DELETE FROM app.road_coverage_service
        WHERE seg_id = NEW.seg_id AND provider = app.own_name() AND technology = 'fiber_expansion';
    END IF;
    RETURN NEW;
END $$;


--
-- Name: set_updated_at(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;


--
-- Name: sheath_length_biu(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.sheath_length_biu() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  tol_m   double precision := 2.0;          -- search/snap radius in meters
  srid_m  integer := 2953;                  -- NB Stereographic (meters). Change if your project uses another.
  s_rec   app.cable_span%ROWTYPE;
  gspan   geometry;                          -- original (4326)
  gspan_ls geometry;                         -- line-merged (4326)
  gspan_m geometry;                          -- projected (meters)
  p_m     geometry;                          -- point projected (meters)
  gsnap_m geometry;                          -- snapped point in meters CRS
BEGIN
  -- Input guards
  IF NEW.geom IS NULL OR ST_IsEmpty(NEW.geom) OR GeometryType(NEW.geom) <> 'POINT' THEN
    RAISE EXCEPTION 'geom must be a non-empty POINT';
  END IF;
  IF ST_SRID(NEW.geom) <> 4326 THEN
    RAISE EXCEPTION 'geom SRID must be 4326 (got %)', ST_SRID(NEW.geom);
  END IF;

  -- Choose span (respect user choice; else nearest by KNN)
  IF NEW.cable_span_id IS NOT NULL THEN
    SELECT * INTO s_rec FROM app.cable_span WHERE cable_span_id = NEW.cable_span_id;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'cable_span_id % not found', NEW.cable_span_id;
    END IF;
  ELSE
    SELECT s.*
      INTO s_rec
      FROM app.cable_span s
      ORDER BY s.geom <-> NEW.geom
      LIMIT 1;

    -- nothing nearby or table empty: keep as-is
    IF NOT FOUND THEN
      RETURN NEW;
    END IF;
  END IF;

  gspan := s_rec.geom;

  -- If too far from the chosen span, leave as-is (and leave cable_span_id NULL unless user set it)
  -- Distance test in meters using projected CRS
  IF ST_Distance(
       ST_Transform(gspan, srid_m),
       ST_Transform(NEW.geom, srid_m)
     ) > tol_m THEN
    IF NEW.cable_span_id IS NULL THEN
      -- no chosen span and too far: keep unassigned so QGIS can color it red
      RETURN NEW;
    ELSE
      -- user-specified span but too far: keep point, keep the span (your policy)
      RETURN NEW;
    END IF;
  END IF;

  -- Merge to a single LineString (handles MultiLineString)
  gspan_ls := ST_LineMerge(gspan);

  -- Project to meters and snap ON the line (not to a vertex)
  gspan_m := ST_Transform(gspan_ls, srid_m);
  p_m     := ST_Transform(NEW.geom,  srid_m);
  gsnap_m := ST_ClosestPoint(gspan_m, p_m);

  -- Assign span if it was auto-picked
  IF NEW.cable_span_id IS NULL THEN
    NEW.cable_span_id := s_rec.cable_span_id;
  END IF;

  -- Write snapped point back in 4326
  NEW.geom := ST_Transform(gsnap_m, 4326);

  -- Do NOT change NEW.measure_m (user-entered!)
  RETURN NEW;
END $$;


--
-- Name: sheath_length_biu_snap(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.sheath_length_biu_snap() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  tol_m   double precision := 2.0;
  gspan   geometry;
  span_id bigint;
  len_m   double precision;
  frac    double precision;
BEGIN
  IF NEW.geom IS NULL OR ST_IsEmpty(NEW.geom) OR GeometryType(NEW.geom) <> 'POINT' THEN
    RAISE EXCEPTION 'geom must be a non-empty Point(4326)';
  END IF;
  IF ST_SRID(NEW.geom) <> 4326 THEN
    RAISE EXCEPTION 'geom SRID must be 4326 (got %)', ST_SRID(NEW.geom);
  END IF;
  IF NEW.measure_m IS NULL THEN
    RAISE EXCEPTION 'measure_m is required';
  END IF;

  -- CASE 1: user specified a span -> snap to that span (or error if missing)
  IF NEW.cable_span_id IS NOT NULL THEN
    SELECT geom INTO gspan FROM app.cable_span WHERE cable_span_id = NEW.cable_span_id;
    IF gspan IS NULL THEN
      RAISE EXCEPTION 'Specified cable_span_id % not found', NEW.cable_span_id;
    END IF;

    len_m := ST_Length(gspan::geography);
    IF len_m > 0 THEN
      frac := app._clamp01(NEW.measure_m::double precision / len_m);
      NEW.geom := ST_LineInterpolatePoint(gspan, frac);
    ELSE
      NEW.geom := ST_StartPoint(gspan); -- degenerate span
    END IF;

  ELSE
  -- CASE 2: no span given -> find nearest within tolerance
    SELECT s.cable_span_id, s.geom
      INTO span_id, gspan
      FROM app.cable_span s
      WHERE ST_DWithin(s.geom::geography, NEW.geom::geography, tol_m)
      ORDER BY ST_Distance(s.geom::geography, NEW.geom::geography)
      LIMIT 1;

    IF span_id IS NOT NULL THEN
      NEW.cable_span_id := span_id;
      len_m := ST_Length(gspan::geography);
      IF len_m > 0 THEN
        frac := app._clamp01(NEW.measure_m::double precision / len_m);
        NEW.geom := ST_LineInterpolatePoint(gspan, frac);
      ELSE
        NEW.geom := ST_StartPoint(gspan);
      END IF;
    ELSE
      -- leave cable_span_id NULL and preserve the clicked point
      -- (QGIS can color NULLs red)
    END IF;
  END IF;

  NEW.updated_at := now();
  RETURN NEW;
END;
$$;


--
-- Name: span_endpoint(bigint, text); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.span_endpoint(p_span_id bigint, p_which text) RETURNS topology.geometry
    LANGUAGE sql STABLE
    AS $$
  SELECT CASE
           WHEN lower(p_which) = 'from' THEN f.location
           WHEN lower(p_which) = 'to'   THEN t.location
           ELSE NULL
         END
  FROM app.cable_span s
  JOIN app.fosc f ON f.fosc_id = s.from_fosc_id
  JOIN app.fosc t ON t.fosc_id = s.to_fosc_id
  WHERE s.cable_span_id = p_span_id
$$;


--
-- Name: span_geom(bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.span_geom(p_span_id bigint) RETURNS topology.geometry
    LANGUAGE sql STABLE
    AS $$
  SELECT s.geom  -- adjust if your snapped column is named differently
  FROM app.cable_span s
  WHERE s.cable_span_id = p_span_id
$$;


--
-- Name: splice_join_no_double_use(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.splice_join_no_double_use() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_conflict text;
BEGIN
    IF NEW.retired_at IS NOT NULL THEN
        RETURN NEW;
    END IF;

    WITH new_sides AS (
        SELECT 'a' AS which, NEW.a_target::text AS target, NEW.a_span_id AS obj_id,
               NEW.a_end::text AS side,
               generate_series(lower(NEW.a_range), upper(NEW.a_range) - 1) AS port
        UNION ALL
        SELECT 'b', NEW.b_target::text, NEW.b_span_id, NEW.b_end::text,
               generate_series(lower(NEW.b_range), upper(NEW.b_range) - 1)
    ),
    -- A patch-panel port is a two-sided bulkhead, so it is never double-booked.
    eligible AS (
        SELECT ns.*
          FROM new_sides ns
          LEFT JOIN app.fosc_device d
                 ON ns.target = 'fosc_device' AND d.fosc_device_id = ns.obj_id
         WHERE COALESCE(d.device_type, '') <> 'patch_panel'
    ),
    -- (1) this row's own two sides landing on the same position
    self_hit AS (
        SELECT format('its own two sides both use %s %s %s port/fibre %s',
                      a.target, a.obj_id, a.side, a.port) AS msg
          FROM eligible a
          JOIN eligible b
            ON b.which = 'b' AND a.which = 'a'
           AND a.target = b.target AND a.obj_id = b.obj_id
           AND a.side = b.side AND a.port = b.port
         LIMIT 1
    ),
    -- (2) a position already taken by another live join of the same kind
    other_sides AS (
        SELECT j.splice_join_id, j.a_target::text AS target, j.a_span_id AS obj_id,
               j.a_end::text AS side,
               generate_series(lower(j.a_range), upper(j.a_range) - 1) AS port
          FROM app.splice_join j
         WHERE j.retired_at IS NULL
           AND j.is_plan = NEW.is_plan
           AND j.splice_join_id IS DISTINCT FROM NEW.splice_join_id
        UNION ALL
        SELECT j.splice_join_id, j.b_target::text, j.b_span_id, j.b_end::text,
               generate_series(lower(j.b_range), upper(j.b_range) - 1)
          FROM app.splice_join j
         WHERE j.retired_at IS NULL
           AND j.is_plan = NEW.is_plan
           AND j.splice_join_id IS DISTINCT FROM NEW.splice_join_id
    ),
    other_hit AS (
        SELECT format('%s %s %s port/fibre %s is already used by splice #%s',
                      e.target, e.obj_id, e.side, e.port, o.splice_join_id) AS msg
          FROM eligible e
          JOIN other_sides o
            ON o.target = e.target AND o.obj_id = e.obj_id
           AND o.side = e.side AND o.port = e.port
         ORDER BY o.splice_join_id
         LIMIT 1
    )
    SELECT msg INTO v_conflict
      FROM (SELECT msg FROM self_hit UNION ALL SELECT msg FROM other_hit) q
     LIMIT 1;

    IF v_conflict IS NOT NULL THEN
        -- P0001 (raise_exception) marks this as an application rule with a message
        -- written for the operator; api/_common.php surfaces those verbatim.
        RAISE EXCEPTION 'Splice conflict: %', v_conflict
            USING ERRCODE = 'P0001',
                  HINT    = 'Retire the existing splice first, or pick a free fibre/port.';
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: split_conduit(integer, topology.geometry); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.split_conduit(p_id integer, p_pt topology.geometry) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    c   app.conduit%ROWTYPE;
    f   double precision;
    g1  geometry;
    g2  geometry;
    nid integer;
BEGIN
    SELECT * INTO c FROM app.conduit WHERE conduit_id = p_id FOR UPDATE;
    IF NOT FOUND OR c.geom IS NULL THEN RETURN NULL; END IF;
    f := ST_LineLocatePoint(c.geom, p_pt);
    IF f <= 0.0005 OR f >= 0.9995 THEN RETURN NULL; END IF;
    g1 := ST_LineSubstring(c.geom, 0, f);
    g2 := ST_LineSubstring(c.geom, f, 1);
    g1 := ST_SetPoint(g1, ST_NPoints(g1) - 1, p_pt);
    g2 := ST_SetPoint(g2, 0, p_pt);

    PERFORM set_config('app.skip_carry', 'on', true);
    UPDATE app.conduit SET geom = g1, known_length_m = known_length_m * f WHERE conduit_id = p_id;
    INSERT INTO app.conduit (name, status, notes, geom, microduct_type_id, fpm_wor, bell_permit_num,
                             owner, is_spare, known_length_m, known_length_tol_m)
    VALUES (c.name, c.status, c.notes, g2, c.microduct_type_id, c.fpm_wor, c.bell_permit_num,
            c.owner, c.is_spare, c.known_length_m * (1 - f), c.known_length_tol_m)
    RETURNING conduit_id INTO nid;
    INSERT INTO app.conduit_work_order (conduit_id, work_order, is_primary)
    SELECT nid, work_order, is_primary FROM app.conduit_work_order WHERE conduit_id = p_id
    ON CONFLICT (conduit_id, work_order) DO NOTHING;
    PERFORM set_config('app.skip_carry', '', true);
    RETURN nid;
END $$;


--
-- Name: stamp_change(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.stamp_change() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE c text := app.current_change();
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.created_change := c;
        NEW.updated_change := c;
    ELSE
        NEW.created_change := OLD.created_change;
        NEW.updated_change := c;
    END IF;
    RETURN NEW;
END $$;


--
-- Name: strand_endpoint_key(bigint, bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.strand_endpoint_key(pole_fid bigint, anchor_id bigint) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$ SELECT COALESCE('p' || pole_fid, 'a' || anchor_id) $$;


--
-- Name: strand_geom(bigint, bigint, bigint, bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.strand_geom(p_from_pole bigint, p_from_anchor bigint, p_to_pole bigint, p_to_anchor bigint) RETURNS topology.geometry
    LANGUAGE sql STABLE
    AS $$
    SELECT ST_MakeLine(
             COALESCE((SELECT p.geom FROM app.pole            p WHERE p.fid = p_from_pole),
                      (SELECT b.geom FROM app.building_anchor b WHERE b.id  = p_from_anchor)),
             COALESCE((SELECT p.geom FROM app.pole            p WHERE p.fid = p_to_pole),
                      (SELECT b.geom FROM app.building_anchor b WHERE b.id  = p_to_anchor))
           )::geometry(LineString,4326)
$$;


--
-- Name: t_fiber_range_touch(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.t_fiber_range_touch() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END$$;


--
-- Name: t_fiber_range_validate(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.t_fiber_range_validate() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_len_m        numeric;
  v_fiber_count  integer;
BEGIN
  -- fetch span length and cable fiber_count
  SELECT s.length_m, c.fiber_count
    INTO v_len_m, v_fiber_count
  FROM app.cable_span s
  JOIN app.cable c ON c.cable_id = s.cable_id
  WHERE s.cable_span_id = NEW.cable_span_id;

  IF v_len_m IS NULL THEN
    RAISE EXCEPTION 'Unknown cable_span_id %', NEW.cable_span_id;
  END IF;

  IF v_fiber_count IS NULL THEN
    RAISE EXCEPTION 'Cable behind span % has no fiber_count', NEW.cable_span_id;
  END IF;

  -- bounds against span length
  IF NEW.start_m > v_len_m OR NEW.end_m > v_len_m THEN
    RAISE EXCEPTION 'Range [%, %] m exceeds span length % m', NEW.start_m, NEW.end_m, v_len_m;
  END IF;

  -- bounds against fiber count
  IF NEW.fiber_hi > v_fiber_count THEN
    RAISE EXCEPTION 'Fiber range %..% exceeds cable fiber_count %', NEW.fiber_lo, NEW.fiber_hi, v_fiber_count;
  END IF;

  RETURN NEW;
END$$;


--
-- Name: t_splice_join_touch(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.t_splice_join_touch() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END $$;


--
-- Name: t_splice_join_validate(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.t_splice_join_validate() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  a_lo int; a_hi int; b_lo int; b_hi int;
  a_span bigint; a_end app.span_end;
  b_span bigint; b_end app.span_end;
  -- Optional: ensure both endpoints resolve to real FOSCs (sanity)
  a_fosc bigint;
  b_fosc bigint;
BEGIN
  -- Normalize local vars
  a_lo := COALESCE(NEW.a_fiber_lo, 0);
  a_hi := COALESCE(NEW.a_fiber_hi, 0);
  b_lo := COALESCE(NEW.b_fiber_lo, 0);
  b_hi := COALESCE(NEW.b_fiber_hi, 0);

  a_span := NEW.a_span_id;
  b_span := NEW.b_span_id;
  a_end  := NEW.a_end;
  b_end  := NEW.b_end;

  -- Basic validation
  IF a_span IS NULL OR b_span IS NULL THEN
    RAISE EXCEPTION 'Both endpoints must specify cable_span_id (a_span_id/b_span_id).';
  END IF;

  IF a_end IS NULL OR b_end IS NULL THEN
    RAISE EXCEPTION 'Both endpoints must specify end (from|to).';
  END IF;

  IF a_lo <= 0 OR a_hi <= 0 OR a_lo > a_hi THEN
    RAISE EXCEPTION 'Invalid A fiber range (%–%).', a_lo, a_hi;
  END IF;
  IF b_lo <= 0 OR b_hi <= 0 OR b_lo > b_hi THEN
    RAISE EXCEPTION 'Invalid B fiber range (%–%).', b_lo, b_hi;
  END IF;

  -- Optional sanity: ensure endpoints resolve to existing FOSCs
  SELECT app._endpoint_fosc(a_span, a_end) INTO a_fosc;
  SELECT app._endpoint_fosc(b_span, b_end) INTO b_fosc;
  IF a_fosc IS NULL OR b_fosc IS NULL THEN
    RAISE EXCEPTION 'Endpoint FOSC not found (A=% B=%).', a_fosc, b_fosc;
  END IF;

  -- If you want splices to only occur at the same closure, enforce this:
  -- IF a_fosc <> b_fosc THEN
  --   RAISE EXCEPTION 'Splice must connect endpoints at the same closure (A FOSC %, B FOSC %).', a_fosc, b_fosc;
  -- END IF;

  -- NOTE: previously we tried to validate against c.fiber_count. Your app.cable
  --       table doesn’t have that column, so we skip “capacity” checks here.

  RETURN NEW;
END;
$$;


--
-- Name: tg_touch(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.tg_touch() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN NEW.updated_at := now(); RETURN NEW; END
    $$;


--
-- Name: touch_updated_at(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.touch_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END$$;


--
-- Name: trace_fiber_path(bigint, integer, app.span_end, boolean, integer); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trace_fiber_path(p_start_span_id bigint, p_start_fiber_num integer, p_start_end app.span_end, p_follow_plan boolean DEFAULT false, p_max_hops integer DEFAULT 500) RETURNS TABLE(hop_num integer, cable_span_id bigint, cable_name text, fiber_num integer, span_end app.span_end, fosc_id bigint, fosc_label text, splice_join_id bigint, next_span_id bigint, next_fiber_num integer, next_end app.span_end, geom topology.geometry, length_m double precision)
    LANGUAGE sql STABLE
    AS $$
WITH RECURSIVE fiber_trace AS (
  SELECT
    1 as hop,
    p_start_span_id as span_id,
    p_start_fiber_num as fiber,
    (CASE WHEN p_start_end = 'from' THEN 'to'::app.span_end
          ELSE 'from'::app.span_end END) as span_end,
    NULL::bigint as splice_id,
    NULL::bigint as next_span,
    NULL::int as next_fiber,
    NULL::app.span_end as next_end,
    ARRAY[p_start_span_id || '|' ||
      (CASE WHEN p_start_end = 'from' THEN 'to' ELSE 'from' END)] as path_key

  UNION ALL

  SELECT
    ft.hop + 1,
    CASE
      WHEN sj.a_span_id = ft.span_id AND sj.a_end::text = ft.span_end::text
        THEN sj.b_span_id
      ELSE sj.a_span_id
    END as next_span_id,
    CASE
      WHEN sj.a_span_id = ft.span_id AND sj.a_end::text = ft.span_end::text
        THEN lower(sj.b_range) + (ft.fiber - lower(sj.a_range))
      ELSE lower(sj.a_range) + (ft.fiber - lower(sj.b_range))
    END as next_fiber,
    CASE
      WHEN sj.a_span_id = ft.span_id AND sj.a_end::text = ft.span_end::text
        THEN (CASE WHEN sj.b_end = 'from' THEN 'to' ELSE 'from' END)::app.span_end
      ELSE
        (CASE WHEN sj.a_end = 'from' THEN 'to' ELSE 'from' END)::app.span_end
    END as next_end,
    sj.splice_join_id,
    NULL, NULL, NULL,
    ft.path_key || ARRAY[
      (CASE
        WHEN sj.a_span_id = ft.span_id AND sj.a_end::text = ft.span_end::text
          THEN sj.b_span_id::text || '|' || (CASE WHEN sj.b_end = 'from' THEN 'to' ELSE 'from' END)
        ELSE sj.a_span_id::text || '|' || (CASE WHEN sj.a_end = 'from' THEN 'to' ELSE 'from' END)
      END)
    ]
  FROM fiber_trace ft
  JOIN app.splice_join sj ON (
    sj.a_target = 'cable_span' AND sj.b_target = 'cable_span'
    AND sj.retired_at IS NULL
    AND (NOT sj.is_plan OR p_follow_plan)
    AND (
      (sj.a_span_id = ft.span_id AND sj.a_end::text = ft.span_end::text
       AND ft.fiber >= lower(sj.a_range) AND ft.fiber < upper(sj.a_range))
      OR
      (sj.b_span_id = ft.span_id AND sj.b_end::text = ft.span_end::text
       AND ft.fiber >= lower(sj.b_range) AND ft.fiber < upper(sj.b_range))
    )
  )
  WHERE ft.hop < p_max_hops
    AND NOT (
      (CASE
        WHEN sj.a_span_id = ft.span_id AND sj.a_end::text = ft.span_end::text
          THEN sj.b_span_id::text || '|' || (CASE WHEN sj.b_end = 'from' THEN 'to' ELSE 'from' END)
        ELSE sj.a_span_id::text || '|' || (CASE WHEN sj.a_end = 'from' THEN 'to' ELSE 'from' END)
      END) = ANY(ft.path_key)
    )
)
SELECT
  ft.hop::int,
  ft.span_id,
  c.name,
  ft.fiber,
  ft.span_end,
  CASE
    WHEN ft.span_end = 'from' THEN cs.from_fosc_id
    ELSE cs.to_fosc_id
  END,
  f.splice_num,
  ft.splice_id,
  ft.next_span,
  ft.next_fiber,
  ft.next_end,
  cs.geom,
  cs.length_m
FROM fiber_trace ft
JOIN app.cable_span cs ON cs.cable_span_id = ft.span_id
JOIN app.cable c ON c.cable_id = cs.cable_id
LEFT JOIN app.fosc f ON f.fosc_id = CASE
  WHEN ft.span_end = 'from' THEN cs.from_fosc_id
  ELSE cs.to_fosc_id
END
ORDER BY ft.hop;
$$;


--
-- Name: trace_light_path(bigint, integer, app.span_end, boolean, integer); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trace_light_path(p_start_span_id bigint, p_start_fiber_num integer, p_start_end app.span_end, p_follow_plan boolean DEFAULT false, p_max_hops integer DEFAULT 500) RETURNS TABLE(hop_num integer, branch_path text, cable_span_id bigint, cable_name text, fiber_num integer, span_end app.span_end, fosc_id bigint, fosc_label text, device_id bigint, device_type text, device_name text, device_port integer, splice_join_id bigint, cumulative_loss_db numeric, geom topology.geometry)
    LANGUAGE sql STABLE
    AS $$
WITH RECURSIVE

  lp AS (
    SELECT
      MAX(CASE WHEN param_key = 'splice_fusion'      THEN loss_db END)::numeric AS splice_db,
      MAX(CASE WHEN param_key = 'fibre_smf_db_per_m' THEN loss_db END)::numeric AS fibre_db_per_m,
      MAX(CASE WHEN param_key = 'connector_sc_upc'   THEN loss_db END)::numeric AS connector_db
    FROM app.loss_param
    WHERE param_key IN ('splice_fusion', 'fibre_smf_db_per_m', 'connector_sc_upc')
  ),

  light_trace AS (
    SELECT
      1::int                                                          AS hop,
      '1'::text                                                       AS branch_path,
      TRUE                                                            AS on_span,
      p_start_span_id                                                 AS span_id,
      p_start_fiber_num                                               AS fiber,
      p_start_end                                                     AS cur_end,
      NULL::bigint                                                    AS device_id,
      NULL::int                                                       AS device_port,
      NULL::bigint                                                    AS in_splice_id,
      NULL::bigint                                                    AS cur_splice_id,
      0.0::numeric                                                    AS loss_db,
      ARRAY[p_start_span_id::text || '|' || p_start_end::text]       AS path_key

    UNION ALL

    SELECT
      lt.hop + 1,
      next.branch_path,
      next.on_span,
      next.span_id,
      next.fiber,
      next.cur_end,
      next.device_id,
      next.device_port,
      next.in_splice_id,
      next.cur_splice_id,
      next.loss_db,
      next.path_key
    FROM light_trace lt
    CROSS JOIN LATERAL (

      /* ── Case A: cable span → cable span ──────────────────────────────── */
      SELECT
        lt.branch_path                                                         AS branch_path,
        TRUE                                                                   AS on_span,
        CASE WHEN sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
             THEN sja.b_span_id ELSE sja.a_span_id END                        AS span_id,
        CASE WHEN sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
             THEN lower(sja.b_range) + (lt.fiber - lower(sja.a_range))
             ELSE lower(sja.a_range) + (lt.fiber - lower(sja.b_range)) END    AS fiber,
        CASE WHEN sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
             THEN (CASE WHEN sja.b_end = 'from' THEN 'to' ELSE 'from' END)
             ELSE (CASE WHEN sja.a_end = 'from' THEN 'to' ELSE 'from' END)
        END::app.span_end                                                      AS cur_end,
        NULL::bigint                                                           AS device_id,
        NULL::int                                                              AS device_port,
        NULL::bigint                                                           AS in_splice_id,
        sja.splice_join_id                                                     AS cur_splice_id,
        lt.loss_db
          + (SELECT splice_db     FROM lp)
          + COALESCE(cs_next.length_m, 0.0)::numeric * (SELECT fibre_db_per_m FROM lp) AS loss_db,
        lt.path_key || ARRAY[(
          CASE WHEN sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
               THEN sja.b_span_id::text || '|'
                    || (CASE WHEN sja.b_end = 'from' THEN 'to' ELSE 'from' END)
               ELSE sja.a_span_id::text || '|'
                    || (CASE WHEN sja.a_end = 'from' THEN 'to' ELSE 'from' END)
          END
        )]                                                                     AS path_key
      FROM app.splice_join sja
      LEFT JOIN app.cable_span cs_next ON cs_next.cable_span_id =
        CASE WHEN sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
             THEN sja.b_span_id ELSE sja.a_span_id END
      WHERE lt.on_span = TRUE
        AND sja.a_target = 'cable_span' AND sja.b_target = 'cable_span'
        AND sja.retired_at IS NULL
        AND (NOT sja.is_plan OR p_follow_plan)
        AND (
          (sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
           AND lt.fiber >= lower(sja.a_range) AND lt.fiber < upper(sja.a_range))
          OR
          (sja.b_span_id = lt.span_id AND sja.b_end::text = lt.cur_end::text
           AND lt.fiber >= lower(sja.b_range) AND lt.fiber < upper(sja.b_range))
        )
        AND NOT ((
          CASE WHEN sja.a_span_id = lt.span_id AND sja.a_end::text = lt.cur_end::text
               THEN sja.b_span_id::text || '|'
                    || (CASE WHEN sja.b_end = 'from' THEN 'to' ELSE 'from' END)
               ELSE sja.a_span_id::text || '|'
                    || (CASE WHEN sja.a_end = 'from' THEN 'to' ELSE 'from' END)
          END
        ) = ANY(lt.path_key))

      UNION ALL

      /* ── Case B: cable span → device ──────────────────────────────────── */
      SELECT
        lt.branch_path                                                         AS branch_path,
        FALSE                                                                  AS on_span,
        lt.span_id                                                             AS span_id,
        lt.fiber                                                               AS fiber,
        lt.cur_end                                                             AS cur_end,
        CASE WHEN sjb.a_target = 'fosc_device'
             THEN sjb.a_span_id ELSE sjb.b_span_id END                        AS device_id,
        CASE WHEN sjb.a_target = 'fosc_device'
             THEN lower(sjb.a_range) + (lt.fiber - lower(sjb.b_range))
             ELSE lower(sjb.b_range) + (lt.fiber - lower(sjb.a_range)) END    AS device_port,
        sjb.splice_join_id                                                     AS in_splice_id,
        sjb.splice_join_id                                                     AS cur_splice_id,
        lt.loss_db + CASE d.device_type
          WHEN 'splitter'     THEN 10.0 * log(10.0, (d.attrs->>'outs')::numeric)
          WHEN 'oadm'         THEN 1.5
          WHEN 'patch_panel'  THEN 0.0
          -- MPT: split = internal 1×N splitter loss; passive = pass-through (0 dB)
          WHEN 'mpt'          THEN CASE WHEN d.attrs->>'mode' = 'split'
                                        THEN 10.0 * log(10.0, (d.attrs->>'ports')::numeric)
                                        ELSE 0.0 END
          ELSE                     1.0
        END                                                                    AS loss_db,
        lt.path_key                                                            AS path_key
      FROM app.splice_join sjb
      JOIN app.fosc_device d ON d.fosc_device_id =
        CASE WHEN sjb.a_target = 'fosc_device' THEN sjb.a_span_id
             ELSE sjb.b_span_id END
      WHERE lt.on_span = TRUE
        AND sjb.retired_at IS NULL
        AND (NOT sjb.is_plan OR p_follow_plan)
        AND (
          (sjb.a_target = 'cable_span'  AND sjb.a_span_id = lt.span_id
           AND sjb.a_end::text = lt.cur_end::text
           AND lt.fiber >= lower(sjb.a_range) AND lt.fiber < upper(sjb.a_range)
           AND sjb.b_target = 'fosc_device')
          OR
          (sjb.b_target = 'cable_span'  AND sjb.b_span_id = lt.span_id
           AND sjb.b_end::text = lt.cur_end::text
           AND lt.fiber >= lower(sjb.b_range) AND lt.fiber < upper(sjb.b_range)
           AND sjb.a_target = 'fosc_device')
        )

      UNION ALL

      /* ── Case C: device → cable span ──────────────────────────────────── */
      SELECT
        lt.branch_path || '.' || out_port::text                               AS branch_path,
        TRUE                                                                   AS on_span,
        CASE WHEN sjc.a_target = 'cable_span'
             THEN sjc.a_span_id ELSE sjc.b_span_id END                        AS span_id,
        CASE WHEN sjc.a_target = 'cable_span'
             THEN lower(sjc.a_range) + (out_port - lower(sjc.b_range))
             ELSE lower(sjc.b_range) + (out_port - lower(sjc.a_range)) END    AS fiber,
        CASE WHEN sjc.a_target = 'cable_span'
             THEN (CASE WHEN sjc.a_end = 'from' THEN 'to' ELSE 'from' END)
             ELSE (CASE WHEN sjc.b_end = 'from' THEN 'to' ELSE 'from' END)
        END::app.span_end                                                      AS cur_end,
        NULL::bigint                                                           AS device_id,
        NULL::int                                                              AS device_port,
        NULL::bigint                                                           AS in_splice_id,
        sjc.splice_join_id                                                     AS cur_splice_id,
        lt.loss_db                                                             AS loss_db,
        lt.path_key || ARRAY[(
          CASE WHEN sjc.a_target = 'cable_span'
               THEN sjc.a_span_id::text || '|'
                    || (CASE WHEN sjc.a_end = 'from' THEN 'to' ELSE 'from' END)
               ELSE sjc.b_span_id::text || '|'
                    || (CASE WHEN sjc.b_end = 'from' THEN 'to' ELSE 'from' END)
          END
        )]                                                                     AS path_key
      FROM app.splice_join sjc
      JOIN app.fosc_device dex ON dex.fosc_device_id = lt.device_id
      CROSS JOIN LATERAL generate_series(
        CASE WHEN sjc.a_target = 'fosc_device'
             THEN lower(sjc.a_range) ELSE lower(sjc.b_range) END,
        CASE WHEN sjc.a_target = 'fosc_device'
             THEN upper(sjc.a_range) ELSE upper(sjc.b_range) END - 1
      ) AS out_port
      WHERE lt.on_span = FALSE
        AND sjc.retired_at IS NULL
        AND (NOT sjc.is_plan OR p_follow_plan)
        AND sjc.splice_join_id IS DISTINCT FROM lt.in_splice_id
        AND (
          (sjc.a_target = 'fosc_device' AND sjc.a_span_id = lt.device_id
           AND sjc.b_target = 'cable_span')
          OR
          (sjc.b_target = 'fosc_device' AND sjc.b_span_id = lt.device_id
           AND sjc.a_target = 'cable_span')
        )
        AND (dex.device_type <> 'patch_panel' OR out_port = lt.device_port)
        -- MPT: passive is 1:1 (exit only at entered port); split fans out all outputs
        AND (dex.device_type <> 'mpt' OR dex.attrs->>'mode' = 'split' OR out_port = lt.device_port)
        AND NOT ((
          CASE WHEN sjc.a_target = 'cable_span'
               THEN sjc.a_span_id::text || '|'
                    || (CASE WHEN sjc.a_end = 'from' THEN 'to' ELSE 'from' END)
               ELSE sjc.b_span_id::text || '|'
                    || (CASE WHEN sjc.b_end = 'from' THEN 'to' ELSE 'from' END)
          END
        ) = ANY(lt.path_key))

      UNION ALL

      /* ── Case D: device → device (patch cable) ───────────────────────── */
      SELECT
        lt.branch_path                                                         AS branch_path,
        FALSE                                                                  AS on_span,
        lt.span_id                                                             AS span_id,
        lt.fiber                                                               AS fiber,
        lt.cur_end                                                             AS cur_end,
        CASE WHEN sjd.a_target = 'fosc_device' AND sjd.a_span_id = lt.device_id
             THEN sjd.b_span_id ELSE sjd.a_span_id END                        AS device_id,
        CASE WHEN sjd.a_target = 'fosc_device' AND sjd.a_span_id = lt.device_id
             THEN lower(sjd.b_range) + (lt.device_port - lower(sjd.a_range))
             ELSE lower(sjd.a_range) + (lt.device_port - lower(sjd.b_range)) END AS device_port,
        sjd.splice_join_id                                                     AS in_splice_id,
        sjd.splice_join_id                                                     AS cur_splice_id,
        lt.loss_db + COALESCE((SELECT connector_db FROM lp), 0.35)            AS loss_db,
        lt.path_key                                                            AS path_key
      FROM app.splice_join sjd
      WHERE lt.on_span = FALSE
        AND lt.device_port IS NOT NULL
        AND sjd.retired_at IS NULL
        AND (NOT sjd.is_plan OR p_follow_plan)
        AND sjd.splice_join_id IS DISTINCT FROM lt.in_splice_id
        AND sjd.a_target = 'fosc_device'
        AND sjd.b_target = 'fosc_device'
        AND (
          (sjd.a_span_id = lt.device_id
           AND lt.device_port >= lower(sjd.a_range)
           AND lt.device_port <  upper(sjd.a_range))
          OR
          (sjd.b_span_id = lt.device_id
           AND lt.device_port >= lower(sjd.b_range)
           AND lt.device_port <  upper(sjd.b_range))
        )

    ) next
    WHERE lt.hop < p_max_hops

  ) -- end of light_trace recursive CTE

SELECT
  lt.hop::int,
  lt.branch_path,
  lt.span_id,
  c.name,
  lt.fiber,
  lt.cur_end,
  CASE
    WHEN lt.cur_end = 'from' THEN cs.from_fosc_id
    WHEN lt.cur_end = 'to'   THEN cs.to_fosc_id
    ELSE NULL
  END,
  f.splice_num,
  lt.device_id,
  d.device_type,
  d.name,
  lt.device_port,
  lt.cur_splice_id,
  ROUND(lt.loss_db, 2),
  cs.geom
FROM light_trace lt
LEFT JOIN app.cable_span cs ON cs.cable_span_id = lt.span_id
LEFT JOIN app.cable c ON c.cable_id = cs.cable_id
LEFT JOIN app.fosc f ON f.fosc_id = CASE
  WHEN lt.cur_end = 'from' THEN cs.from_fosc_id
  WHEN lt.cur_end = 'to'   THEN cs.to_fosc_id
  ELSE NULL
END
LEFT JOIN app.fosc_device d ON d.fosc_device_id = lt.device_id
ORDER BY lt.branch_path, lt.hop;
$$;


--
-- Name: trg_attached_point_moved(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_attached_point_moved() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.geom IS DISTINCT FROM OLD.geom THEN
        IF TG_TABLE_NAME = 'vault' THEN
            UPDATE app.conduit SET geom = geom WHERE start_vault_id = NEW.vault_id OR end_vault_id = NEW.vault_id;
        ELSE
            UPDATE app.conduit SET geom = geom WHERE start_term_id = NEW.termination_id OR end_term_id = NEW.termination_id;
        END IF;
    END IF;
    RETURN NEW;
END $$;


--
-- Name: trg_banchor_refresh_strand_geom(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_banchor_refresh_strand_geom() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.geom IS NOT DISTINCT FROM OLD.geom THEN RETURN NULL; END IF;
    UPDATE app.messenger_strand ms
       SET geom = app.strand_geom(ms.from_pole_fid, ms.from_anchor_id,
                                  ms.to_pole_fid,   ms.to_anchor_id)
     WHERE ms.from_anchor_id = NEW.id OR ms.to_anchor_id = NEW.id;
    RETURN NULL;
END $$;


--
-- Name: trg_circuit_fibre_in_range(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_circuit_fibre_in_range() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE n integer;
BEGIN
    SELECT c.fibre_count INTO n FROM app.cable_span s JOIN app.cable c USING (cable_id) WHERE s.cable_span_id = NEW.cable_span_id;
    IF n IS NOT NULL AND NEW.fibre_num > n THEN
        RAISE EXCEPTION 'Fibre % is past the % fibres in cable span %', NEW.fibre_num, n, NEW.cable_span_id;
    END IF;
    RETURN NEW;
END $$;


--
-- Name: trg_closure_placement(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_closure_placement() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.kind = 'closure'
       AND (TG_OP = 'INSERT' OR NEW.location IS DISTINCT FROM OLD.location OR OLD.kind IS DISTINCT FROM NEW.kind)
       AND NOT app.closure_is_housed(NEW.location, NEW.attrs) THEN
        -- already off a pole or vault before this edit: a small adjustment is allowed
        IF TG_OP = 'UPDATE' AND OLD.kind = 'closure' AND NOT app.closure_is_housed(OLD.location, OLD.attrs)
           AND ST_DWithin(OLD.location::geography, NEW.location::geography, 25) THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Closure % must be on a pole or inside a vault or handhole. Place a handhole or vault at it first.', NEW.splice_num
            USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;


--
-- Name: trg_conduit_carry_cable(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_conduit_carry_cable() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    s       record;
    f1      double precision;
    f2      double precision;
    lo      double precision;
    hi      double precision;
    ng      geometry;
    pts     geometry[];
BEGIN
    IF current_setting('app.skip_carry', true) = 'on'
       OR OLD.geom IS NULL OR NEW.geom IS NULL OR NEW.geom IS NOT DISTINCT FROM OLD.geom THEN
        RETURN NEW;
    END IF;
    FOR s IN SELECT cable_span_id, geom_snap AS g FROM app.cable_span
              WHERE geom_snap IS NOT NULL
                AND ST_DWithin(geom_snap, ST_StartPoint(OLD.geom), 0.00002)
                AND ST_DWithin(geom_snap, ST_EndPoint(OLD.geom), 0.00002) LOOP
        f1 := ST_LineLocatePoint(s.g, ST_StartPoint(OLD.geom));
        f2 := ST_LineLocatePoint(s.g, ST_EndPoint(OLD.geom));
        lo := LEAST(f1, f2); hi := GREATEST(f1, f2);
        CONTINUE WHEN hi - lo < 0.000001;
        -- only a cable that really ran along this conduit end to end
        CONTINUE WHEN ST_HausdorffDistance(ST_LineSubstring(s.g, lo, hi), OLD.geom) > 0.00003;
        ng := CASE WHEN f1 <= f2 THEN NEW.geom ELSE ST_Reverse(NEW.geom) END;
        pts := ARRAY[]::geometry[];
        IF lo > 0 THEN      -- the points before the old junction, without the junction point itself
            pts := pts || ARRAY(SELECT (d).geom FROM ST_DumpPoints(ST_LineSubstring(s.g, 0, lo)) d
                                 WHERE (d).path[1] < ST_NPoints(ST_LineSubstring(s.g, 0, lo)) ORDER BY (d).path[1]);
        END IF;
        pts := pts || ARRAY(SELECT (d).geom FROM ST_DumpPoints(ng) d ORDER BY (d).path[1]);
        IF hi < 1 THEN      -- the points after the old far junction, without that point
            pts := pts || ARRAY(SELECT (d).geom FROM ST_DumpPoints(ST_LineSubstring(s.g, hi, 1)) d
                                 WHERE (d).path[1] > 1 ORDER BY (d).path[1]);
        END IF;
        UPDATE app.cable_span SET geom_snap = ST_RemoveRepeatedPoints(ST_MakeLine(pts))
         WHERE cable_span_id = s.cable_span_id;
    END LOOP;
    RETURN NEW;
END $$;


--
-- Name: trg_conduit_ends(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_conduit_ends() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    tol   double precision := coalesce(nullif(current_setting('app.conduit_attach_m', true), '')::double precision, 5);
    n     integer;
    pt    geometry;
    moved boolean;
    vid   integer;
    tid   integer;
    tg    geometry;
    i     integer;
    sv    integer; st integer;
    pf    bigint; ff bigint; pp bigint;
    pg    geometry;
    lbl   text;
BEGIN
    IF NEW.geom IS NULL THEN RETURN NEW; END IF;
    n := ST_NPoints(NEW.geom);
    FOR i IN 0..1 LOOP
        pt := CASE WHEN i = 0 THEN ST_StartPoint(NEW.geom) ELSE ST_EndPoint(NEW.geom) END;
        sv := CASE WHEN i = 0 THEN NEW.start_vault_id ELSE NEW.end_vault_id END;
        st := CASE WHEN i = 0 THEN NEW.start_term_id  ELSE NEW.end_term_id  END;
        moved := TG_OP = 'INSERT'
              OR OLD.geom IS NULL
              OR NOT ST_DWithin((CASE WHEN i = 0 THEN ST_StartPoint(OLD.geom) ELSE ST_EndPoint(OLD.geom) END)::geography, pt::geography, 0.001)
              OR (sv IS NULL AND st IS NULL);
        -- an end that has not moved follows what it is attached to (a vault or termination that moved)
        IF NOT moved THEN
            tg := NULL;
            IF sv IS NOT NULL THEN SELECT geom INTO tg FROM app.vault WHERE vault_id = sv;
            ELSIF st IS NOT NULL THEN SELECT geom INTO tg FROM app.conduit_termination WHERE termination_id = st; END IF;
            IF tg IS NOT NULL THEN
                NEW.geom := ST_SetPoint(NEW.geom, CASE WHEN i = 0 THEN 0 ELSE n - 1 END, tg);
            END IF;
            CONTINUE;
        END IF;
        -- the end is new or moved: a vault first
        vid := NULL; tid := NULL;
        SELECT v.vault_id, v.geom INTO vid, tg FROM app.vault v
         WHERE (v.owner IS NOT DISTINCT FROM NEW.owner AND ST_DWithin(v.geom::geography, pt::geography, tol))
            OR ST_DWithin(v.geom::geography, pt::geography, LEAST(1.0, tol))
         ORDER BY ST_Distance(v.geom::geography, pt::geography) LIMIT 1;
        -- then a pole, closure or POP it is at: the termination sits on the object itself
        IF vid IS NULL THEN
            pf := NULL; ff := NULL; pp := NULL;
            SELECT p.fid, p.geom, p."nbp#" INTO pf, pg, lbl FROM app.pole p
             WHERE ST_DWithin(p.geom::geography, pt::geography, LEAST(3.0, tol))
             ORDER BY ST_Distance(p.geom::geography, pt::geography) LIMIT 1;
            IF pf IS NOT NULL THEN
                SELECT t.termination_id, t.geom INTO tid, tg FROM app.conduit_termination t WHERE t.ref_pole_fid = pf AND t.kind = 'pole' LIMIT 1;
                IF tid IS NULL THEN
                    INSERT INTO app.conduit_termination (kind, label, geom, owner, fpm_wor, ref_pole_fid)
                    VALUES ('pole', lbl, pg, NEW.owner, NEW.fpm_wor, pf) RETURNING termination_id, geom INTO tid, tg;
                END IF;
            ELSE
                SELECT f.fosc_id, f.location, f.splice_num INTO ff, pg, lbl FROM app.fosc f
                 WHERE ST_DWithin(f.location::geography, pt::geography, LEAST(3.0, tol))
                 ORDER BY ST_Distance(f.location::geography, pt::geography) LIMIT 1;
                IF ff IS NOT NULL THEN
                    SELECT t.termination_id, t.geom INTO tid, tg FROM app.conduit_termination t WHERE t.ref_fosc_id = ff AND t.kind = 'closure' LIMIT 1;
                    IF tid IS NULL THEN
                        INSERT INTO app.conduit_termination (kind, label, geom, owner, fpm_wor, ref_fosc_id)
                        VALUES ('closure', lbl, pg, NEW.owner, NEW.fpm_wor, ff) RETURNING termination_id, geom INTO tid, tg;
                    END IF;
                ELSE
                    SELECT o.pop_id, o.location, o.name INTO pp, pg, lbl FROM app.pop o
                     WHERE o.location IS NOT NULL AND ST_DWithin(o.location::geography, pt::geography, LEAST(10.0, tol * 2))
                     ORDER BY ST_Distance(o.location::geography, pt::geography) LIMIT 1;
                    IF pp IS NOT NULL THEN
                        SELECT t.termination_id, t.geom INTO tid, tg FROM app.conduit_termination t WHERE t.ref_pop_id = pp AND t.kind = 'pop' LIMIT 1;
                        IF tid IS NULL THEN
                            INSERT INTO app.conduit_termination (kind, label, geom, owner, fpm_wor, ref_pop_id)
                            VALUES ('pop', lbl, pg, NEW.owner, NEW.fpm_wor, pp) RETURNING termination_id, geom INTO tid, tg;
                        END IF;
                    END IF;
                END IF;
            END IF;
        END IF;
        -- then any termination already there
        IF vid IS NULL AND tid IS NULL THEN
            SELECT t.termination_id, t.geom INTO tid, tg FROM app.conduit_termination t
             WHERE ST_DWithin(t.geom::geography, pt::geography, tol)
             ORDER BY ST_Distance(t.geom::geography, pt::geography) LIMIT 1;
        END IF;
        -- else a new unverified one right here
        IF vid IS NULL AND tid IS NULL THEN
            INSERT INTO app.conduit_termination (kind, geom, owner, fpm_wor)
            VALUES ('unverified', pt, NEW.owner, NEW.fpm_wor) RETURNING termination_id, geom INTO tid, tg;
        END IF;
        NEW.geom := ST_SetPoint(NEW.geom, CASE WHEN i = 0 THEN 0 ELSE n - 1 END, tg);
        IF i = 0 THEN NEW.start_vault_id := vid; NEW.start_term_id := tid;
        ELSE          NEW.end_vault_id   := vid; NEW.end_term_id   := tid; END IF;
    END LOOP;
    RETURN NEW;
END $$;


--
-- Name: trg_conduit_orphan_terminations(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_conduit_orphan_terminations() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    DELETE FROM app.conduit_termination t
     WHERE t.kind = 'unverified' AND coalesce(t.label, '') = ''
       AND NOT EXISTS (SELECT 1 FROM app.conduit c WHERE c.start_term_id = t.termination_id OR c.end_term_id = t.termination_id);
    RETURN NULL;
END $$;


--
-- Name: trg_conduit_support(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_conduit_support() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'INSERT' OR NEW.geom IS DISTINCT FROM OLD.geom THEN
        PERFORM app.rebuild_span_support('conduit', NEW.conduit_id);
    END IF;
    RETURN NULL;
END $$;


--
-- Name: trg_fosc_normalize(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_fosc_normalize() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.splice_num := UPPER(NEW.splice_num);
  NEW.updated_at := now();
  RETURN NEW;
END$$;


--
-- Name: trg_guard_device_in_splice(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_guard_device_in_splice() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE n int;
BEGIN
    SELECT count(*) INTO n FROM app.splice_join
     WHERE retired_at IS NULL
       AND ((a_target = 'fosc_device' AND a_span_id = OLD.fosc_device_id)
         OR (b_target = 'fosc_device' AND b_span_id = OLD.fosc_device_id));
    IF n > 0 THEN
        RAISE EXCEPTION 'Cannot delete device % (%): % active splice(s) use it. Retire or remove the splices first.', OLD.fosc_device_id, OLD.name, n;
    END IF;
    RETURN OLD;
END $$;


--
-- Name: trg_guard_span_in_splice(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_guard_span_in_splice() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE n int;
BEGIN
    SELECT count(*) INTO n FROM app.splice_join
     WHERE retired_at IS NULL
       AND ((a_target = 'cable_span' AND a_span_id = OLD.cable_span_id)
         OR (b_target = 'cable_span' AND b_span_id = OLD.cable_span_id));
    IF n > 0 THEN
        RAISE EXCEPTION 'Cannot delete cable span %: % active splice(s) use it. Retire or remove the splices first.', OLD.cable_span_id, n;
    END IF;
    RETURN OLD;
END $$;


--
-- Name: trg_object_moved_carry_termination(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_object_moved_carry_termination() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_TABLE_NAME = 'pole' THEN
        IF NEW.geom IS DISTINCT FROM OLD.geom THEN
            UPDATE app.conduit_termination SET geom = NEW.geom WHERE kind = 'pole' AND ref_pole_fid = NEW.fid;
        END IF;
    ELSIF TG_TABLE_NAME = 'fosc' THEN
        IF NEW.location IS DISTINCT FROM OLD.location THEN
            UPDATE app.conduit_termination SET geom = NEW.location WHERE kind = 'closure' AND ref_fosc_id = NEW.fosc_id;
        END IF;
    ELSE
        IF NEW.location IS DISTINCT FROM OLD.location AND NEW.location IS NOT NULL THEN
            UPDATE app.conduit_termination SET geom = NEW.location WHERE kind = 'pop' AND ref_pop_id = NEW.pop_id;
        END IF;
    END IF;
    RETURN NEW;
END $$;


--
-- Name: trg_pole_carry_cable_spans(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_pole_carry_cable_spans() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    r record;
    g geometry;
    n int;
    i int;
    changed boolean;
BEGIN
    IF NEW.geom IS NOT DISTINCT FROM OLD.geom OR OLD.geom IS NULL OR NEW.geom IS NULL THEN
        RETURN NULL;
    END IF;
    FOR r IN
        SELECT cable_span_id, geom_snap FROM app.cable_span
         WHERE geom_snap IS NOT NULL
           AND ST_DWithin(geom_snap::geography, OLD.geom::geography, 1.0)
    LOOP
        g := r.geom_snap; n := ST_NPoints(g); changed := false;
        FOR i IN 2 .. n - 1 LOOP
            IF ST_Distance(ST_PointN(g, i)::geography, OLD.geom::geography) <= 1.0 THEN
                g := ST_SetPoint(g, i - 1, NEW.geom);
                changed := true;
            END IF;
        END LOOP;
        IF changed THEN
            UPDATE app.cable_span SET geom_snap = g WHERE cable_span_id = r.cable_span_id;
        END IF;
    END LOOP;
    RETURN NULL;
END $$;


--
-- Name: trg_pole_refresh_strand_geom(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_pole_refresh_strand_geom() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.geom IS NOT DISTINCT FROM OLD.geom THEN RETURN NULL; END IF;
    UPDATE app.messenger_strand ms
       SET geom = app.strand_geom(ms.from_pole_fid, ms.from_anchor_id,
                                  ms.to_pole_fid,   ms.to_anchor_id)
     WHERE ms.from_pole_fid = NEW.fid OR ms.to_pole_fid = NEW.fid;
    RETURN NULL;
END $$;


--
-- Name: trg_route_coincidence_bump(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_route_coincidence_bump() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    UPDATE app.route_coincidence_version SET v = v + 1 WHERE id = 1;
    RETURN NULL;
END $$;


--
-- Name: trg_set_updated_at(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;


--
-- Name: trg_span_check_slack_loop(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_span_check_slack_loop() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  -- check OLD attachment
  IF TG_OP IN ('UPDATE','DELETE') THEN
    IF OLD.from_fosc_id IS NOT NULL THEN
      PERFORM app.check_slack_loop_cables(OLD.from_fosc_id);
    END IF;
    IF OLD.to_fosc_id IS NOT NULL
       AND OLD.to_fosc_id <> OLD.from_fosc_id THEN
      PERFORM app.check_slack_loop_cables(OLD.to_fosc_id);
    END IF;
  END IF;

  -- check NEW attachment
  IF TG_OP IN ('INSERT','UPDATE') THEN
    IF NEW.from_fosc_id IS NOT NULL THEN
      PERFORM app.check_slack_loop_cables(NEW.from_fosc_id);
    END IF;
    IF NEW.to_fosc_id IS NOT NULL
       AND NEW.to_fosc_id <> NEW.from_fosc_id THEN
      PERFORM app.check_slack_loop_cables(NEW.to_fosc_id);
    END IF;
  END IF;

  RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: trg_span_support(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_span_support() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'INSERT' OR NEW.geom_snap IS DISTINCT FROM OLD.geom_snap THEN
        PERFORM app.rebuild_span_support('span', NEW.cable_span_id);
    END IF;
    RETURN NULL;
END $$;


--
-- Name: trg_stamp(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_stamp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  IF TG_OP = 'INSERT' AND NEW.created_at IS NULL THEN
    NEW.created_at := now();
  END IF;
  RETURN NEW;
END;
$$;


--
-- Name: trg_strand_set_geom(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_strand_set_geom() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.geom := app.strand_geom(NEW.from_pole_fid, NEW.from_anchor_id,
                                NEW.to_pole_fid,   NEW.to_anchor_id);
    RETURN NEW;
END $$;


--
-- Name: trg_strand_support(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_strand_support() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'INSERT' OR NEW.geom IS DISTINCT FROM OLD.geom THEN
        PERFORM app.rebuild_span_support('strand', NEW.id);
    END IF;
    RETURN NULL;
END $$;


--
-- Name: trg_update_body_tsv(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_update_body_tsv() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.body_tsv := to_tsvector('english', unaccent(coalesce(NEW.body_text, '')));
  RETURN NEW;
END;
$$;


--
-- Name: trg_validate_slack_loop_splice(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.trg_validate_slack_loop_splice() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_kind        text;
  v_fibre_count integer;
  v_splice_count integer;
  v_full_range  int4range;
BEGIN
  -- Only validate cable_span ↔ cable_span splices
  IF NEW.a_target <> 'cable_span' OR NEW.b_target <> 'cable_span' THEN
    RETURN NEW;
  END IF;

  SELECT f.kind INTO v_kind
  FROM app.fosc f
  WHERE f.fosc_id = NEW.fosc_id;

  -- Not a slack-loop FOSC — no extra constraints
  IF v_kind IS NULL OR v_kind <> 'slack' THEN
    RETURN NEW;
  END IF;

  -- (a) join_kind must be pass_through
  IF NEW.join_kind <> 'pass_through' THEN
    RAISE EXCEPTION
      'Slack-loop FOSC % only allows pass_through splices (got %).',
      NEW.fosc_id, NEW.join_kind
      USING ERRCODE = 'check_violation';
  END IF;

  -- (b) at most 1 splice record per slack-loop FOSC
  SELECT count(*) INTO v_splice_count
  FROM app.splice_join j
  WHERE j.fosc_id = NEW.fosc_id
    AND (TG_OP = 'INSERT' OR j.splice_join_id <> NEW.splice_join_id);

  IF v_splice_count >= 1 THEN
    RAISE EXCEPTION
      'Slack-loop FOSC % may only have 1 splice record (already has %).',
      NEW.fosc_id, v_splice_count
      USING ERRCODE = 'check_violation';
  END IF;

  -- (c) ranges must cover all fibres in the cable
  SELECT c.fibre_count INTO v_fibre_count
  FROM app.cable_span cs
  JOIN app.cable c ON c.cable_id = cs.cable_id
  WHERE cs.cable_span_id = NEW.a_span_id;

  IF v_fibre_count IS NOT NULL THEN
    v_full_range := int4range(1, v_fibre_count + 1);

    IF NEW.a_range <> v_full_range OR NEW.b_range <> v_full_range THEN
      RAISE EXCEPTION
        'Slack-loop FOSC % splice must cover all fibres [1,%); got a_range=%, b_range=%.',
        NEW.fosc_id, v_fibre_count + 1, NEW.a_range, NEW.b_range
        USING ERRCODE = 'check_violation';
    END IF;
  END IF;

  RETURN NEW;
END;
$$;


--
-- Name: undirected_pair(bigint, bigint); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.undirected_pair(a bigint, b bigint) RETURNS bigint
    LANGUAGE sql IMMUTABLE
    AS $$
  SELECT (LEAST(a,b)::bigint << 32) + GREATEST(a,b)::bigint;
$$;


--
-- Name: undo_change(text, boolean); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.undo_change(p_change_id text, p_apply boolean DEFAULT false) RETURNS TABLE(step integer, audit_log_id bigint, action text, table_name text, row_pk text, result text)
    LANGUAGE plpgsql
    AS $$
DECLARE
    r        record;
    tbl      text;
    pkwhere  text;
    cols     text;
    sql      text;
    n        bigint;
    i        int := 0;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM app.change_set WHERE change_id = p_change_id) THEN
        RAISE EXCEPTION 'No such change: %', p_change_id;
    END IF;
    IF p_change_id = 'LEGC_0' THEN RAISE EXCEPTION 'LEGC_0 is every row from before tracking; it cannot be undone'; END IF;
    IF p_apply THEN
        PERFORM app.begin_change(COALESCE(NULLIF(current_setting('app.actor', true), ''), 'UNKN'),
                                 COALESCE(NULLIF(current_setting('app.source', true), ''), 'psql'),
                                 'UNDO of ' || p_change_id);
    END IF;
    FOR r IN SELECT * FROM app.audit_log l WHERE l.change_id = p_change_id ORDER BY l.audit_log_id DESC LOOP
        i := i + 1;
        tbl := format('%I.%I', r.schema_name, r.table_name);
        -- primary-key condition built from the logged row
        SELECT string_agg(format('t.%I::text = %L', a.attname, COALESCE(r.new_data, r.old_data) ->> a.attname), ' AND ' ORDER BY k.ord)
          INTO pkwhere
          FROM pg_index ix
          CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY k(attnum, ord)
          JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = k.attnum
         WHERE ix.indrelid = tbl::regclass AND ix.indisprimary;
        IF pkwhere IS NULL THEN
            step := i; audit_log_id := r.audit_log_id; action := r.action; table_name := r.table_name; row_pk := r.row_pk;
            result := 'SKIPPED: table has no primary key'; RETURN NEXT; CONTINUE;
        END IF;
        -- writable, non-generated, non-bytea columns present in the logged row
        SELECT string_agg(format('%I', a.attname), ', ' ORDER BY a.attnum) INTO cols
          FROM pg_attribute a
         WHERE a.attrelid = tbl::regclass AND a.attnum > 0 AND NOT a.attisdropped AND a.attgenerated = ''
           AND a.attname NOT IN ('created_change')
           AND a.atttypid <> 'bytea'::regtype
           AND COALESCE(r.old_data, r.new_data) ? a.attname;

        IF r.action = 'INSERT' THEN
            sql := format('DELETE FROM %s t WHERE %s', tbl, pkwhere);
        ELSIF r.action = 'UPDATE' THEN
            sql := format('UPDATE %s t SET (%s) = (SELECT %s FROM jsonb_populate_record(NULL::%s, %L) x) WHERE %s',
                          tbl, cols, (SELECT string_agg(format('x.%I', c), ', ') FROM unnest(string_to_array(cols, ', ')) c), tbl, r.old_data::text, pkwhere);
        ELSE
            sql := format('INSERT INTO %s (%s) SELECT %s FROM jsonb_populate_record(NULL::%s, %L) x ON CONFLICT DO NOTHING',
                          tbl, cols, (SELECT string_agg(format('x.%I', c), ', ') FROM unnest(string_to_array(cols, ', ')) c), tbl, r.old_data::text);
        END IF;
        step := i; audit_log_id := r.audit_log_id; action := r.action; table_name := r.table_name; row_pk := r.row_pk;
        IF p_apply THEN
            EXECUTE sql;
            GET DIAGNOSTICS n = ROW_COUNT;
            result := CASE WHEN n = 0 THEN 'no row affected' ELSE 'done' END;
        ELSE
            result := 'would run: ' || left(sql, 160);
        END IF;
        RETURN NEXT;
    END LOOP;
END $$;


--
-- Name: v_cable_span_edit_del(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_cable_span_edit_del() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'app', 'public', 'pg_temp'
    AS $$
BEGIN
  -- delete the underlying base row
  DELETE FROM app.cable_span
  WHERE cable_span_id = OLD.cable_span_id;

  -- return NULL for INSTEAD OF DELETE
  RETURN NULL;
END
$$;


--
-- Name: v_cable_span_edit_ins(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_cable_span_edit_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  ep RECORD;
  new_id bigint;
BEGIN
  -- Endpoints from the provided FOSCs
  SELECT f1.location AS from_pt, f2.location AS to_pt
    INTO ep
    FROM app.fosc f1, app.fosc f2
   WHERE f1.fosc_id = NEW.from_fosc_id
     AND f2.fosc_id = NEW.to_fosc_id;

  INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, notes,
                              geom_middle, geom_snap)
  VALUES (NEW.cable_id, NEW.from_fosc_id, NEW.to_fosc_id, NEW.notes,
          NEW.geom,
          app._assemble_span(NEW.geom, ep.from_pt, ep.to_pt))
  RETURNING cable_span_id INTO new_id;

  RETURN (
    SELECT v
    FROM app.v_cable_span_edit v
    WHERE v.cable_span_id = new_id
  );
END
$$;


--
-- Name: v_cable_span_edit_upd(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_cable_span_edit_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  ep RECORD;
  target_id bigint := OLD.cable_span_id;
BEGIN
  -- Pull current endpoints based on possibly-changed FOSC ids
  SELECT f1.location AS from_pt, f2.location AS to_pt
    INTO ep
    FROM app.fosc f1, app.fosc f2
   WHERE f1.fosc_id = COALESCE(NEW.from_fosc_id, OLD.from_fosc_id)
     AND f2.fosc_id = COALESCE(NEW.to_fosc_id,   OLD.to_fosc_id);

  -- Update base table; recompute snapped geometry
  UPDATE app.cable_span s
     SET cable_id     = COALESCE(NEW.cable_id, s.cable_id),
         from_fosc_id = COALESCE(NEW.from_fosc_id, s.from_fosc_id),
         to_fosc_id   = COALESCE(NEW.to_fosc_id,   s.to_fosc_id),
         notes        = COALESCE(NEW.notes,        s.notes),
         geom_middle  = COALESCE(NEW.geom,         s.geom_middle),
         geom_snap    = app._assemble_span(COALESCE(NEW.geom, s.geom_middle),
                                           ep.from_pt, ep.to_pt),
         updated_at   = now()
   WHERE s.cable_span_id = target_id;

  -- Return the fresh row as the view expects
  RETURN (
    SELECT v
    FROM app.v_cable_span_edit v
    WHERE v.cable_span_id = target_id
  );
END
$$;


--
-- Name: v_fosc_device_edit_del(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_fosc_device_edit_del() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  DELETE FROM app.fosc_device
   WHERE fosc_device_id = OLD.fosc_device_id;
  RETURN NULL;
END $$;


--
-- Name: v_fosc_device_edit_ins(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_fosc_device_edit_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  pgeom geometry(Point,2953);
BEGIN
  -- If no geometry supplied from QGIS, snap to parent FOSC location
  IF NEW.geom IS NULL THEN
    SELECT f.location INTO pgeom FROM app.fosc f WHERE f.fosc_id = NEW.fosc_id;
  ELSE
    pgeom := ST_SetSRID(NEW.geom, 2953);
  END IF;

  INSERT INTO app.fosc_device (fosc_id, device_type, name, attrs, notes, location)
  VALUES (
    NEW.fosc_id,
    COALESCE(NULLIF(NEW.device_type,''),'unknown'),
    NULLIF(NEW.name,''),
    COALESCE(NEW.attrs, '{}'::jsonb),
    NULLIF(NEW.notes,''),
    pgeom
  )
  RETURNING fosc_device_id, location
  INTO NEW.fosc_device_id, NEW.geom;

  RETURN NEW;
END $$;


--
-- Name: v_fosc_device_edit_upd(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_fosc_device_edit_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  UPDATE app.fosc_device fd
     SET fosc_id     = COALESCE(NEW.fosc_id,     fd.fosc_id),
         device_type = COALESCE(NULLIF(NEW.device_type,''), fd.device_type),
         name        = COALESCE(NULLIF(NEW.name,''),        fd.name),
         attrs       = COALESCE(NEW.attrs,                  fd.attrs),
         notes       = COALESCE(NULLIF(NEW.notes,''),       fd.notes),
         location    = COALESCE(
                         CASE WHEN NEW.geom IS NULL THEN NULL
                              ELSE ST_SetSRID(NEW.geom,2953) END,
                         fd.location)
   WHERE fd.fosc_device_id = OLD.fosc_device_id;

  -- Return fresh row to QGIS
  SELECT fd.fosc_device_id, fd.fosc_id, fd.device_type, fd.name, fd.attrs, fd.notes, fd.location
    INTO NEW
    FROM app.fosc_device fd
   WHERE fd.fosc_device_id = OLD.fosc_device_id;

  RETURN NEW;
END $$;


--
-- Name: v_fosc_qgis_del(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_fosc_qgis_del() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  DELETE FROM app.fosc WHERE fosc_id = OLD.fosc_id;
  RETURN OLD;
END;
$$;


--
-- Name: v_fosc_qgis_ins(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_fosc_qgis_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_type_id BIGINT;
BEGIN
  -- Lookup closure type by name (required)
  SELECT closure_type_id
    INTO v_type_id
  FROM app.closure_type
  WHERE name = NEW.closure_type_name
  LIMIT 1;

  IF v_type_id IS NULL THEN
    RAISE EXCEPTION 'Unknown closure type: %', NEW.closure_type_name;
  END IF;

  INSERT INTO app.fosc (splice_num, status, location, closure_type_id, notes)
  VALUES (
    UPPER(NEW.splice_num),                   -- normalize
    NEW.status::app.fosc_status,            -- text -> enum
    NEW.geom,                               
    v_type_id,
    NEW.notes
  )
  RETURNING fosc_id INTO NEW.fosc_id;

  RETURN NEW;
END;
$$;


--
-- Name: v_fosc_qgis_upd(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_fosc_qgis_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_type_id BIGINT;
BEGIN
  SELECT closure_type_id
    INTO v_type_id
  FROM app.closure_type
  WHERE name = NEW.closure_type_name
  LIMIT 1;

  IF v_type_id IS NULL THEN
    RAISE EXCEPTION 'Unknown closure type: %', NEW.closure_type_name;
  END IF;

  UPDATE app.fosc
     SET splice_num      = UPPER(NEW.splice_num),
         status          = NEW.status::app.fosc_status,
         location        = NEW.geom,
         closure_type_id = v_type_id,
         notes           = NEW.notes,
         updated_at      = now()
   WHERE fosc_id = OLD.fosc_id;

  RETURN NEW;
END;
$$;


--
-- Name: v_pole_qgis_del(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_pole_qgis_del() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  DELETE FROM app.pole WHERE fid = OLD.fid;
  RETURN OLD;
END;
$$;


--
-- Name: v_pole_qgis_ins(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_pole_qgis_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_fid BIGINT;
BEGIN
  SELECT COALESCE(MAX(fid), 0) + 1 INTO v_fid FROM app.pole;

  INSERT INTO app.pole (fid, uid, type, owner, comment, fpm_wor, "nbp#", "telco#", "tags#", geom)
  VALUES (
    v_fid,
    NEW.uid,
    NEW.type,
    NEW.owner,
    NEW.comment,
    NEW.work_order,
    NEW.nbp_num,
    NEW.telco_num,
    NEW.tags_num,
    NEW.geom
  );

  NEW.fid := v_fid;
  RETURN NEW;
END;
$$;


--
-- Name: v_pole_qgis_read_only(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_pole_qgis_read_only() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION
        'app.v_pole_qgis is read-only (% refused). Edit app.pole directly, or '
        'restore the INSTEAD OF triggers from 0050/0051/0052 if QGIS editing is '
        'wanted again — see 0170.', TG_OP
        USING ERRCODE = 'read_only_sql_transaction';
END;
$$;


--
-- Name: v_pole_qgis_upd(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.v_pole_qgis_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  UPDATE app.pole
     SET uid        = NEW.uid,
         type       = NEW.type,
         owner      = NEW.owner,
         comment    = NEW.comment,
         fpm_wor = NEW.work_order,
         "nbp#"     = NEW.nbp_num,
         "telco#"   = NEW.telco_num,
         "tags#"    = NEW.tags_num,
         geom       = NEW.geom
   WHERE fid = OLD.fid;

  RETURN NEW;
END;
$$;


--
-- Name: vault_attach_conduits(integer, topology.geometry); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.vault_attach_conduits(p_vault integer, p_old topology.geometry DEFAULT NULL::topology.geometry) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v      app.vault%ROWTYPE;
    c      record;
    moved  integer := 0;
    snapped integer := 0;
    split  integer := 0;
    g      geometry;
    near_m constant double precision := 5;
    on_m   constant double precision := 0.6;
BEGIN
    SELECT * INTO v FROM app.vault WHERE vault_id = p_vault;
    IF NOT FOUND OR v.geom IS NULL THEN
        RETURN jsonb_build_object('moved', 0, 'snapped', 0, 'split', 0);
    END IF;

    -- conduit ends that sat on the old position go with the vault
    IF p_old IS NOT NULL THEN
        FOR c IN SELECT conduit_id, geom FROM app.conduit
                  WHERE owner IS NOT DISTINCT FROM v.owner AND geom IS NOT NULL
                    AND (ST_DWithin(ST_StartPoint(geom)::geography, p_old::geography, on_m)
                      OR ST_DWithin(ST_EndPoint(geom)::geography,   p_old::geography, on_m)) LOOP
            g := c.geom;
            IF ST_DWithin(ST_StartPoint(g)::geography, p_old::geography, on_m) THEN g := ST_SetPoint(g, 0, v.geom); END IF;
            IF ST_DWithin(ST_EndPoint(g)::geography,   p_old::geography, on_m) THEN g := ST_SetPoint(g, ST_NPoints(g) - 1, v.geom); END IF;
            UPDATE app.conduit SET geom = g WHERE conduit_id = c.conduit_id;
            moved := moved + 1;
        END LOOP;
    END IF;

    -- conduits that pass or end near the vault, and are not already attached to it
    FOR c IN SELECT conduit_id, geom FROM app.conduit
              WHERE owner IS NOT DISTINCT FROM v.owner AND geom IS NOT NULL
                AND ST_DWithin(geom::geography, v.geom::geography, near_m)
                AND NOT ST_DWithin(ST_StartPoint(geom)::geography, v.geom::geography, on_m)
                AND NOT ST_DWithin(ST_EndPoint(geom)::geography,   v.geom::geography, on_m) LOOP
        IF ST_DWithin(ST_StartPoint(c.geom)::geography, v.geom::geography, near_m) THEN
            UPDATE app.conduit SET geom = ST_SetPoint(c.geom, 0, v.geom) WHERE conduit_id = c.conduit_id;
            snapped := snapped + 1;
        ELSIF ST_DWithin(ST_EndPoint(c.geom)::geography, v.geom::geography, near_m) THEN
            UPDATE app.conduit SET geom = ST_SetPoint(c.geom, ST_NPoints(c.geom) - 1, v.geom) WHERE conduit_id = c.conduit_id;
            snapped := snapped + 1;
        ELSIF app.split_conduit(c.conduit_id, v.geom) IS NOT NULL THEN
            split := split + 1;
        END IF;
    END LOOP;
    RETURN jsonb_build_object('moved', moved, 'snapped', snapped, 'split', split);
END $$;


--
-- Name: wor_span_m(text); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.wor_span_m(p_wor text) RETURNS double precision
    LANGUAGE sql STABLE
    AS $$
    WITH RECURSIVE
    wor_poles AS (
        SELECT fid, geom::geometry AS g
        FROM app.pole
        WHERE fpm_wor = p_wor
    ),
    start_pole AS (
        SELECT fid, g FROM wor_poles ORDER BY ST_X(g) LIMIT 1
    ),
    chain AS (
        SELECT fid, g, ARRAY[fid]::bigint[] AS visited, 0.0::double precision AS total_m
        FROM start_pole
        UNION ALL
        SELECT nxt.fid, nxt.g, chain.visited || nxt.fid, chain.total_m + ST_Distance(chain.g, nxt.g)
        FROM chain
        CROSS JOIN LATERAL (
            SELECT fid, g FROM wor_poles
            WHERE fid <> ALL(chain.visited)
            ORDER BY chain.g <-> g
            LIMIT 1
        ) nxt
    )
    SELECT COALESCE(MAX(total_m), 0) FROM chain
$$;


--
-- Name: work_order_column_to_link(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.work_order_column_to_link() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
    v_link text := TG_ARGV[0]; v_lk text := TG_ARGV[1]; v_key text := TG_ARGV[2];
    v_id bigint := (to_jsonb(NEW) ->> v_key)::bigint;
BEGIN
    IF COALESCE(NEW.fpm_wor, '') = '' THEN
        EXECUTE format('DELETE FROM app.%I WHERE %I = $1 AND is_primary', v_link, v_lk) USING v_id;
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM app.fpm_permit WHERE permit_num = NEW.fpm_wor) THEN RETURN NULL; END IF;
    EXECUTE format('UPDATE app.%I SET is_primary = false WHERE %I = $1 AND is_primary AND work_order <> $2', v_link, v_lk)
        USING v_id, NEW.fpm_wor;
    EXECUTE format('INSERT INTO app.%I (%I, work_order, is_primary) VALUES ($1, $2, true)
                    ON CONFLICT (%I, work_order) DO UPDATE SET is_primary = true', v_link, v_lk, v_lk)
        USING v_id, NEW.fpm_wor;
    RETURN NULL;
END $_$;


--
-- Name: work_order_link_to_column(); Type: FUNCTION; Schema: app; Owner: -
--

CREATE FUNCTION app.work_order_link_to_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
    -- TG_ARGV: item table, item key column, link key column
    v_tbl text := TG_ARGV[0]; v_key text := TG_ARGV[1]; v_lk text := TG_ARGV[2];
    v_id bigint;
BEGIN
    IF pg_trigger_depth() > 1 THEN RETURN NULL; END IF;
    IF TG_OP = 'DELETE' THEN
        v_id := (to_jsonb(OLD) ->> v_lk)::bigint;
        EXECUTE format('UPDATE app.%I SET fpm_wor = NULL WHERE %I = $1 AND fpm_wor = $2 AND NOT EXISTS (
                            SELECT 1 FROM app.%I l WHERE l.%I = $1 AND l.is_primary)', v_tbl, v_key, TG_TABLE_NAME, v_lk)
            USING v_id, OLD.work_order;
        RETURN NULL;
    END IF;
    v_id := (to_jsonb(NEW) ->> v_lk)::bigint;
    IF NEW.is_primary THEN
        EXECUTE format('UPDATE app.%I SET fpm_wor = $2 WHERE %I = $1 AND fpm_wor IS DISTINCT FROM $2', v_tbl, v_key)
            USING v_id, NEW.work_order;
    ELSE
        EXECUTE format('UPDATE app.%I SET fpm_wor = NULL WHERE %I = $1 AND fpm_wor = $2 AND NOT EXISTS (
                            SELECT 1 FROM app.%I l WHERE l.%I = $1 AND l.is_primary)', v_tbl, v_key, TG_TABLE_NAME, v_lk)
            USING v_id, NEW.work_order;
    END IF;
    RETURN NULL;
END $_$;


--
-- Name: actor; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.actor (
    actor_code text NOT NULL,
    display_name text NOT NULL,
    kind text NOT NULL,
    app_user_id bigint,
    active boolean DEFAULT true NOT NULL,
    notes text,
    CONSTRAINT actor_actor_code_check CHECK ((actor_code ~ '^[A-Z]{4}$'::text)),
    CONSTRAINT actor_kind_check CHECK ((kind = ANY (ARRAY['person'::text, 'ai'::text, 'script'::text, 'system'::text])))
);


--
-- Name: anchor; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.anchor (
    id integer NOT NULL,
    pole_fid bigint,
    owner text,
    status app.anchor_status DEFAULT 'plan'::app.anchor_status NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    direction text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT anchor_direction_check CHECK (((direction IS NULL) OR (direction = ANY (ARRAY['N'::text, 'NNE'::text, 'NE'::text, 'ENE'::text, 'E'::text, 'ESE'::text, 'SE'::text, 'SSE'::text, 'S'::text, 'SSW'::text, 'SW'::text, 'WSW'::text, 'W'::text, 'WNW'::text, 'NW'::text, 'NNW'::text]))))
);


--
-- Name: anchor_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.anchor_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: anchor_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.anchor_id_seq OWNED BY app.anchor.id;


--
-- Name: app_user; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.app_user (
    app_user_id bigint NOT NULL,
    username text NOT NULL,
    display_name text,
    email text,
    is_active boolean DEFAULT true NOT NULL,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    role text DEFAULT 'user'::text NOT NULL,
    provider text DEFAULT 'google'::text NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT app_user_role_check CHECK ((role = ANY (ARRAY['admin'::text, 'user'::text]))),
    CONSTRAINT app_user_username_chk CHECK ((username ~ '^[a-z0-9_][a-z0-9_\.-]{1,63}$'::text))
);


--
-- Name: app_user_app_user_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.app_user_app_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: app_user_app_user_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.app_user_app_user_id_seq OWNED BY app.app_user.app_user_id;


--
-- Name: asset_density_hex; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.asset_density_hex (
    zoom smallint NOT NULL,
    geom topology.geometry(Polygon,4326) NOT NULL,
    cnt integer NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: asset_density_meta; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.asset_density_meta (
    singleton boolean DEFAULT true NOT NULL,
    refreshed_at timestamp with time zone,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: audit_exempt; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.audit_exempt (
    table_name text NOT NULL,
    reason text NOT NULL
);


--
-- Name: audit_log; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.audit_log (
    audit_log_id bigint NOT NULL,
    happened_at timestamp with time zone DEFAULT now() NOT NULL,
    actor_user_id bigint,
    actor_username text NOT NULL,
    action text NOT NULL,
    schema_name text NOT NULL,
    table_name text NOT NULL,
    row_pk text,
    old_data jsonb,
    new_data jsonb,
    change_id text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: audit_log_audit_log_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.audit_log_audit_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_log_audit_log_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.audit_log_audit_log_id_seq OWNED BY app.audit_log.audit_log_id;


--
-- Name: auth_identity; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.auth_identity (
    auth_identity_id bigint NOT NULL,
    app_user_id bigint NOT NULL,
    provider text NOT NULL,
    provider_subject text NOT NULL,
    email text,
    profile_json jsonb,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: auth_identity_auth_identity_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.auth_identity_auth_identity_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_identity_auth_identity_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.auth_identity_auth_identity_id_seq OWNED BY app.auth_identity.auth_identity_id;


--
-- Name: bell_permit; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.bell_permit (
    id integer NOT NULL,
    permit_num text NOT NULL,
    applicant text,
    municipality text,
    status app.bell_permit_status DEFAULT 'draft'::app.bell_permit_status NOT NULL,
    permit_date date,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    fpm_permit_num text,
    nbp_permit_num text,
    eng_search_cost numeric(12,2),
    make_ready_cost numeric(12,2),
    inspection_cost numeric(12,2),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: bell_permit_document_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bell_permit_document_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bell_permit_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bell_permit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bell_permit_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.bell_permit_id_seq OWNED BY app.bell_permit.id;


--
-- Name: bell_permit_import_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bell_permit_import_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bell_permit_pole; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.bell_permit_pole (
    id integer NOT NULL,
    permit_id integer NOT NULL,
    pole_fid bigint,
    bell_pole_num text,
    attachment_position text,
    notes text,
    nbp_num text,
    pole_owner text DEFAULT 'BA'::text,
    attachment_type text DEFAULT 'strand'::text NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT bell_permit_pole_attachment_type_check CHECK ((attachment_type = ANY (ARRAY['strand'::text, 'overlash'::text]))),
    CONSTRAINT bell_permit_pole_pole_owner_check CHECK ((pole_owner = ANY (ARRAY['BA'::text, 'NBP'::text])))
);


--
-- Name: bell_permit_pole_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bell_permit_pole_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bell_permit_pole_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.bell_permit_pole_id_seq OWNED BY app.bell_permit_pole.id;


--
-- Name: bell_permit_timeline_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bell_permit_timeline_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bom_extra; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.bom_extra (
    id integer NOT NULL,
    fpm_wor text NOT NULL,
    material_id integer,
    description text NOT NULL,
    qty numeric(12,3) NOT NULL,
    unit text DEFAULT 'ea'::text NOT NULL,
    unit_price numeric(12,4),
    notes text DEFAULT ''::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: bom_extra_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bom_extra_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bom_extra_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.bom_extra_id_seq OWNED BY app.bom_extra.id;


--
-- Name: bom_material; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.bom_material (
    id integer NOT NULL,
    sku text DEFAULT ''::text NOT NULL,
    description text NOT NULL,
    category text DEFAULT 'make_ready'::text NOT NULL,
    unit text DEFAULT 'ea'::text NOT NULL,
    active boolean DEFAULT true NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    manufacturer text DEFAULT ''::text NOT NULL,
    assembly_length_m numeric(10,2),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: bom_material_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bom_material_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bom_material_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.bom_material_id_seq OWNED BY app.bom_material.id;


--
-- Name: bom_price; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.bom_price (
    id integer NOT NULL,
    material_id integer NOT NULL,
    price_per_unit numeric(12,4) NOT NULL,
    currency text DEFAULT 'CAD'::text NOT NULL,
    supplier text DEFAULT ''::text NOT NULL,
    invoice_ref text DEFAULT ''::text NOT NULL,
    effective_date date NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    foreign_price numeric(12,4),
    foreign_currency text,
    fx_rate numeric(9,5),
    freight_pct numeric(6,2),
    freight_exact boolean,
    CONSTRAINT bom_price_foreign_chk CHECK (((foreign_price IS NULL) OR ((foreign_currency IS NOT NULL) AND (fx_rate > (0)::numeric))))
);


--
-- Name: bom_price_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.bom_price_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bom_price_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.bom_price_id_seq OWNED BY app.bom_price.id;


--
-- Name: building_anchor; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.building_anchor (
    id bigint NOT NULL,
    name text,
    notes text,
    status text DEFAULT 'plan'::text NOT NULL,
    fpm_wor text,
    geom topology.geometry(Point,4326) NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT building_anchor_status_check CHECK ((status = ANY (ARRAY['plan'::text, 'construction'::text, 'active'::text])))
);


--
-- Name: building_anchor_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.building_anchor_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: building_anchor_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.building_anchor_id_seq OWNED BY app.building_anchor.id;


--
-- Name: cable; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.cable (
    cable_id bigint NOT NULL,
    name text,
    fibre_count integer,
    notes text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    open_ended boolean DEFAULT false NOT NULL,
    material_id integer,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: cable_cable_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.cable_cable_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cable_cable_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.cable_cable_id_seq OWNED BY app.cable.cable_id;


--
-- Name: cable_span; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.cable_span (
    cable_span_id bigint NOT NULL,
    cable_id bigint NOT NULL,
    from_fosc_id bigint NOT NULL,
    to_fosc_id bigint NOT NULL,
    undirected_pair_key bigint GENERATED ALWAYS AS (app.undirected_pair(from_fosc_id, to_fosc_id)) STORED,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    geom_middle topology.geometry(LineString,4326),
    notes text,
    geom_snap topology.geometry(LineString,4326),
    geom topology.geometry(LineString,4326) GENERATED ALWAYS AS (geom_snap) STORED,
    length_m double precision GENERATED ALWAYS AS (topology.st_length((geom_snap)::topology.geography)) STORED,
    fpm_wor text,
    is_spare boolean DEFAULT false NOT NULL,
    installation text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    installed boolean DEFAULT false NOT NULL,
    is_private boolean DEFAULT false NOT NULL,
    cable_serial text,
    CONSTRAINT cable_span_not_self CHECK ((from_fosc_id <> to_fosc_id)),
    CONSTRAINT chk_cable_span_installation CHECK (((installation IS NULL) OR (installation = ANY (ARRAY['aerial'::text, 'conduit'::text, 'direct_buried'::text]))))
);


--
-- Name: cable_span_cable_span_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.cable_span_cable_span_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cable_span_cable_span_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.cable_span_cable_span_id_seq OWNED BY app.cable_span.cable_span_id;


--
-- Name: cable_span_conduit; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.cable_span_conduit (
    cable_span_id bigint NOT NULL,
    conduit_id integer NOT NULL,
    along_m numeric(9,1) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: cable_span_strand; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.cable_span_strand (
    cable_span_id bigint NOT NULL,
    strand_id integer NOT NULL,
    along_m numeric(9,1) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: cable_span_tag; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.cable_span_tag (
    span_tag_id bigint NOT NULL,
    cable_span_id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: cable_span_work_order; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.cable_span_work_order (
    id integer NOT NULL,
    cable_span_id bigint NOT NULL,
    work_order text NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: cable_span_work_order_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.cable_span_work_order_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cable_span_work_order_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.cable_span_work_order_id_seq OWNED BY app.cable_span_work_order.id;


--
-- Name: change_set; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.change_set (
    change_id text NOT NULL,
    change_no bigint NOT NULL,
    actor_code text NOT NULL,
    app_user_id bigint,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    source text NOT NULL,
    note text,
    CONSTRAINT change_set_change_id_check CHECK ((change_id ~ '^[A-Z]{4}_[0-9]+$'::text)),
    CONSTRAINT change_set_check CHECK ((change_id = ((actor_code || '_'::text) || change_no))),
    CONSTRAINT change_set_source_check CHECK ((source = ANY (ARRAY['web'::text, 'api'::text, 'psql'::text, 'cron'::text, 'script'::text, 'migration'::text, 'untracked'::text, 'legacy'::text])))
);


--
-- Name: change_set_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.change_set_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: circuit; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.circuit (
    circuit_id bigint NOT NULL,
    name text NOT NULL,
    kind text DEFAULT 'p2p'::text NOT NULL,
    customer_name text,
    crm_client_id bigint,
    speed_mbps integer,
    status text DEFAULT 'active'::text NOT NULL,
    protected_by_link text,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT circuit_kind_check CHECK ((kind = ANY (ARRAY['p2p'::text, 'dwdm_channel'::text, 'pon_feeder'::text, 'transport'::text, 'other'::text]))),
    CONSTRAINT circuit_speed_mbps_check CHECK ((speed_mbps > 0)),
    CONSTRAINT circuit_status_check CHECK ((status = ANY (ARRAY['planned'::text, 'active'::text, 'retired'::text])))
);


--
-- Name: circuit_circuit_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.circuit_circuit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: circuit_circuit_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.circuit_circuit_id_seq OWNED BY app.circuit.circuit_id;


--
-- Name: circuit_fibre; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.circuit_fibre (
    id bigint NOT NULL,
    circuit_id bigint NOT NULL,
    cable_span_id bigint NOT NULL,
    fibre_num integer NOT NULL,
    role text DEFAULT 'working'::text NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT circuit_fibre_fibre_num_check CHECK ((fibre_num > 0)),
    CONSTRAINT circuit_fibre_role_check CHECK ((role = ANY (ARRAY['working'::text, 'protect'::text])))
);


--
-- Name: circuit_fibre_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.circuit_fibre_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: circuit_fibre_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.circuit_fibre_id_seq OWNED BY app.circuit_fibre.id;


--
-- Name: closure_type; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.closure_type (
    closure_type_id bigint NOT NULL,
    name text NOT NULL,
    primary_bom_sku text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: closure_type_closure_type_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.closure_type_closure_type_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: closure_type_closure_type_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.closure_type_closure_type_id_seq OWNED BY app.closure_type.closure_type_id;


--
-- Name: conduit; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.conduit (
    conduit_id integer NOT NULL,
    name text DEFAULT ''::text NOT NULL,
    status app.conduit_status DEFAULT 'plan'::app.conduit_status NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    geom topology.geometry(LineString,4326),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    microduct_type_id integer,
    fpm_wor text,
    bell_permit_num text DEFAULT ''::text NOT NULL,
    owner text,
    is_spare boolean DEFAULT false NOT NULL,
    known_length_m numeric(8,2),
    known_length_tol_m numeric(6,2),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    installation text DEFAULT 'buried'::text NOT NULL,
    start_vault_id integer,
    end_vault_id integer,
    start_term_id integer,
    end_term_id integer,
    CONSTRAINT chk_conduit_ends_attached CHECK (((geom IS NULL) OR ((num_nonnulls(start_vault_id, start_term_id) = 1) AND (num_nonnulls(end_vault_id, end_term_id) = 1)))),
    CONSTRAINT chk_conduit_fpm_wor_single CHECK (((fpm_wor IS NULL) OR ((fpm_wor <> ''::text) AND (fpm_wor !~ ','::text)))),
    CONSTRAINT chk_conduit_known_length_positive CHECK (((known_length_m IS NULL) OR (known_length_m > (0)::numeric))),
    CONSTRAINT chk_conduit_known_length_tol CHECK (((known_length_tol_m IS NULL) OR ((known_length_tol_m >= (0)::numeric) AND (known_length_m IS NOT NULL)))),
    CONSTRAINT conduit_installation_check CHECK ((installation = ANY (ARRAY['buried'::text, 'aerial'::text])))
);


--
-- Name: conduit_conduit_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.conduit_conduit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: conduit_conduit_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.conduit_conduit_id_seq OWNED BY app.conduit.conduit_id;


--
-- Name: conduit_termination; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.conduit_termination (
    termination_id integer NOT NULL,
    kind text DEFAULT 'unverified'::text NOT NULL,
    label text,
    geom topology.geometry(Point,4326) NOT NULL,
    owner text,
    fpm_wor text,
    ref_pole_fid bigint,
    ref_fosc_id bigint,
    ref_pop_id bigint,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_term_fpm_wor CHECK (((fpm_wor IS NULL) OR ((fpm_wor <> ''::text) AND (fpm_wor !~ ','::text)))),
    CONSTRAINT conduit_termination_kind_check CHECK ((kind = ANY (ARRAY['building'::text, 'pole'::text, 'closure'::text, 'pop'::text, 'unverified'::text])))
);


--
-- Name: conduit_termination_termination_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.conduit_termination_termination_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: conduit_termination_termination_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.conduit_termination_termination_id_seq OWNED BY app.conduit_termination.termination_id;


--
-- Name: conduit_work_order; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.conduit_work_order (
    id integer NOT NULL,
    conduit_id integer NOT NULL,
    work_order text NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT conduit_work_order_work_order_check CHECK ((work_order <> ''::text))
);


--
-- Name: conduit_work_order_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.conduit_work_order_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: conduit_work_order_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.conduit_work_order_id_seq OWNED BY app.conduit_work_order.id;


--
-- Name: dhcp_lease_cache; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.dhcp_lease_cache (
    router_id text NOT NULL,
    router_name text NOT NULL,
    leases jsonb DEFAULT '[]'::jsonb NOT NULL,
    fetched_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: dimension; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.dimension (
    id integer NOT NULL,
    kind text DEFAULT 'straight'::text NOT NULL,
    geom topology.geometry(LineString,4326) NOT NULL,
    a_pole_fid bigint,
    b_pole_fid bigint,
    offset_m real DEFAULT 0 NOT NULL,
    label text,
    permit_ref text,
    fpm_wor text,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT dimension_kind_check CHECK ((kind = ANY (ARRAY['straight'::text, 'route'::text])))
);


--
-- Name: dimension_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.dimension_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dimension_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.dimension_id_seq OWNED BY app.dimension.id;


--
-- Name: document; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.document (
    document_id bigint NOT NULL,
    title text NOT NULL,
    description text,
    category text,
    filename text NOT NULL,
    mime_type text DEFAULT 'application/pdf'::text NOT NULL,
    file_size integer,
    file_data bytea NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: document_document_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.document_document_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_document_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.document_document_id_seq OWNED BY app.document.document_id;


--
-- Name: document_pid; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.document_pid (
    document_id bigint NOT NULL,
    pid text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT document_pid_pid_check CHECK ((pid ~ '^[0-9]{8}$'::text))
);


--
-- Name: document_text; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.document_text (
    document_id bigint NOT NULL,
    page integer NOT NULL,
    body text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: easement; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.easement (
    id integer NOT NULL,
    plan_no text NOT NULL,
    plan_date date,
    status text DEFAULT 'tentative'::text NOT NULL,
    easement_type text DEFAULT 'utility'::text NOT NULL,
    label text NOT NULL,
    width_m numeric(7,3),
    notes text,
    calls jsonb DEFAULT '[]'::jsonb NOT NULL,
    geom topology.geometry(Polygon,4326) NOT NULL,
    fpm_wor text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    pid text,
    CONSTRAINT easement_easement_type_check CHECK ((easement_type = ANY (ARRAY['utility'::text, 'power'::text, 'telecom'::text, 'sewer'::text, 'water'::text, 'drainage'::text, 'access'::text, 'other'::text]))),
    CONSTRAINT easement_status_check CHECK ((status = ANY (ARRAY['tentative'::text, 'final'::text, 'superseded'::text])))
);


--
-- Name: easement_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.easement_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: easement_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.easement_id_seq OWNED BY app.easement.id;


--
-- Name: flextin_run; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.flextin_run (
    id integer NOT NULL,
    fosc_id integer NOT NULL,
    side character(1) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT flextin_run_side_check CHECK ((side = ANY (ARRAY['L'::bpchar, 'R'::bpchar])))
);


--
-- Name: flextin_run_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.flextin_run_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: flextin_run_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.flextin_run_id_seq OWNED BY app.flextin_run.id;


--
-- Name: fosc; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.fosc (
    fosc_id bigint NOT NULL,
    splice_num text NOT NULL,
    status app.fosc_status DEFAULT 'plan'::app.fosc_status NOT NULL,
    location topology.geometry(Point,4326) NOT NULL,
    closure_type_id bigint NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    kind app.fosc_kind DEFAULT 'closure'::app.fosc_kind NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    fpm_wor text,
    pop_id bigint,
    enclosure_rating app.enclosure_rating DEFAULT 'outside'::app.enclosure_rating NOT NULL,
    slack_length_m numeric(7,2),
    civic_address text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_fosc_slack_length CHECK (((slack_length_m IS NULL) OR ((slack_length_m > (0)::numeric) AND (slack_length_m <= (500)::numeric)))),
    CONSTRAINT chk_fosc_splice_num_format CHECK ((splice_num ~ '^([A-Z]{3}[0-9]{4}|[LR][0-9]{2}(-MST)?)$'::text)),
    CONSTRAINT chk_fosc_status_not_slack CHECK (((status)::text <> 'slack loop'::text))
);


--
-- Name: fosc_device; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.fosc_device (
    fosc_device_id bigint NOT NULL,
    fosc_id bigint,
    device_type text NOT NULL,
    name text NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    notes text,
    location topology.geometry(Point,2953),
    pop_id bigint,
    parent_device_id bigint,
    parent_slot integer,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_closure_device_passive CHECK (((pop_id IS NOT NULL) OR (device_type = ANY (ARRAY['splitter'::text, 'oadm'::text, 'patch_panel'::text, 'mpt'::text])))),
    CONSTRAINT chk_device_home CHECK ((num_nonnulls(fosc_id, pop_id) = 1)),
    CONSTRAINT chk_device_not_self_parent CHECK (((parent_device_id IS NULL) OR (parent_device_id <> fosc_device_id))),
    CONSTRAINT chk_mpt_attrs CHECK (((device_type <> 'mpt'::text) OR (((attrs ->> 'mode'::text) = ANY (ARRAY['passive'::text, 'split'::text])) AND ((attrs ->> 'ports'::text) ~ '^[0-9]+$'::text) AND (((attrs ->> 'ports'::text))::integer >= 1) AND (((attrs ->> 'ports'::text))::integer <= 128)))),
    CONSTRAINT chk_patch_panel_ports CHECK (((device_type <> 'patch_panel'::text) OR ((attrs ? 'ports'::text) AND ((attrs ->> 'ports'::text) ~ '^[0-9]+$'::text) AND (((attrs ->> 'ports'::text))::integer >= 1) AND (((attrs ->> 'ports'::text))::integer <= 1000)))),
    CONSTRAINT chk_splitter_outs CHECK (((device_type <> 'splitter'::text) OR ((attrs ? 'outs'::text) AND ((attrs ->> 'outs'::text) ~ '^[0-9]+$'::text) AND ((((attrs ->> 'outs'::text))::integer >= 2) AND (((attrs ->> 'outs'::text))::integer <= 128))))),
    CONSTRAINT fosc_device_location_chk CHECK (((topology.geometrytype(location) = 'POINT'::text) AND (topology.st_srid(location) = 2953)))
);


--
-- Name: fosc_device_fosc_device_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.fosc_device_fosc_device_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fosc_device_fosc_device_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.fosc_device_fosc_device_id_seq OWNED BY app.fosc_device.fosc_device_id;


--
-- Name: fosc_fosc_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.fosc_fosc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fosc_fosc_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.fosc_fosc_id_seq OWNED BY app.fosc.fosc_id;


--
-- Name: fosc_work_order; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.fosc_work_order (
    id integer NOT NULL,
    fosc_id bigint NOT NULL,
    work_order text NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: fosc_work_order_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.fosc_work_order_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fosc_work_order_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.fosc_work_order_id_seq OWNED BY app.fosc_work_order.id;


--
-- Name: fpm_permit; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.fpm_permit (
    permit_num text NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    abandoned_at timestamp with time zone,
    abandoned_note text DEFAULT ''::text NOT NULL,
    CONSTRAINT chk_fpm_permit_format CHECK (((permit_num ~ '^\d{4}-\d+(-[A-Za-z0-9]*[A-Za-z][A-Za-z0-9]*)?$'::text) AND (((split_part(permit_num, '-'::text, 1))::integer < 2026) OR (permit_num ~ '^\d{4}-\d+-[A-Za-z0-9]*[A-Za-z][A-Za-z0-9]*$'::text))))
);


--
-- Name: fpm_permit_stage; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.fpm_permit_stage (
    id integer NOT NULL,
    permit_num text NOT NULL,
    stage app.construction_stage NOT NULL,
    effective_date date DEFAULT CURRENT_DATE NOT NULL,
    note text DEFAULT ''::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: fpm_permit_stage_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.fpm_permit_stage_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fpm_permit_stage_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.fpm_permit_stage_id_seq OWNED BY app.fpm_permit_stage.id;


--
-- Name: geonb_sync; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.geonb_sync (
    dataset text NOT NULL,
    signature text,
    row_count integer,
    checked_at timestamp with time zone,
    loaded_at timestamp with time zone,
    load_seconds numeric(8,1),
    last_error text,
    updated_at timestamp with time zone,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: gnb_wan_site_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.gnb_wan_site_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: http_check_http_check_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.http_check_http_check_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: lead_association; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.lead_association (
    lead_association_id bigint NOT NULL,
    crm_client_id bigint NOT NULL,
    fosc_id bigint NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    route_geom topology.geometry(LineString,4326),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: lead_association_lead_association_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.lead_association_lead_association_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: lead_association_lead_association_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.lead_association_lead_association_id_seq OWNED BY app.lead_association.lead_association_id;


--
-- Name: lnd_access_tech; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.lnd_access_tech (
    tech text NOT NULL,
    label text NOT NULL,
    down_mbps integer NOT NULL,
    up_mbps integer NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: lnd_external; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.lnd_external (
    ext_id integer NOT NULL,
    name text NOT NULL,
    kind text DEFAULT 'carrier'::text NOT NULL,
    asn integer,
    notes text,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT lnd_external_kind_check CHECK ((kind = ANY (ARRAY['internet'::text, 'carrier'::text, 'exchange'::text, 'other'::text])))
);


--
-- Name: lnd_external_ext_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.lnd_external_ext_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: lnd_external_ext_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.lnd_external_ext_id_seq OWNED BY app.lnd_external.ext_id;


--
-- Name: lnd_link; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.lnd_link (
    link_id text NOT NULL,
    name text NOT NULL,
    link_type text NOT NULL,
    a_pop_id bigint,
    a_ext_id integer,
    b_pop_id bigint,
    b_ext_id integer,
    down_mbps integer,
    up_mbps integer,
    status text DEFAULT 'existing'::text NOT NULL,
    role text DEFAULT 'primary'::text NOT NULL,
    resilient boolean DEFAULT false NOT NULL,
    funded_by text,
    fpm_wor text,
    sort_order integer DEFAULT 0 NOT NULL,
    notes text,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT lnd_link_down_mbps_check CHECK ((down_mbps > 0)),
    CONSTRAINT lnd_link_one_a CHECK ((num_nonnulls(a_pop_id, a_ext_id) = 1)),
    CONSTRAINT lnd_link_one_b CHECK ((num_nonnulls(b_pop_id, b_ext_id) = 1)),
    CONSTRAINT lnd_link_role_check CHECK ((role = ANY (ARRAY['primary'::text, 'backup'::text]))),
    CONSTRAINT lnd_link_status_check CHECK ((status = ANY (ARRAY['existing'::text, 'new'::text, 'upgraded'::text, 'planned'::text]))),
    CONSTRAINT lnd_link_up_mbps_check CHECK ((up_mbps > 0))
);


--
-- Name: lnd_route; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.lnd_route (
    main_cable_id integer NOT NULL,
    route_id text GENERATED ALWAYS AS (('RT-'::text || lpad((main_cable_id)::text, 4, '0'::text))) STORED,
    route_kind text DEFAULT 'access'::text NOT NULL,
    a_site text NOT NULL,
    b_site text NOT NULL,
    via_sites text[],
    fpm_wor text,
    funded_by text,
    sort_order integer DEFAULT 0 NOT NULL,
    notes text,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT lnd_route_route_kind_check CHECK ((route_kind = ANY (ARRAY['feeder'::text, 'access'::text])))
);


--
-- Name: loss_param; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.loss_param (
    param_key text NOT NULL,
    loss_db numeric NOT NULL,
    notes text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: map_note; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.map_note (
    id integer NOT NULL,
    icon text DEFAULT 'note'::text NOT NULL,
    body text DEFAULT ''::text NOT NULL,
    geom topology.geometry(Point,4326),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    fpm_wor text,
    done_at timestamp with time zone,
    CONSTRAINT chk_map_note_fpm_wor CHECK (((fpm_wor IS NULL) OR ((fpm_wor <> ''::text) AND (fpm_wor !~ ','::text))))
);


--
-- Name: map_note_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.map_note_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: map_note_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.map_note_id_seq OWNED BY app.map_note.id;


--
-- Name: map_plan; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.map_plan (
    plan_id bigint NOT NULL,
    entity_type text NOT NULL,
    entity_id text NOT NULL,
    title text DEFAULT 'Site Plan'::text NOT NULL,
    center_lat double precision NOT NULL,
    center_lon double precision NOT NULL,
    zoom integer DEFAULT 16 NOT NULL,
    orientation text DEFAULT 'landscape'::text NOT NULL,
    layers jsonb DEFAULT '[]'::jsonb NOT NULL,
    meta jsonb DEFAULT '{}'::jsonb NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    rendered_png bytea,
    rendered_at timestamp with time zone,
    created_by text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_by text,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_map_plan_entity_type CHECK ((entity_type = ANY (ARRAY['tender'::text, 'tender_option'::text, 'bell_permit'::text, 'tinuum_permit'::text, 'adhoc'::text]))),
    CONSTRAINT chk_map_plan_orientation CHECK ((orientation = ANY (ARRAY['portrait'::text, 'landscape'::text])))
);


--
-- Name: map_plan_plan_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.map_plan_plan_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: map_plan_plan_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.map_plan_plan_id_seq OWNED BY app.map_plan.plan_id;


--
-- Name: messenger_strand; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.messenger_strand (
    id integer NOT NULL,
    from_pole_fid bigint,
    to_pole_fid bigint,
    from_side text NOT NULL,
    from_height text NOT NULL,
    to_side text NOT NULL,
    to_height text NOT NULL,
    status text DEFAULT 'plan'::text NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    is_overlash boolean DEFAULT false NOT NULL,
    tension text DEFAULT 'normal'::text NOT NULL,
    from_anchor_id bigint,
    to_anchor_id bigint,
    geom topology.geometry(LineString,4326),
    fpm_wor text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT messenger_strand_from_endpoint_ck CHECK ((num_nonnulls(from_pole_fid, from_anchor_id) = 1)),
    CONSTRAINT messenger_strand_from_height_check CHECK ((from_height = ANY (ARRAY['top'::text, 'middle'::text, 'bottom'::text]))),
    CONSTRAINT messenger_strand_from_side_check CHECK ((from_side = ANY (ARRAY['road'::text, 'field'::text]))),
    CONSTRAINT messenger_strand_not_self CHECK ((app.strand_endpoint_key(from_pole_fid, from_anchor_id) <> app.strand_endpoint_key(to_pole_fid, to_anchor_id))),
    CONSTRAINT messenger_strand_status_check CHECK ((status = ANY (ARRAY['plan'::text, 'construction'::text, 'active'::text]))),
    CONSTRAINT messenger_strand_tension_check CHECK ((tension = ANY (ARRAY['normal'::text, 'slack_span'::text]))),
    CONSTRAINT messenger_strand_to_endpoint_ck CHECK ((num_nonnulls(to_pole_fid, to_anchor_id) = 1)),
    CONSTRAINT messenger_strand_to_height_check CHECK ((to_height = ANY (ARRAY['top'::text, 'middle'::text, 'bottom'::text]))),
    CONSTRAINT messenger_strand_to_side_check CHECK ((to_side = ANY (ARRAY['road'::text, 'field'::text])))
);


--
-- Name: messenger_strand_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.messenger_strand_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: messenger_strand_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.messenger_strand_id_seq OWNED BY app.messenger_strand.id;


--
-- Name: microduct_type; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.microduct_type (
    id integer NOT NULL,
    name text NOT NULL,
    way_count smallint DEFAULT 1 NOT NULL,
    inner_ducts jsonb DEFAULT '[]'::jsonb NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    has_tracer boolean DEFAULT false NOT NULL,
    is_microduct boolean DEFAULT false NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: microduct_type_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.microduct_type_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: microduct_type_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.microduct_type_id_seq OWNED BY app.microduct_type.id;


--
-- Name: nb_civic_address; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_civic_address (
    civ_id uuid NOT NULL,
    civic_num integer,
    num_suffix text,
    street text,
    st_type text,
    st_dir text,
    community text,
    county text,
    pid text,
    structure text,
    stru_name text,
    add_type text,
    descript text,
    address text NOT NULL,
    modified timestamp with time zone,
    geom topology.geometry(Point,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_civic_address_add; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_civic_address_add (
    add_id uuid DEFAULT gen_random_uuid() NOT NULL,
    civic_num integer,
    num_suffix text,
    street text,
    st_type text,
    community text,
    county text,
    pid text,
    structure text DEFAULT 'Residence'::text NOT NULL,
    address text NOT NULL,
    note text,
    geom topology.geometry(Point,4326) NOT NULL,
    added_by text,
    added_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_civic_address_ignore; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_civic_address_ignore (
    civ_id uuid NOT NULL,
    address text,
    ignored_by text,
    ignored_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_civic_address_move; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_civic_address_move (
    civ_id uuid NOT NULL,
    geom topology.geometry(Point,4326) NOT NULL,
    orig_geom topology.geometry(Point,4326),
    address text,
    moved_by text,
    moved_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_civic_address_stage; Type: TABLE; Schema: app; Owner: -
--

CREATE UNLOGGED TABLE app.nb_civic_address_stage (
    civ_id uuid NOT NULL,
    civic_num integer,
    num_suffix text,
    street text,
    st_type text,
    st_dir text,
    community text,
    county text,
    pid text,
    structure text,
    stru_name text,
    add_type text,
    descript text,
    address text NOT NULL,
    modified timestamp with time zone,
    geom topology.geometry(Point,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_community; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_community (
    id integer NOT NULL,
    community_cd integer,
    name text NOT NULL,
    bell_name text,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    source text DEFAULT 'GeoNB Community Boundaries (SNB, Open Government Licence)'::text NOT NULL,
    loaded_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_community_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.nb_community_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nb_community_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.nb_community_id_seq OWNED BY app.nb_community.id;


--
-- Name: nb_parcel; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_parcel (
    objectid integer NOT NULL,
    pid text NOT NULL,
    feature text,
    county text,
    property_map text,
    last_update date,
    area_m2 double precision,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_parcel_stage; Type: TABLE; Schema: app; Owner: -
--

CREATE UNLOGGED TABLE app.nb_parcel_stage (
    objectid integer NOT NULL,
    pid text NOT NULL,
    feature text,
    county text,
    property_map text,
    last_update date,
    area_m2 double precision,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_railway; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_railway (
    objectid integer NOT NULL,
    track_name text,
    owner text,
    operator text,
    status text,
    geom topology.geometry(MultiLineString,4326) NOT NULL
);


--
-- Name: nb_railway_stage; Type: TABLE; Schema: app; Owner: -
--

CREATE UNLOGGED TABLE app.nb_railway_stage (
    objectid integer NOT NULL,
    track_name text,
    owner text,
    operator text,
    status text,
    geom topology.geometry(MultiLineString,4326) NOT NULL
);


--
-- Name: nb_wetland; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_wetland (
    objectid integer NOT NULL,
    wc text,
    wetland_class text,
    hectares double precision,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_wetland_psw; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.nb_wetland_psw (
    objectid integer NOT NULL,
    wc text,
    wetland_class text,
    hectares double precision,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_wetland_psw_stage; Type: TABLE; Schema: app; Owner: -
--

CREATE UNLOGGED TABLE app.nb_wetland_psw_stage (
    objectid integer NOT NULL,
    wc text,
    wetland_class text,
    hectares double precision,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nb_wetland_stage; Type: TABLE; Schema: app; Owner: -
--

CREATE UNLOGGED TABLE app.nb_wetland_stage (
    objectid integer NOT NULL,
    wc text,
    wetland_class text,
    hectares double precision,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: nbp_work_order_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.nbp_work_order_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ont_association; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.ont_association (
    ont_association_id bigint NOT NULL,
    uisp_device_id uuid NOT NULL,
    fosc_id bigint NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    route_geom topology.geometry(LineString,4326),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: ont_association_ont_association_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.ont_association_ont_association_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ont_association_ont_association_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.ont_association_ont_association_id_seq OWNED BY app.ont_association.ont_association_id;


--
-- Name: org_setting; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.org_setting (
    key text NOT NULL,
    value text NOT NULL
);


--
-- Name: otdr_result; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.otdr_result (
    otdr_result_id integer NOT NULL,
    fosc_id bigint,
    cable_span_id bigint,
    fiber_num smallint,
    linked_at timestamp with time zone,
    linked_by bigint,
    uploaded_by bigint,
    uploaded_at timestamp with time zone DEFAULT now() NOT NULL,
    filename text NOT NULL,
    file_size integer NOT NULL,
    raw_file bytea NOT NULL,
    tested_at timestamp with time zone,
    wavelength_nm smallint,
    fiber_length_m real,
    total_loss_db real,
    supplier text,
    model text,
    parsed_meta jsonb,
    events jsonb,
    trace_pts jsonb,
    file_hash text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: otdr_result_otdr_result_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.otdr_result_otdr_result_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: otdr_result_otdr_result_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.otdr_result_otdr_result_id_seq OWNED BY app.otdr_result.otdr_result_id;


--
-- Name: parcel_owner; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.parcel_owner (
    pid text NOT NULL,
    owner text NOT NULL,
    as_of date,
    source text,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT parcel_owner_owner_check CHECK ((btrim(owner) <> ''::text)),
    CONSTRAINT parcel_owner_pid_check CHECK ((pid ~ '^[0-9]{8}$'::text))
);


--
-- Name: plant_build_log_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.plant_build_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plant_build_undo_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.plant_build_undo_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pole; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.pole (
    fid bigint NOT NULL,
    geom topology.geometry(Point,4326),
    id bigint,
    type character varying(80),
    uid character varying(10),
    owner character varying(15) DEFAULT 'Unknown'::character varying NOT NULL,
    comment character varying(254),
    fpm_wor character varying(80),
    "nbp#" character varying(32),
    "telco#" character varying(18),
    "tags#" character varying(30),
    pole_class text DEFAULT '2'::text NOT NULL,
    street text DEFAULT ''::text NOT NULL,
    alden_pole_id text,
    alden_pole_tag text,
    alden_owner_raw text,
    alden_location text,
    alden_status text,
    alden_telco text,
    alden_power_number text,
    alden_lat double precision,
    alden_lon double precision,
    source text DEFAULT 'user'::text NOT NULL,
    identity_status text GENERATED ALWAYS AS (
CASE
    WHEN (("nbp#")::text ~ '^[0-9]{4}[A-Z0-9]*_'::text) THEN 'numbered'::text
    WHEN (alden_pole_id IS NOT NULL) THEN 'alden_no_number'::text
    ELSE 'unverified'::text
END) STORED,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_pole_nbp_not_blank CHECK ((("nbp#" IS NULL) OR (btrim(("nbp#")::text) <> ''::text))),
    CONSTRAINT chk_pole_source CHECK ((source = ANY (ARRAY['user'::text, 'alden'::text])))
);


--
-- Name: pole_fid_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.pole_fid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pole_fid_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.pole_fid_seq OWNED BY app.pole.fid;


--
-- Name: pole_owner; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.pole_owner (
    owner character varying(15) NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: pole_work_order; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.pole_work_order (
    id integer NOT NULL,
    pole_fid bigint NOT NULL,
    work_order text NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT pole_work_order_work_order_check CHECK ((work_order <> ''::text))
);


--
-- Name: pole_work_order_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.pole_work_order_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pole_work_order_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.pole_work_order_id_seq OWNED BY app.pole_work_order.id;


--
-- Name: pop; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.pop (
    pop_id bigint NOT NULL,
    name text NOT NULL,
    notes text,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    location topology.geometry(Point,4326),
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    lnd_status text,
    CONSTRAINT pop_lnd_status_check CHECK ((lnd_status = ANY (ARRAY['existing'::text, 'upgraded'::text, 'new'::text])))
);


--
-- Name: pop_pop_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

ALTER TABLE app.pop ALTER COLUMN pop_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME app.pop_pop_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: road_coverage; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.road_coverage (
    osm_way_id bigint NOT NULL,
    road_name text,
    highway_type text,
    geom topology.geometry(LineString,4326) NOT NULL,
    updated_at timestamp with time zone DEFAULT now(),
    seg_id integer NOT NULL,
    in_boundary boolean DEFAULT true NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: road_coverage_seg_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.road_coverage_seg_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: road_coverage_seg_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.road_coverage_seg_id_seq OWNED BY app.road_coverage.seg_id;


--
-- Name: road_coverage_service; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.road_coverage_service (
    id integer NOT NULL,
    provider text NOT NULL,
    technology text NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now(),
    seg_id integer NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT road_coverage_service_provider_check CHECK ((provider <> ''::text)),
    CONSTRAINT road_coverage_service_technology_check CHECK ((technology = ANY (ARRAY['fiber_ftth'::text, 'hybrid_coax'::text, 'fiber_mainline'::text, 'copper_dsl'::text, 'no_telecom'::text, 'fiber_coming_soon'::text, 'fiber_expansion'::text])))
);


--
-- Name: road_coverage_service_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.road_coverage_service_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: road_coverage_service_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.road_coverage_service_id_seq OWNED BY app.road_coverage_service.id;


--
-- Name: route_coincidence_cache; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.route_coincidence_cache (
    kind text NOT NULL,
    id bigint NOT NULL,
    v bigint NOT NULL,
    payload jsonb NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT route_coincidence_cache_kind_check CHECK ((kind = ANY (ARRAY['strand'::text, 'span'::text])))
);


--
-- Name: route_coincidence_version; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.route_coincidence_version (
    id integer NOT NULL,
    v bigint DEFAULT 0 NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT route_coincidence_version_id_check CHECK ((id = 1))
);


--
-- Name: salisbury_zoning; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.salisbury_zoning (
    id integer NOT NULL,
    layer text NOT NULL,
    src_oid integer,
    name text,
    code text,
    link text,
    props jsonb DEFAULT '{}'::jsonb NOT NULL,
    geom topology.geometry(MultiPolygon,4326) NOT NULL,
    loaded_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT salisbury_zoning_layer_check CHECK ((layer = ANY (ARRAY['zoning'::text, 'infill'::text, 'serviceable'::text])))
);


--
-- Name: salisbury_zoning_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.salisbury_zoning_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: salisbury_zoning_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.salisbury_zoning_id_seq OWNED BY app.salisbury_zoning.id;


--
-- Name: schema_migrations; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.schema_migrations (
    version text NOT NULL,
    applied_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: service_association; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.service_association (
    service_association_id bigint NOT NULL,
    crm_service_id bigint NOT NULL,
    crm_client_id bigint NOT NULL,
    fosc_id bigint NOT NULL,
    notes text,
    route_geom topology.geometry(LineString,4326),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: service_association_service_association_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.service_association_service_association_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: service_association_service_association_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.service_association_service_association_id_seq OWNED BY app.service_association.service_association_id;


--
-- Name: sheath_length; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.sheath_length (
    sheath_length_id bigint NOT NULL,
    cable_span_id bigint,
    measure_m numeric(12,3) NOT NULL,
    geom topology.geometry(Point,4326) NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: sheath_length_sheath_length_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.sheath_length_sheath_length_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sheath_length_sheath_length_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.sheath_length_sheath_length_id_seq OWNED BY app.sheath_length.sheath_length_id;


--
-- Name: ship_party_party_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.ship_party_party_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: snmp_metric_snmp_metric_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.snmp_metric_snmp_metric_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: span_tag; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.span_tag (
    span_tag_id bigint NOT NULL,
    name character varying(24) NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: span_tag_span_tag_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.span_tag_span_tag_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: span_tag_span_tag_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.span_tag_span_tag_id_seq OWNED BY app.span_tag.span_tag_id;


--
-- Name: splice_dummy_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.splice_dummy_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: splice_join_splice_join_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.splice_join_splice_join_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: splice_join_splice_join_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.splice_join_splice_join_id_seq OWNED BY app.splice_join.splice_join_id;


--
-- Name: static_customer; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.static_customer (
    static_customer_id bigint NOT NULL,
    name text NOT NULL,
    mac_address text NOT NULL,
    duid text DEFAULT '0x00030001AABBCCDDEEFF'::text NOT NULL,
    iaid text DEFAULT '1'::text NOT NULL,
    static_ipv4 inet NOT NULL,
    ipv6_prefix cidr NOT NULL,
    vlan smallint DEFAULT 233 NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    uisp_site_id text,
    check_status text DEFAULT 'unchecked'::text NOT NULL,
    check_detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    last_checked_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    router_ip text DEFAULT '192.168.40.1'::text NOT NULL,
    router_login text DEFAULT 'ubnt'::text NOT NULL,
    router_password text DEFAULT ''::text NOT NULL,
    wifi_ssid text DEFAULT ''::text NOT NULL,
    wifi_password text DEFAULT ''::text NOT NULL,
    credentials_generated_at timestamp with time zone,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT static_customer_check_status_check CHECK ((check_status = ANY (ARRAY['unchecked'::text, 'ok'::text, 'mismatch'::text, 'unknown'::text])))
);


--
-- Name: static_customer_static_customer_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.static_customer_static_customer_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: static_customer_static_customer_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.static_customer_static_customer_id_seq OWNED BY app.static_customer.static_customer_id;


--
-- Name: survey_draft; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.survey_draft (
    id integer NOT NULL,
    plan_no text NOT NULL,
    plan_date date NOT NULL,
    status text DEFAULT 'tentative'::text NOT NULL,
    label text NOT NULL,
    notes text,
    markers jsonb DEFAULT '[]'::jsonb NOT NULL,
    geom topology.geometry(LineString,4326) NOT NULL,
    fpm_wor text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT survey_draft_status_check CHECK ((status = ANY (ARRAY['tentative'::text, 'final'::text, 'superseded'::text])))
);


--
-- Name: survey_draft_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.survey_draft_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_draft_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.survey_draft_id_seq OWNED BY app.survey_draft.id;


--
-- Name: timeline_entry; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.timeline_entry (
    timeline_entry_id bigint NOT NULL,
    parent_type text NOT NULL,
    parent_id text NOT NULL,
    body_text text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    geom topology.geometry(Point,4326),
    meta jsonb DEFAULT '{}'::jsonb NOT NULL,
    body_tsv tsvector,
    fosc_id bigint,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: timeline_entry_timeline_entry_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.timeline_entry_timeline_entry_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: timeline_entry_timeline_entry_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.timeline_entry_timeline_entry_id_seq OWNED BY app.timeline_entry.timeline_entry_id;


--
-- Name: timeline_media; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.timeline_media (
    timeline_media_id bigint NOT NULL,
    timeline_entry_id bigint NOT NULL,
    blob bytea NOT NULL,
    mime_type text NOT NULL,
    file_name text,
    file_size bigint GENERATED ALWAYS AS (octet_length(blob)) STORED,
    width integer,
    height integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    exif_created_at timestamp without time zone,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_media_is_image CHECK ((mime_type ~~* 'image/%'::text)),
    CONSTRAINT chk_media_max_20mb CHECK ((octet_length(blob) <= ((20 * 1024) * 1024)))
);


--
-- Name: timeline_media_timeline_media_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.timeline_media_timeline_media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: timeline_media_timeline_media_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.timeline_media_timeline_media_id_seq OWNED BY app.timeline_media.timeline_media_id;


--
-- Name: uguard; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.uguard (
    id integer NOT NULL,
    pole_fid bigint,
    status app.anchor_status DEFAULT 'plan'::app.anchor_status NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    fpm_wor text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: uguard_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.uguard_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uguard_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.uguard_id_seq OWNED BY app.uguard.id;


--
-- Name: uisp_cache; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.uisp_cache (
    endpoint text NOT NULL,
    data jsonb NOT NULL,
    fetched_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL
);


--
-- Name: uisp_client_gps_log; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.uisp_client_gps_log (
    log_id bigint NOT NULL,
    crm_client_id integer NOT NULL,
    client_name text,
    old_lat double precision,
    old_lon double precision,
    new_lat double precision NOT NULL,
    new_lon double precision NOT NULL,
    actor text,
    moved_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: uisp_client_gps_log_log_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.uisp_client_gps_log_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uisp_client_gps_log_log_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.uisp_client_gps_log_log_id_seq OWNED BY app.uisp_client_gps_log.log_id;


--
-- Name: uisp_common_event; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.uisp_common_event (
    common_event_id bigint NOT NULL,
    started_at timestamp with time zone NOT NULL,
    ended_at timestamp with time zone NOT NULL,
    device_count integer NOT NULL,
    event_count integer DEFAULT 0 NOT NULL,
    sites text[] DEFAULT '{}'::text[] NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    kind text DEFAULT 'outage'::text NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_uisp_common_event_kind CHECK ((kind = ANY (ARRAY['outage'::text, 'nms_reconnect'::text, 'local'::text])))
);


--
-- Name: uisp_device_event; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.uisp_device_event (
    uisp_log_id uuid NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    device_id uuid,
    device_name text DEFAULT ''::text NOT NULL,
    device_mac text,
    device_model text,
    device_type text,
    site_id uuid,
    site_name text DEFAULT ''::text NOT NULL,
    kind text NOT NULL,
    level text DEFAULT 'info'::text NOT NULL,
    message text DEFAULT ''::text NOT NULL,
    tags text[] DEFAULT '{}'::text[] NOT NULL,
    common_event_id bigint,
    ingested_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    CONSTRAINT chk_uisp_event_kind CHECK ((kind = ANY (ARRAY['disconnect'::text, 'connect'::text, 'restart'::text, 'pon_down'::text, 'pon_up'::text, 'upgrade'::text, 'other'::text])))
);


--
-- Name: uisp_mass_event_mass_event_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.uisp_mass_event_mass_event_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uisp_mass_event_mass_event_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.uisp_mass_event_mass_event_id_seq OWNED BY app.uisp_common_event.common_event_id;


--
-- Name: v_cable_span_edit; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_cable_span_edit AS
 SELECT s.cable_span_id,
    s.cable_id,
    s.from_fosc_id,
    s.to_fosc_id,
    s.notes,
    s.geom_middle AS geom,
    s.geom_snap,
    f1.splice_num AS from_fosc_label,
    f2.splice_num AS to_fosc_label
   FROM ((app.cable_span s
     JOIN app.fosc f1 ON ((f1.fosc_id = s.from_fosc_id)))
     JOIN app.fosc f2 ON ((f2.fosc_id = s.to_fosc_id)));


--
-- Name: v_cable_span_support; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_cable_span_support AS
 SELECT s.cable_span_id,
    s.cable_id,
    c.name AS cable,
    c.fibre_count,
    s.fpm_wor,
    round((topology.st_length(topology.st_transform(s.geom_snap, 2953)))::numeric) AS length_m,
    ( SELECT count(*) AS count
           FROM app.cable_span_strand l
          WHERE (l.cable_span_id = s.cable_span_id)) AS strands,
    ( SELECT count(*) AS count
           FROM app.cable_span_conduit l
          WHERE (l.cable_span_id = s.cable_span_id)) AS conduits,
        CASE
            WHEN (s.installation = 'direct_buried'::text) THEN (0)::numeric
            ELSE round((COALESCE(topology.st_length(topology.st_difference(topology.st_transform(s.geom_snap, 2953), sup.u)), topology.st_length(topology.st_transform(s.geom_snap, 2953))))::numeric)
        END AS uncovered_m,
        CASE
            WHEN (s.installation = 'direct_buried'::text) THEN 'buried'::text
            WHEN (sup.u IS NULL) THEN
            CASE
                WHEN (topology.st_length(topology.st_transform(s.geom_snap, 2953)) < (15)::double precision) THEN 'short'::text
                ELSE 'unsupported'::text
            END
            WHEN (COALESCE(topology.st_length(topology.st_difference(topology.st_transform(s.geom_snap, 2953), sup.u)), (0)::double precision) <= GREATEST((10)::double precision, ((0.03)::double precision * topology.st_length(topology.st_transform(s.geom_snap, 2953))))) THEN 'supported'::text
            WHEN (topology.st_length(topology.st_transform(s.geom_snap, 2953)) < (15)::double precision) THEN 'short'::text
            ELSE 'partial'::text
        END AS support,
    s.installation
   FROM ((app.cable_span s
     JOIN app.cable c ON ((c.cable_id = s.cable_id)))
     LEFT JOIN LATERAL ( SELECT topology.st_union(x.b) AS u
           FROM ( SELECT topology.st_buffer(topology.st_transform(ms.geom, 2953), (5)::double precision) AS b
                   FROM (app.cable_span_strand l
                     JOIN app.messenger_strand ms ON ((ms.id = l.strand_id)))
                  WHERE (l.cable_span_id = s.cable_span_id)
                UNION ALL
                 SELECT topology.st_buffer(topology.st_transform(k.geom, 2953), (5)::double precision) AS st_buffer
                   FROM (app.cable_span_conduit l
                     JOIN app.conduit k ON ((k.conduit_id = l.conduit_id)))
                  WHERE (l.cable_span_id = s.cable_span_id)) x) sup ON (true))
  WHERE (s.geom_snap IS NOT NULL);


--
-- Name: v_change_coverage; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_change_coverage AS
 SELECT (((n.nspname)::text || '.'::text) || (c.relname)::text) AS table_name,
    (EXISTS ( SELECT 1
           FROM pg_trigger g
          WHERE ((g.tgrelid = c.oid) AND (g.tgfoid = ('app.audit_row_change'::regproc)::oid) AND (NOT g.tgisinternal)))) AS audited,
    (e.table_name IS NOT NULL) AS exempt,
    e.reason AS exempt_reason,
    (EXISTS ( SELECT 1
           FROM pg_attribute a
          WHERE ((a.attrelid = c.oid) AND (a.attname = 'created_change'::name) AND (NOT a.attisdropped)))) AS stamped
   FROM ((pg_class c
     JOIN pg_namespace n ON ((n.oid = c.relnamespace)))
     LEFT JOIN app.audit_exempt e ON ((e.table_name = c.relname)))
  WHERE ((n.nspname = ANY (ARRAY['app'::name, 'nda'::name])) AND (c.relkind = 'r'::"char"));


--
-- Name: v_change_history; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_change_history AS
 SELECT l.audit_log_id,
    l.happened_at,
    l.change_id,
    cs.actor_code,
    a.display_name AS actor,
    cs.source,
    cs.note,
    l.action,
    l.table_name,
    l.row_pk
   FROM ((app.audit_log l
     JOIN app.change_set cs ON ((cs.change_id = l.change_id)))
     JOIN app.actor a ON ((a.actor_code = cs.actor_code)));


--
-- Name: vault; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.vault (
    vault_id integer NOT NULL,
    name text DEFAULT ''::text NOT NULL,
    type app.vault_type DEFAULT 'vault'::app.vault_type NOT NULL,
    status app.vault_status DEFAULT 'plan'::app.vault_status NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    geom topology.geometry(Point,4326),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    fpm_wor text,
    owner text,
    owner_ref text,
    alden_vault_id text,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    model_id integer,
    model_other text
);


--
-- Name: v_closure_placement_issues; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_closure_placement_issues AS
 SELECT fosc_id,
    splice_num,
    status,
    fpm_wor,
    ( SELECT round((min(topology.st_distance((p.geom)::topology.geography, (f.location)::topology.geography)))::numeric, 1) AS round
           FROM app.pole p
          WHERE topology.st_dwithin((p.geom)::topology.geography, (f.location)::topology.geography, (100)::double precision)) AS nearest_pole_m,
    ( SELECT round((min(topology.st_distance((v.geom)::topology.geography, (f.location)::topology.geography)))::numeric, 1) AS round
           FROM app.vault v
          WHERE topology.st_dwithin((v.geom)::topology.geography, (f.location)::topology.geography, (100)::double precision)) AS nearest_vault_m
   FROM app.fosc f
  WHERE ((kind = 'closure'::app.fosc_kind) AND (NOT app.closure_is_housed(location, attrs)));


--
-- Name: v_expansion_overbuild; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_expansion_overbuild AS
 SELECT seg_id,
    road_name,
    kind,
    len_m,
    competitors,
    run_id,
    run_m,
    is_overbuild
   FROM app.expansion_overbuild((300)::numeric) expansion_overbuild(seg_id, road_name, kind, len_m, competitors, run_id, run_m, is_overbuild);


--
-- Name: v_fosc_device_edges; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_fosc_device_edges AS
 SELECT d.fosc_id,
    'fosc_device'::app.join_target AS from_target,
    d.fosc_device_id AS from_id,
    'device'::app.join_side AS from_end,
    0 AS from_port,
    'fosc_device'::app.join_target AS to_target,
    d.fosc_device_id AS to_id,
    'device'::app.join_side AS to_end,
    gs.port AS to_port,
    'device_internal'::text AS edge_type
   FROM (app.fosc_device d
     JOIN LATERAL ( SELECT generate_series(1, ((d.attrs ->> 'outs'::text))::integer) AS port
          WHERE (d.device_type = 'splitter'::text)) gs ON (true));


--
-- Name: v_fosc_device_edit; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_fosc_device_edit AS
 SELECT fosc_device_id,
    fosc_id,
    device_type,
    name,
    attrs,
    notes,
    location AS geom
   FROM app.fosc_device fd;


--
-- Name: v_fosc_location; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_fosc_location AS
 WITH nearest_per_road AS (
         SELECT f_1.fosc_id,
            r.road_name,
            topology.st_distance((f_1.location)::topology.geography, (r.geom)::topology.geography) AS d,
            row_number() OVER (PARTITION BY f_1.fosc_id, r.road_name ORDER BY (topology.st_distance((f_1.location)::topology.geography, (r.geom)::topology.geography))) AS rn
           FROM (app.fosc f_1
             JOIN app.road_coverage r ON (topology.st_dwithin((f_1.location)::topology.geography, (r.geom)::topology.geography, (150)::double precision)))
          WHERE ((r.road_name IS NOT NULL) AND (r.road_name <> ''::text))
        ), ranked AS (
         SELECT nearest_per_road.fosc_id,
            nearest_per_road.road_name,
            nearest_per_road.d,
            row_number() OVER (PARTITION BY nearest_per_road.fosc_id ORDER BY nearest_per_road.d) AS rk
           FROM nearest_per_road
          WHERE (nearest_per_road.rn = 1)
        ), picked AS (
         SELECT ranked.fosc_id,
            max(ranked.road_name) FILTER (WHERE (ranked.rk = 1)) AS on_road,
            max(ranked.d) FILTER (WHERE (ranked.rk = 1)) AS on_road_m,
            max(ranked.road_name) FILTER (WHERE ((ranked.rk = 2) AND (ranked.d <= (40)::double precision))) AS cross_road,
            max(ranked.d) FILTER (WHERE ((ranked.rk = 2) AND (ranked.d <= (40)::double precision))) AS cross_road_m
           FROM ranked
          GROUP BY ranked.fosc_id
        )
 SELECT f.fosc_id,
    f.splice_num,
    f.civic_address,
    p.on_road,
    round((p.on_road_m)::numeric, 0) AS on_road_m,
    p.cross_road,
    round((p.cross_road_m)::numeric, 0) AS cross_road_m,
    COALESCE(f.civic_address,
        CASE
            WHEN (p.on_road IS NULL) THEN NULL::text
            WHEN (p.cross_road IS NULL) THEN p.on_road
            ELSE ((p.on_road || ' at '::text) || p.cross_road)
        END) AS where_label
   FROM (app.fosc f
     LEFT JOIN picked p ON ((p.fosc_id = f.fosc_id)));


--
-- Name: v_fosc_qgis; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_fosc_qgis AS
 SELECT f.fosc_id,
    f.splice_num,
    (f.status)::text AS status,
    ct.name AS closure_type_name,
    f.notes,
    f.created_at,
    f.updated_at,
    f.location AS geom,
    f.kind,
    f.attrs
   FROM (app.fosc f
     JOIN app.closure_type ct ON ((ct.closure_type_id = f.closure_type_id)));


--
-- Name: v_hidden_asset; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_hidden_asset AS
 WITH live AS (
         SELECT fpm_permit.permit_num
           FROM app.fpm_permit
          WHERE (fpm_permit.abandoned_at IS NULL)
        ), gone AS (
         SELECT fpm_permit.permit_num
           FROM app.fpm_permit
          WHERE (fpm_permit.abandoned_at IS NOT NULL)
        )
 SELECT 'pole'::text AS kind,
    p.fid AS id
   FROM app.pole p
  WHERE ((EXISTS ( SELECT 1
           FROM app.pole_work_order w
          WHERE (w.pole_fid = p.fid))) AND (NOT (EXISTS ( SELECT 1
           FROM (app.pole_work_order w
             JOIN live l ON ((l.permit_num = w.work_order)))
          WHERE (w.pole_fid = p.fid)))))
UNION ALL
 SELECT 'fosc'::text AS kind,
    f.fosc_id AS id
   FROM app.fosc f
  WHERE ((EXISTS ( SELECT 1
           FROM app.fosc_work_order w
          WHERE (w.fosc_id = f.fosc_id))) AND (NOT (EXISTS ( SELECT 1
           FROM (app.fosc_work_order w
             JOIN live l ON ((l.permit_num = w.work_order)))
          WHERE (w.fosc_id = f.fosc_id)))))
UNION ALL
 SELECT 'span'::text AS kind,
    s.cable_span_id AS id
   FROM app.cable_span s
  WHERE ((EXISTS ( SELECT 1
           FROM app.cable_span_work_order w
          WHERE (w.cable_span_id = s.cable_span_id))) AND (NOT (EXISTS ( SELECT 1
           FROM (app.cable_span_work_order w
             JOIN live l ON ((l.permit_num = w.work_order)))
          WHERE (w.cable_span_id = s.cable_span_id)))))
UNION ALL
 SELECT 'conduit'::text AS kind,
    c.conduit_id AS id
   FROM app.conduit c
  WHERE ((EXISTS ( SELECT 1
           FROM app.conduit_work_order w
          WHERE (w.conduit_id = c.conduit_id))) AND (NOT (EXISTS ( SELECT 1
           FROM (app.conduit_work_order w
             JOIN live l ON ((l.permit_num = w.work_order)))
          WHERE (w.conduit_id = c.conduit_id)))))
UNION ALL
 SELECT 'strand'::text AS kind,
    x.id
   FROM (app.messenger_strand x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'vault'::text AS kind,
    x.vault_id AS id
   FROM (app.vault x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'uguard'::text AS kind,
    x.id
   FROM (app.uguard x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'building_anchor'::text AS kind,
    x.id
   FROM (app.building_anchor x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'map_note'::text AS kind,
    x.id
   FROM (app.map_note x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'dimension'::text AS kind,
    x.id
   FROM (app.dimension x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'easement'::text AS kind,
    x.id
   FROM (app.easement x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'survey_draft'::text AS kind,
    x.id
   FROM (app.survey_draft x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)))
UNION ALL
 SELECT 'conduit_termination'::text AS kind,
    x.termination_id AS id
   FROM (app.conduit_termination x
     JOIN gone g ON ((g.permit_num = x.fpm_wor)));


--
-- Name: v_hidden_splice_join; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_hidden_splice_join AS
 SELECT splice_join_id
   FROM app.splice_join j
  WHERE (((a_target = 'cable_span'::app.join_target) AND (a_span_id IN ( SELECT v_hidden_asset.id
           FROM app.v_hidden_asset
          WHERE (v_hidden_asset.kind = 'span'::text)))) OR ((b_target = 'cable_span'::app.join_target) AND (b_span_id IN ( SELECT v_hidden_asset.id
           FROM app.v_hidden_asset
          WHERE (v_hidden_asset.kind = 'span'::text)))) OR (fosc_id IN ( SELECT v_hidden_asset.id
           FROM app.v_hidden_asset
          WHERE (v_hidden_asset.kind = 'fosc'::text))));


--
-- Name: v_nb_civic_address; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_nb_civic_address AS
 SELECT a.civ_id,
    a.civic_num,
    a.num_suffix,
    a.street,
    a.st_type,
    a.st_dir,
    a.community,
    a.county,
    a.pid,
    a.structure,
    a.stru_name,
    a.add_type,
    a.descript,
    a.address,
    a.modified,
    COALESCE(m.geom, a.geom) AS geom,
    (m.civ_id IS NOT NULL) AS moved,
    a.geom AS geonb_geom,
    false AS added
   FROM (app.nb_civic_address a
     LEFT JOIN app.nb_civic_address_move m ON ((m.civ_id = a.civ_id)))
  WHERE (NOT (EXISTS ( SELECT 1
           FROM app.nb_civic_address_ignore i
          WHERE (i.civ_id = a.civ_id))))
UNION ALL
 SELECT d.add_id AS civ_id,
    d.civic_num,
    d.num_suffix,
    d.street,
    d.st_type,
    NULL::text AS st_dir,
    d.community,
    d.county,
    d.pid,
    d.structure,
    NULL::text AS stru_name,
    ('Added by '::text || app.own_name()) AS add_type,
    d.note AS descript,
    d.address,
    d.added_at AS modified,
    d.geom,
    false AS moved,
    NULL::topology.geometry(Point,4326) AS geonb_geom,
    true AS added
   FROM app.nb_civic_address_add d;


--
-- Name: v_pole_alden_conflict; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_pole_alden_conflict AS
 SELECT fid,
    alden_pole_id,
    "telco#" AS your_telco,
    alden_telco,
    "nbp#" AS your_nbp,
    alden_power_number,
    owner AS your_owner,
        CASE alden_owner_raw
            WHEN 'Bell Canada'::text THEN 'Bell Aliant'::text
            WHEN 'NB Power'::text THEN 'NB Power'::text
            ELSE 'Unknown'::text
        END AS alden_owner,
    alden_owner_raw,
    round((topology.st_distancesphere(geom, topology.st_setsrid(topology.st_makepoint(alden_lon, alden_lat), 4326)))::numeric, 1) AS geom_gap_m,
    street
   FROM app.pole p
  WHERE ((alden_pole_id IS NOT NULL) AND (alden_lat IS NOT NULL) AND ((topology.st_distancesphere(geom, topology.st_setsrid(topology.st_makepoint(alden_lon, alden_lat), 4326)) > (5)::double precision) OR (("telco#")::text IS DISTINCT FROM alden_telco) OR (("nbp#")::text IS DISTINCT FROM alden_power_number) OR ((owner)::text IS DISTINCT FROM
        CASE alden_owner_raw
            WHEN 'Bell Canada'::text THEN 'Bell Aliant'::text
            WHEN 'NB Power'::text THEN 'NB Power'::text
            ELSE 'Unknown'::text
        END)));


--
-- Name: v_pole_field_check; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_pole_field_check AS
 SELECT fid,
    identity_status,
    owner,
    "nbp#" AS number_or_label,
    street,
    fpm_wor,
    (topology.st_y(geom))::numeric(9,6) AS lat,
    (topology.st_x(geom))::numeric(9,6) AS lon,
    ( SELECT count(*) AS count
           FROM app.messenger_strand s
          WHERE ((s.from_pole_fid = p.fid) OR (s.to_pole_fid = p.fid))) AS strands,
    concat_ws(' + '::text,
        CASE
            WHEN (identity_status <> 'numbered'::text) THEN 'pole number'::text
            ELSE NULL::text
        END,
        CASE
            WHEN ((owner)::text = 'Unknown'::text) THEN 'owner'::text
            ELSE NULL::text
        END) AS needs
   FROM app.pole p
  WHERE ((identity_status <> 'numbered'::text) OR ((owner)::text = 'Unknown'::text));


--
-- Name: v_pole_possible_duplicate; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_pole_possible_duplicate AS
 SELECT a.fid AS fid_a,
    a."nbp#" AS nbp_a,
    a.owner AS owner_a,
    a.fpm_wor AS wor_a,
    b.fid AS fid_b,
    b."nbp#" AS nbp_b,
    b.owner AS owner_b,
    b.fpm_wor AS wor_b,
    round((topology.st_distance((a.geom)::topology.geography, (b.geom)::topology.geography))::numeric, 2) AS distance_m
   FROM (app.pole a
     JOIN app.pole b ON (((a.fid < b.fid) AND topology.st_dwithin((a.geom)::topology.geography, (b.geom)::topology.geography, (1)::double precision))))
  WHERE ((a."nbp#" IS NULL) OR ((a."nbp#")::text = ''::text) OR ((a."nbp#")::text !~ '^[0-9]{4}[A-Z0-9]*_'::text) OR (b."nbp#" IS NULL) OR ((b."nbp#")::text = ''::text) OR ((b."nbp#")::text !~ '^[0-9]{4}[A-Z0-9]*_'::text));


--
-- Name: v_pole_qgis; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_pole_qgis AS
 SELECT fid,
    uid,
    type,
    owner,
    comment,
    fpm_wor AS work_order,
    "nbp#" AS nbp_num,
    "telco#" AS telco_num,
    "tags#" AS tags_num,
    geom
   FROM app.pole p;


--
-- Name: v_span_endpoints; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_span_endpoints AS
 SELECT s.cable_span_id,
    'from'::app.span_end AS "end",
    s.from_fosc_id AS fosc_id,
    f.splice_num AS fosc_label,
    ((s.cable_span_id)::text || '-from'::text) AS endpoint_key,
    topology.st_startpoint(s.geom) AS geom
   FROM (app.cable_span s
     JOIN app.fosc f ON ((f.fosc_id = s.from_fosc_id)))
UNION ALL
 SELECT s.cable_span_id,
    'to'::app.span_end AS "end",
    s.to_fosc_id AS fosc_id,
    f.splice_num AS fosc_label,
    ((s.cable_span_id)::text || '-to'::text) AS endpoint_key,
    topology.st_endpoint(s.geom) AS geom
   FROM (app.cable_span s
     JOIN app.fosc f ON ((f.fosc_id = s.to_fosc_id)));


--
-- Name: v_splice_edges; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_splice_edges AS
 SELECT sj.fosc_id,
    sj.a_target AS from_target,
    sj.a_span_id AS from_id,
    sj.a_end AS from_end,
    fa.fa AS from_port,
    sj.b_target AS to_target,
    sj.b_span_id AS to_id,
    sj.b_end AS to_end,
    fb.fb AS to_port,
    'splice'::text AS edge_type
   FROM ((app.splice_join sj
     JOIN LATERAL app.range_series(sj.a_range) fa(fa) ON (true))
     JOIN LATERAL app.range_series(sj.b_range) fb(fb) ON (((fb.fb - lower(sj.b_range)) = (fa.fa - lower(sj.a_range)))));


--
-- Name: v_uisp_device_health; Type: VIEW; Schema: app; Owner: -
--

CREATE VIEW app.v_uisp_device_health AS
 SELECT device_id,
    (array_agg(device_name ORDER BY occurred_at DESC))[1] AS device_name,
    (array_agg(site_name ORDER BY occurred_at DESC))[1] AS site_name,
    (array_agg(site_id ORDER BY occurred_at DESC))[1] AS site_id,
    (array_agg(device_mac ORDER BY occurred_at DESC))[1] AS device_mac,
    (array_agg(device_model ORDER BY occurred_at DESC))[1] AS device_model,
    (array_agg(device_type ORDER BY occurred_at DESC))[1] AS device_type,
    count(*) FILTER (WHERE (kind = 'disconnect'::text)) AS disconnects,
    count(*) FILTER (WHERE (kind = 'restart'::text)) AS restarts,
    count(*) FILTER (WHERE (kind = 'pon_down'::text)) AS pon_downs,
    count(*) AS score_events,
    max(occurred_at) AS last_event_at
   FROM app.uisp_device_event e
  WHERE ((device_id IS NOT NULL) AND (common_event_id IS NULL) AND (kind = ANY (ARRAY['disconnect'::text, 'restart'::text, 'pon_down'::text])) AND (occurred_at > (now() - '30 days'::interval)))
  GROUP BY device_id;


--
-- Name: vault_vault_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.vault_vault_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vault_vault_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.vault_vault_id_seq OWNED BY app.vault.vault_id;


--
-- Name: wifi_ap; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.wifi_ap (
    id integer NOT NULL,
    project text DEFAULT 'Salisbury Muni WiFi'::text NOT NULL,
    name text NOT NULL,
    model text NOT NULL,
    status text DEFAULT 'plan'::text NOT NULL,
    geom topology.geometry(Point,4326) NOT NULL,
    azimuth_deg real,
    beam_deg real,
    range_m real,
    unifi_mac text,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_change text DEFAULT 'LEGC_0'::text NOT NULL,
    updated_change text DEFAULT 'LEGC_0'::text NOT NULL,
    fpm_wor text,
    CONSTRAINT chk_wifi_ap_fpm_wor CHECK (((fpm_wor IS NULL) OR ((fpm_wor <> ''::text) AND (fpm_wor !~ ','::text)))),
    CONSTRAINT wifi_ap_azimuth_deg_check CHECK (((azimuth_deg IS NULL) OR ((azimuth_deg >= (0)::double precision) AND (azimuth_deg < (360)::double precision)))),
    CONSTRAINT wifi_ap_beam_deg_check CHECK (((beam_deg IS NULL) OR ((beam_deg > (0)::double precision) AND (beam_deg <= (360)::double precision)))),
    CONSTRAINT wifi_ap_range_m_check CHECK (((range_m IS NULL) OR (range_m > (0)::double precision))),
    CONSTRAINT wifi_ap_status_check CHECK ((status = ANY (ARRAY['plan'::text, 'active'::text])))
);


--
-- Name: wifi_ap_id_seq; Type: SEQUENCE; Schema: app; Owner: -
--

CREATE SEQUENCE app.wifi_ap_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wifi_ap_id_seq; Type: SEQUENCE OWNED BY; Schema: app; Owner: -
--

ALTER SEQUENCE app.wifi_ap_id_seq OWNED BY app.wifi_ap.id;


--
-- Name: wifi_ap_model; Type: TABLE; Schema: app; Owner: -
--

CREATE TABLE app.wifi_ap_model (
    model text NOT NULL,
    label text NOT NULL,
    pattern text NOT NULL,
    beam_deg real NOT NULL,
    range_m real NOT NULL,
    notes text,
    fringe_range_m real,
    fringe_beam_deg real,
    CONSTRAINT wifi_ap_model_beam_deg_check CHECK (((beam_deg > (0)::double precision) AND (beam_deg <= (360)::double precision))),
    CONSTRAINT wifi_ap_model_fringe_beam_deg_check CHECK (((fringe_beam_deg IS NULL) OR ((fringe_beam_deg > (0)::double precision) AND (fringe_beam_deg <= (360)::double precision)))),
    CONSTRAINT wifi_ap_model_fringe_range_m_check CHECK (((fringe_range_m IS NULL) OR (fringe_range_m > (0)::double precision))),
    CONSTRAINT wifi_ap_model_pattern_check CHECK ((pattern = ANY (ARRAY['omni'::text, 'directional'::text]))),
    CONSTRAINT wifi_ap_model_range_m_check CHECK ((range_m > (0)::double precision))
);


--
-- Name: anchor id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.anchor ALTER COLUMN id SET DEFAULT nextval('app.anchor_id_seq'::regclass);


--
-- Name: app_user app_user_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.app_user ALTER COLUMN app_user_id SET DEFAULT nextval('app.app_user_app_user_id_seq'::regclass);


--
-- Name: audit_log audit_log_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.audit_log ALTER COLUMN audit_log_id SET DEFAULT nextval('app.audit_log_audit_log_id_seq'::regclass);


--
-- Name: auth_identity auth_identity_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.auth_identity ALTER COLUMN auth_identity_id SET DEFAULT nextval('app.auth_identity_auth_identity_id_seq'::regclass);


--
-- Name: bell_permit id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit ALTER COLUMN id SET DEFAULT nextval('app.bell_permit_id_seq'::regclass);


--
-- Name: bell_permit_pole id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit_pole ALTER COLUMN id SET DEFAULT nextval('app.bell_permit_pole_id_seq'::regclass);


--
-- Name: bom_extra id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_extra ALTER COLUMN id SET DEFAULT nextval('app.bom_extra_id_seq'::regclass);


--
-- Name: bom_material id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_material ALTER COLUMN id SET DEFAULT nextval('app.bom_material_id_seq'::regclass);


--
-- Name: bom_price id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_price ALTER COLUMN id SET DEFAULT nextval('app.bom_price_id_seq'::regclass);


--
-- Name: building_anchor id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.building_anchor ALTER COLUMN id SET DEFAULT nextval('app.building_anchor_id_seq'::regclass);


--
-- Name: cable cable_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable ALTER COLUMN cable_id SET DEFAULT nextval('app.cable_cable_id_seq'::regclass);


--
-- Name: cable_span cable_span_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span ALTER COLUMN cable_span_id SET DEFAULT nextval('app.cable_span_cable_span_id_seq'::regclass);


--
-- Name: cable_span_work_order id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order ALTER COLUMN id SET DEFAULT nextval('app.cable_span_work_order_id_seq'::regclass);


--
-- Name: circuit circuit_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit ALTER COLUMN circuit_id SET DEFAULT nextval('app.circuit_circuit_id_seq'::regclass);


--
-- Name: circuit_fibre id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre ALTER COLUMN id SET DEFAULT nextval('app.circuit_fibre_id_seq'::regclass);


--
-- Name: closure_type closure_type_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.closure_type ALTER COLUMN closure_type_id SET DEFAULT nextval('app.closure_type_closure_type_id_seq'::regclass);


--
-- Name: conduit conduit_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit ALTER COLUMN conduit_id SET DEFAULT nextval('app.conduit_conduit_id_seq'::regclass);


--
-- Name: conduit_termination termination_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination ALTER COLUMN termination_id SET DEFAULT nextval('app.conduit_termination_termination_id_seq'::regclass);


--
-- Name: conduit_work_order id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order ALTER COLUMN id SET DEFAULT nextval('app.conduit_work_order_id_seq'::regclass);


--
-- Name: dimension id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dimension ALTER COLUMN id SET DEFAULT nextval('app.dimension_id_seq'::regclass);


--
-- Name: document document_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document ALTER COLUMN document_id SET DEFAULT nextval('app.document_document_id_seq'::regclass);


--
-- Name: easement id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.easement ALTER COLUMN id SET DEFAULT nextval('app.easement_id_seq'::regclass);


--
-- Name: flextin_run id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.flextin_run ALTER COLUMN id SET DEFAULT nextval('app.flextin_run_id_seq'::regclass);


--
-- Name: fosc fosc_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc ALTER COLUMN fosc_id SET DEFAULT nextval('app.fosc_fosc_id_seq'::regclass);


--
-- Name: fosc_device fosc_device_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device ALTER COLUMN fosc_device_id SET DEFAULT nextval('app.fosc_device_fosc_device_id_seq'::regclass);


--
-- Name: fosc_work_order id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order ALTER COLUMN id SET DEFAULT nextval('app.fosc_work_order_id_seq'::regclass);


--
-- Name: fpm_permit_stage id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit_stage ALTER COLUMN id SET DEFAULT nextval('app.fpm_permit_stage_id_seq'::regclass);


--
-- Name: lead_association lead_association_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lead_association ALTER COLUMN lead_association_id SET DEFAULT nextval('app.lead_association_lead_association_id_seq'::regclass);


--
-- Name: lnd_external ext_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_external ALTER COLUMN ext_id SET DEFAULT nextval('app.lnd_external_ext_id_seq'::regclass);


--
-- Name: map_note id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_note ALTER COLUMN id SET DEFAULT nextval('app.map_note_id_seq'::regclass);


--
-- Name: map_plan plan_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_plan ALTER COLUMN plan_id SET DEFAULT nextval('app.map_plan_plan_id_seq'::regclass);


--
-- Name: messenger_strand id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand ALTER COLUMN id SET DEFAULT nextval('app.messenger_strand_id_seq'::regclass);


--
-- Name: microduct_type id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.microduct_type ALTER COLUMN id SET DEFAULT nextval('app.microduct_type_id_seq'::regclass);


--
-- Name: nb_community id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_community ALTER COLUMN id SET DEFAULT nextval('app.nb_community_id_seq'::regclass);


--
-- Name: ont_association ont_association_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.ont_association ALTER COLUMN ont_association_id SET DEFAULT nextval('app.ont_association_ont_association_id_seq'::regclass);


--
-- Name: otdr_result otdr_result_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result ALTER COLUMN otdr_result_id SET DEFAULT nextval('app.otdr_result_otdr_result_id_seq'::regclass);


--
-- Name: pole fid; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole ALTER COLUMN fid SET DEFAULT nextval('app.pole_fid_seq'::regclass);


--
-- Name: pole_work_order id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order ALTER COLUMN id SET DEFAULT nextval('app.pole_work_order_id_seq'::regclass);


--
-- Name: road_coverage seg_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage ALTER COLUMN seg_id SET DEFAULT nextval('app.road_coverage_seg_id_seq'::regclass);


--
-- Name: road_coverage_service id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage_service ALTER COLUMN id SET DEFAULT nextval('app.road_coverage_service_id_seq'::regclass);


--
-- Name: salisbury_zoning id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.salisbury_zoning ALTER COLUMN id SET DEFAULT nextval('app.salisbury_zoning_id_seq'::regclass);


--
-- Name: service_association service_association_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.service_association ALTER COLUMN service_association_id SET DEFAULT nextval('app.service_association_service_association_id_seq'::regclass);


--
-- Name: sheath_length sheath_length_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.sheath_length ALTER COLUMN sheath_length_id SET DEFAULT nextval('app.sheath_length_sheath_length_id_seq'::regclass);


--
-- Name: span_tag span_tag_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.span_tag ALTER COLUMN span_tag_id SET DEFAULT nextval('app.span_tag_span_tag_id_seq'::regclass);


--
-- Name: splice_join splice_join_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.splice_join ALTER COLUMN splice_join_id SET DEFAULT nextval('app.splice_join_splice_join_id_seq'::regclass);


--
-- Name: static_customer static_customer_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.static_customer ALTER COLUMN static_customer_id SET DEFAULT nextval('app.static_customer_static_customer_id_seq'::regclass);


--
-- Name: survey_draft id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.survey_draft ALTER COLUMN id SET DEFAULT nextval('app.survey_draft_id_seq'::regclass);


--
-- Name: timeline_entry timeline_entry_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_entry ALTER COLUMN timeline_entry_id SET DEFAULT nextval('app.timeline_entry_timeline_entry_id_seq'::regclass);


--
-- Name: timeline_media timeline_media_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_media ALTER COLUMN timeline_media_id SET DEFAULT nextval('app.timeline_media_timeline_media_id_seq'::regclass);


--
-- Name: uguard id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uguard ALTER COLUMN id SET DEFAULT nextval('app.uguard_id_seq'::regclass);


--
-- Name: uisp_client_gps_log log_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_client_gps_log ALTER COLUMN log_id SET DEFAULT nextval('app.uisp_client_gps_log_log_id_seq'::regclass);


--
-- Name: uisp_common_event common_event_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_common_event ALTER COLUMN common_event_id SET DEFAULT nextval('app.uisp_mass_event_mass_event_id_seq'::regclass);


--
-- Name: vault vault_id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.vault ALTER COLUMN vault_id SET DEFAULT nextval('app.vault_vault_id_seq'::regclass);


--
-- Name: wifi_ap id; Type: DEFAULT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.wifi_ap ALTER COLUMN id SET DEFAULT nextval('app.wifi_ap_id_seq'::regclass);


--
-- Name: actor actor_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.actor
    ADD CONSTRAINT actor_pkey PRIMARY KEY (actor_code);


--
-- Name: anchor anchor_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.anchor
    ADD CONSTRAINT anchor_pkey PRIMARY KEY (id);


--
-- Name: app_user app_user_email_unique; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.app_user
    ADD CONSTRAINT app_user_email_unique UNIQUE (email);


--
-- Name: app_user app_user_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.app_user
    ADD CONSTRAINT app_user_pkey PRIMARY KEY (app_user_id);


--
-- Name: app_user app_user_username_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.app_user
    ADD CONSTRAINT app_user_username_key UNIQUE (username);


--
-- Name: asset_density_meta asset_density_meta_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.asset_density_meta
    ADD CONSTRAINT asset_density_meta_pkey PRIMARY KEY (singleton);


--
-- Name: audit_exempt audit_exempt_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.audit_exempt
    ADD CONSTRAINT audit_exempt_pkey PRIMARY KEY (table_name);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (audit_log_id);


--
-- Name: auth_identity auth_identity_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.auth_identity
    ADD CONSTRAINT auth_identity_pkey PRIMARY KEY (auth_identity_id);


--
-- Name: auth_identity auth_identity_provider_subject_uq; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.auth_identity
    ADD CONSTRAINT auth_identity_provider_subject_uq UNIQUE (provider, provider_subject);


--
-- Name: bell_permit bell_permit_permit_num_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit
    ADD CONSTRAINT bell_permit_permit_num_key UNIQUE (permit_num);


--
-- Name: bell_permit bell_permit_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit
    ADD CONSTRAINT bell_permit_pkey PRIMARY KEY (id);


--
-- Name: bell_permit_pole bell_permit_pole_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit_pole
    ADD CONSTRAINT bell_permit_pole_pkey PRIMARY KEY (id);


--
-- Name: bom_extra bom_extra_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_extra
    ADD CONSTRAINT bom_extra_pkey PRIMARY KEY (id);


--
-- Name: bom_material bom_material_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_material
    ADD CONSTRAINT bom_material_pkey PRIMARY KEY (id);


--
-- Name: bom_price bom_price_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_price
    ADD CONSTRAINT bom_price_pkey PRIMARY KEY (id);


--
-- Name: building_anchor building_anchor_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.building_anchor
    ADD CONSTRAINT building_anchor_pkey PRIMARY KEY (id);


--
-- Name: cable cable_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable
    ADD CONSTRAINT cable_pkey PRIMARY KEY (cable_id);


--
-- Name: cable_span_conduit cable_span_conduit_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_conduit
    ADD CONSTRAINT cable_span_conduit_pkey PRIMARY KEY (cable_span_id, conduit_id);


--
-- Name: cable_span cable_span_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT cable_span_pkey PRIMARY KEY (cable_span_id);


--
-- Name: cable_span_strand cable_span_strand_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_strand
    ADD CONSTRAINT cable_span_strand_pkey PRIMARY KEY (cable_span_id, strand_id);


--
-- Name: cable_span_tag cable_span_tag_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_tag
    ADD CONSTRAINT cable_span_tag_pkey PRIMARY KEY (span_tag_id, cable_span_id);


--
-- Name: cable_span_work_order cable_span_work_order_cable_span_id_work_order_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order
    ADD CONSTRAINT cable_span_work_order_cable_span_id_work_order_key UNIQUE (cable_span_id, work_order);


--
-- Name: cable_span_work_order cable_span_work_order_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order
    ADD CONSTRAINT cable_span_work_order_pkey PRIMARY KEY (id);


--
-- Name: change_set change_set_change_no_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.change_set
    ADD CONSTRAINT change_set_change_no_key UNIQUE (change_no);


--
-- Name: change_set change_set_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.change_set
    ADD CONSTRAINT change_set_pkey PRIMARY KEY (change_id);


--
-- Name: circuit_fibre circuit_fibre_circuit_id_cable_span_id_fibre_num_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre
    ADD CONSTRAINT circuit_fibre_circuit_id_cable_span_id_fibre_num_key UNIQUE (circuit_id, cable_span_id, fibre_num);


--
-- Name: circuit_fibre circuit_fibre_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre
    ADD CONSTRAINT circuit_fibre_pkey PRIMARY KEY (id);


--
-- Name: circuit circuit_name_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit
    ADD CONSTRAINT circuit_name_key UNIQUE (name);


--
-- Name: circuit circuit_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit
    ADD CONSTRAINT circuit_pkey PRIMARY KEY (circuit_id);


--
-- Name: closure_type closure_type_name_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.closure_type
    ADD CONSTRAINT closure_type_name_key UNIQUE (name);


--
-- Name: closure_type closure_type_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.closure_type
    ADD CONSTRAINT closure_type_pkey PRIMARY KEY (closure_type_id);


--
-- Name: conduit conduit_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_pkey PRIMARY KEY (conduit_id);


--
-- Name: conduit_termination conduit_termination_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_pkey PRIMARY KEY (termination_id);


--
-- Name: conduit_work_order conduit_work_order_conduit_id_work_order_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order
    ADD CONSTRAINT conduit_work_order_conduit_id_work_order_key UNIQUE (conduit_id, work_order);


--
-- Name: conduit_work_order conduit_work_order_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order
    ADD CONSTRAINT conduit_work_order_pkey PRIMARY KEY (id);


--
-- Name: dhcp_lease_cache dhcp_lease_cache_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dhcp_lease_cache
    ADD CONSTRAINT dhcp_lease_cache_pkey PRIMARY KEY (router_id);


--
-- Name: dimension dimension_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dimension
    ADD CONSTRAINT dimension_pkey PRIMARY KEY (id);


--
-- Name: document_pid document_pid_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_pid
    ADD CONSTRAINT document_pid_pkey PRIMARY KEY (document_id, pid);


--
-- Name: document document_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document
    ADD CONSTRAINT document_pkey PRIMARY KEY (document_id);


--
-- Name: document_text document_text_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_text
    ADD CONSTRAINT document_text_pkey PRIMARY KEY (document_id, page);


--
-- Name: easement easement_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.easement
    ADD CONSTRAINT easement_pkey PRIMARY KEY (id);


--
-- Name: flextin_run flextin_run_fosc_id_side_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.flextin_run
    ADD CONSTRAINT flextin_run_fosc_id_side_key UNIQUE (fosc_id, side);


--
-- Name: flextin_run flextin_run_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.flextin_run
    ADD CONSTRAINT flextin_run_pkey PRIMARY KEY (id);


--
-- Name: fosc_device fosc_device_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_pkey PRIMARY KEY (fosc_device_id);


--
-- Name: fosc fosc_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc
    ADD CONSTRAINT fosc_pkey PRIMARY KEY (fosc_id);


--
-- Name: fosc_work_order fosc_work_order_fosc_id_work_order_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order
    ADD CONSTRAINT fosc_work_order_fosc_id_work_order_key UNIQUE (fosc_id, work_order);


--
-- Name: fosc_work_order fosc_work_order_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order
    ADD CONSTRAINT fosc_work_order_pkey PRIMARY KEY (id);


--
-- Name: fpm_permit fpm_permit_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit
    ADD CONSTRAINT fpm_permit_pkey PRIMARY KEY (permit_num);


--
-- Name: fpm_permit_stage fpm_permit_stage_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit_stage
    ADD CONSTRAINT fpm_permit_stage_pkey PRIMARY KEY (id);


--
-- Name: fpm_permit_stage fpm_permit_stage_unique; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit_stage
    ADD CONSTRAINT fpm_permit_stage_unique UNIQUE (permit_num, stage);


--
-- Name: geonb_sync geonb_sync_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.geonb_sync
    ADD CONSTRAINT geonb_sync_pkey PRIMARY KEY (dataset);


--
-- Name: lead_association lead_association_crm_client_id_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lead_association
    ADD CONSTRAINT lead_association_crm_client_id_key UNIQUE (crm_client_id);


--
-- Name: lead_association lead_association_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lead_association
    ADD CONSTRAINT lead_association_pkey PRIMARY KEY (lead_association_id);


--
-- Name: lnd_access_tech lnd_access_tech_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_access_tech
    ADD CONSTRAINT lnd_access_tech_pkey PRIMARY KEY (tech);


--
-- Name: lnd_external lnd_external_name_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_external
    ADD CONSTRAINT lnd_external_name_key UNIQUE (name);


--
-- Name: lnd_external lnd_external_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_external
    ADD CONSTRAINT lnd_external_pkey PRIMARY KEY (ext_id);


--
-- Name: lnd_link lnd_link_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_pkey PRIMARY KEY (link_id);


--
-- Name: lnd_route lnd_route_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_route
    ADD CONSTRAINT lnd_route_pkey PRIMARY KEY (main_cable_id);


--
-- Name: lnd_route lnd_route_route_id_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_route
    ADD CONSTRAINT lnd_route_route_id_key UNIQUE (route_id);


--
-- Name: loss_param loss_param_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.loss_param
    ADD CONSTRAINT loss_param_pkey PRIMARY KEY (param_key);


--
-- Name: map_note map_note_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_note
    ADD CONSTRAINT map_note_pkey PRIMARY KEY (id);


--
-- Name: map_plan map_plan_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_plan
    ADD CONSTRAINT map_plan_pkey PRIMARY KEY (plan_id);


--
-- Name: messenger_strand messenger_strand_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_pkey PRIMARY KEY (id);


--
-- Name: microduct_type microduct_type_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.microduct_type
    ADD CONSTRAINT microduct_type_pkey PRIMARY KEY (id);


--
-- Name: nb_civic_address_add nb_civic_address_add_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_add
    ADD CONSTRAINT nb_civic_address_add_pkey PRIMARY KEY (add_id);


--
-- Name: nb_civic_address_ignore nb_civic_address_ignore_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_ignore
    ADD CONSTRAINT nb_civic_address_ignore_pkey PRIMARY KEY (civ_id);


--
-- Name: nb_civic_address_move nb_civic_address_move_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_move
    ADD CONSTRAINT nb_civic_address_move_pkey PRIMARY KEY (civ_id);


--
-- Name: nb_civic_address nb_civic_address_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address
    ADD CONSTRAINT nb_civic_address_pkey PRIMARY KEY (civ_id);


--
-- Name: nb_community nb_community_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_community
    ADD CONSTRAINT nb_community_pkey PRIMARY KEY (id);


--
-- Name: nb_parcel nb_parcel_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_parcel
    ADD CONSTRAINT nb_parcel_pkey PRIMARY KEY (objectid);


--
-- Name: nb_railway nb_railway_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_railway
    ADD CONSTRAINT nb_railway_pkey PRIMARY KEY (objectid);


--
-- Name: nb_wetland nb_wetland_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland
    ADD CONSTRAINT nb_wetland_pkey PRIMARY KEY (objectid);


--
-- Name: nb_wetland_psw nb_wetland_psw_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_psw
    ADD CONSTRAINT nb_wetland_psw_pkey PRIMARY KEY (objectid);


--
-- Name: ont_association ont_association_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.ont_association
    ADD CONSTRAINT ont_association_pkey PRIMARY KEY (ont_association_id);


--
-- Name: ont_association ont_association_uisp_device_id_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.ont_association
    ADD CONSTRAINT ont_association_uisp_device_id_key UNIQUE (uisp_device_id);


--
-- Name: org_setting org_setting_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.org_setting
    ADD CONSTRAINT org_setting_pkey PRIMARY KEY (key);


--
-- Name: otdr_result otdr_result_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_pkey PRIMARY KEY (otdr_result_id);


--
-- Name: parcel_owner parcel_owner_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.parcel_owner
    ADD CONSTRAINT parcel_owner_pkey PRIMARY KEY (pid);


--
-- Name: pole_owner pole_owner_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_owner
    ADD CONSTRAINT pole_owner_pkey PRIMARY KEY (owner);


--
-- Name: pole_work_order pole_work_order_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order
    ADD CONSTRAINT pole_work_order_pkey PRIMARY KEY (id);


--
-- Name: pole_work_order pole_work_order_uk; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order
    ADD CONSTRAINT pole_work_order_uk UNIQUE (pole_fid, work_order);


--
-- Name: pole poles_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole
    ADD CONSTRAINT poles_pkey PRIMARY KEY (fid);


--
-- Name: pop pop_name_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pop
    ADD CONSTRAINT pop_name_key UNIQUE (name);


--
-- Name: pop pop_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pop
    ADD CONSTRAINT pop_pkey PRIMARY KEY (pop_id);


--
-- Name: road_coverage road_coverage_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage
    ADD CONSTRAINT road_coverage_pkey PRIMARY KEY (seg_id);


--
-- Name: road_coverage_service road_coverage_service_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage_service
    ADD CONSTRAINT road_coverage_service_pkey PRIMARY KEY (id);


--
-- Name: road_coverage_service road_coverage_service_seg_id_provider_technology_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage_service
    ADD CONSTRAINT road_coverage_service_seg_id_provider_technology_key UNIQUE (seg_id, provider, technology);


--
-- Name: route_coincidence_cache route_coincidence_cache_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.route_coincidence_cache
    ADD CONSTRAINT route_coincidence_cache_pkey PRIMARY KEY (kind, id);


--
-- Name: route_coincidence_version route_coincidence_version_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.route_coincidence_version
    ADD CONSTRAINT route_coincidence_version_pkey PRIMARY KEY (id);


--
-- Name: salisbury_zoning salisbury_zoning_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.salisbury_zoning
    ADD CONSTRAINT salisbury_zoning_pkey PRIMARY KEY (id);


--
-- Name: schema_migrations schema_migrations_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (version);


--
-- Name: service_association service_association_crm_service_id_key; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.service_association
    ADD CONSTRAINT service_association_crm_service_id_key UNIQUE (crm_service_id);


--
-- Name: service_association service_association_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.service_association
    ADD CONSTRAINT service_association_pkey PRIMARY KEY (service_association_id);


--
-- Name: sheath_length sheath_length_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.sheath_length
    ADD CONSTRAINT sheath_length_pkey PRIMARY KEY (sheath_length_id);


--
-- Name: span_tag span_tag_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.span_tag
    ADD CONSTRAINT span_tag_pkey PRIMARY KEY (span_tag_id);


--
-- Name: splice_join splice_join_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.splice_join
    ADD CONSTRAINT splice_join_pkey PRIMARY KEY (splice_join_id);


--
-- Name: static_customer static_customer_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.static_customer
    ADD CONSTRAINT static_customer_pkey PRIMARY KEY (static_customer_id);


--
-- Name: survey_draft survey_draft_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.survey_draft
    ADD CONSTRAINT survey_draft_pkey PRIMARY KEY (id);


--
-- Name: timeline_entry timeline_entry_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_entry
    ADD CONSTRAINT timeline_entry_pkey PRIMARY KEY (timeline_entry_id);


--
-- Name: timeline_media timeline_media_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_media
    ADD CONSTRAINT timeline_media_pkey PRIMARY KEY (timeline_media_id);


--
-- Name: uguard uguard_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uguard
    ADD CONSTRAINT uguard_pkey PRIMARY KEY (id);


--
-- Name: uisp_cache uisp_cache_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_cache
    ADD CONSTRAINT uisp_cache_pkey PRIMARY KEY (endpoint);


--
-- Name: uisp_client_gps_log uisp_client_gps_log_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_client_gps_log
    ADD CONSTRAINT uisp_client_gps_log_pkey PRIMARY KEY (log_id);


--
-- Name: uisp_device_event uisp_device_event_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_device_event
    ADD CONSTRAINT uisp_device_event_pkey PRIMARY KEY (uisp_log_id);


--
-- Name: uisp_common_event uisp_mass_event_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_common_event
    ADD CONSTRAINT uisp_mass_event_pkey PRIMARY KEY (common_event_id);


--
-- Name: span_tag uq_span_tag_name; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.span_tag
    ADD CONSTRAINT uq_span_tag_name UNIQUE (name);


--
-- Name: vault vault_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.vault
    ADD CONSTRAINT vault_pkey PRIMARY KEY (vault_id);


--
-- Name: wifi_ap_model wifi_ap_model_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.wifi_ap_model
    ADD CONSTRAINT wifi_ap_model_pkey PRIMARY KEY (model);


--
-- Name: wifi_ap wifi_ap_pkey; Type: CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.wifi_ap
    ADD CONSTRAINT wifi_ap_pkey PRIMARY KEY (id);


--
-- Name: actor_app_user_uidx; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX actor_app_user_uidx ON app.actor USING btree (app_user_id) WHERE (app_user_id IS NOT NULL);


--
-- Name: anchor_pole_fid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX anchor_pole_fid_idx ON app.anchor USING btree (pole_fid);


--
-- Name: asset_density_hex_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX asset_density_hex_gix ON app.asset_density_hex USING gist (geom);


--
-- Name: asset_density_hex_zoom; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX asset_density_hex_zoom ON app.asset_density_hex USING btree (zoom);


--
-- Name: audit_log_actor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX audit_log_actor_idx ON app.audit_log USING btree (actor_username);


--
-- Name: audit_log_change_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX audit_log_change_idx ON app.audit_log USING btree (change_id, audit_log_id);


--
-- Name: audit_log_table_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX audit_log_table_idx ON app.audit_log USING btree (schema_name, table_name, happened_at DESC);


--
-- Name: audit_log_when_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX audit_log_when_idx ON app.audit_log USING btree (happened_at DESC);


--
-- Name: auth_identity_user_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX auth_identity_user_idx ON app.auth_identity USING btree (app_user_id);


--
-- Name: bell_permit_nbp_permit_num_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX bell_permit_nbp_permit_num_idx ON app.bell_permit USING btree (nbp_permit_num) WHERE (nbp_permit_num IS NOT NULL);


--
-- Name: bell_permit_pole_permit_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX bell_permit_pole_permit_id_idx ON app.bell_permit_pole USING btree (permit_id);


--
-- Name: bell_permit_pole_pole_fid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX bell_permit_pole_pole_fid_idx ON app.bell_permit_pole USING btree (pole_fid);


--
-- Name: bom_extra_wor; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX bom_extra_wor ON app.bom_extra USING btree (fpm_wor);


--
-- Name: bom_price_material_date; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX bom_price_material_date ON app.bom_price USING btree (material_id, effective_date DESC);


--
-- Name: building_anchor_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX building_anchor_geom_gix ON app.building_anchor USING gist (geom);


--
-- Name: cable_span_cable_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX cable_span_cable_id_idx ON app.cable_span USING btree (cable_id);


--
-- Name: cable_span_conduit_conduit_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX cable_span_conduit_conduit_idx ON app.cable_span_conduit USING btree (conduit_id);


--
-- Name: cable_span_from_fosc_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX cable_span_from_fosc_id_idx ON app.cable_span USING btree (from_fosc_id);


--
-- Name: cable_span_strand_strand_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX cable_span_strand_strand_idx ON app.cable_span_strand USING btree (strand_id);


--
-- Name: cable_span_to_fosc_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX cable_span_to_fosc_id_idx ON app.cable_span USING btree (to_fosc_id);


--
-- Name: cable_span_work_order_primary_uk; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX cable_span_work_order_primary_uk ON app.cable_span_work_order USING btree (cable_span_id) WHERE is_primary;


--
-- Name: cable_span_work_order_wo_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX cable_span_work_order_wo_idx ON app.cable_span_work_order USING btree (work_order);


--
-- Name: change_set_actor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX change_set_actor_idx ON app.change_set USING btree (actor_code, started_at DESC);


--
-- Name: change_set_when_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX change_set_when_idx ON app.change_set USING btree (started_at DESC);


--
-- Name: circuit_fibre_span_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX circuit_fibre_span_idx ON app.circuit_fibre USING btree (cable_span_id, fibre_num);


--
-- Name: conduit_end_term_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_end_term_idx ON app.conduit USING btree (end_term_id);


--
-- Name: conduit_end_vault_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_end_vault_idx ON app.conduit USING btree (end_vault_id);


--
-- Name: conduit_geom_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_geom_idx ON app.conduit USING gist (geom);


--
-- Name: conduit_start_term_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_start_term_idx ON app.conduit USING btree (start_term_id);


--
-- Name: conduit_start_vault_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_start_vault_idx ON app.conduit USING btree (start_vault_id);


--
-- Name: conduit_termination_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_termination_geom_gix ON app.conduit_termination USING gist (geom);


--
-- Name: conduit_work_order_primary_uk; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX conduit_work_order_primary_uk ON app.conduit_work_order USING btree (conduit_id) WHERE is_primary;


--
-- Name: conduit_work_order_wo_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX conduit_work_order_wo_idx ON app.conduit_work_order USING btree (work_order);


--
-- Name: dimension_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX dimension_geom_gix ON app.dimension USING gist (geom);


--
-- Name: document_category_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX document_category_idx ON app.document USING btree (category);


--
-- Name: document_pid_pid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX document_pid_pid_idx ON app.document_pid USING btree (pid);


--
-- Name: document_text_fts; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX document_text_fts ON app.document_text USING gin (to_tsvector('english'::regconfig, body));


--
-- Name: document_title_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX document_title_idx ON app.document USING btree (title);


--
-- Name: easement_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX easement_geom_gix ON app.easement USING gist (geom);


--
-- Name: easement_pid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX easement_pid_idx ON app.easement USING btree (pid);


--
-- Name: fosc_device_fosc_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fosc_device_fosc_idx ON app.fosc_device USING btree (fosc_id);


--
-- Name: fosc_device_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fosc_device_gix ON app.fosc_device USING gist (location);


--
-- Name: fosc_device_parent_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fosc_device_parent_idx ON app.fosc_device USING btree (parent_device_id);


--
-- Name: fosc_device_parent_slot_uix; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX fosc_device_parent_slot_uix ON app.fosc_device USING btree (parent_device_id, parent_slot) WHERE ((parent_device_id IS NOT NULL) AND (parent_slot IS NOT NULL));


--
-- Name: fosc_device_pop_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fosc_device_pop_id_idx ON app.fosc_device USING btree (pop_id);


--
-- Name: fosc_pop_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fosc_pop_id_idx ON app.fosc USING btree (pop_id);


--
-- Name: fosc_splice_num_abc_uniq; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX fosc_splice_num_abc_uniq ON app.fosc USING btree (splice_num) WHERE (splice_num ~ '^[A-Z]{3}[0-9]{4}$'::text);


--
-- Name: fosc_work_order_primary_uk; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX fosc_work_order_primary_uk ON app.fosc_work_order USING btree (fosc_id) WHERE is_primary;


--
-- Name: fosc_work_order_wo_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fosc_work_order_wo_idx ON app.fosc_work_order USING btree (work_order);


--
-- Name: fpm_permit_stage_lookup; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX fpm_permit_stage_lookup ON app.fpm_permit_stage USING btree (permit_num, effective_date DESC, id DESC);


--
-- Name: idx_app_timeline_entry_body_tsv; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_app_timeline_entry_body_tsv ON app.timeline_entry USING gin (body_tsv);


--
-- Name: idx_app_timeline_entry_parent; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_app_timeline_entry_parent ON app.timeline_entry USING btree (parent_type, parent_id);


--
-- Name: idx_app_timeline_entry_updated_at; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_app_timeline_entry_updated_at ON app.timeline_entry USING btree (updated_at DESC);


--
-- Name: idx_app_timeline_media_entry; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_app_timeline_media_entry ON app.timeline_media USING btree (timeline_entry_id);


--
-- Name: idx_bell_permit_pole_nbp_num; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_bell_permit_pole_nbp_num ON app.bell_permit_pole USING btree (nbp_num);


--
-- Name: idx_cable_span_geom_gist; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_cable_span_geom_gist ON app.cable_span USING gist (geom);


--
-- Name: idx_fosc_closure_type; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_fosc_closure_type ON app.fosc USING btree (closure_type_id);


--
-- Name: idx_fosc_status; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_fosc_status ON app.fosc USING btree (status);


--
-- Name: idx_pole_geom_4326; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_pole_geom_4326 ON app.pole USING gist (topology.st_transform(geom, 4326));


--
-- Name: idx_splice_join_a_span_fiber; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_splice_join_a_span_fiber ON app.splice_join USING btree (a_span_id, a_end, a_range) WHERE ((a_target = 'cable_span'::app.join_target) AND (is_plan = false));


--
-- Name: idx_splice_join_b_span_fiber; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_splice_join_b_span_fiber ON app.splice_join USING btree (b_span_id, b_end, b_range) WHERE ((b_target = 'cable_span'::app.join_target) AND (is_plan = false));


--
-- Name: idx_splice_join_device; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_splice_join_device ON app.splice_join USING btree (a_span_id, b_span_id) WHERE (((a_target = 'fosc_device'::app.join_target) OR (b_target = 'fosc_device'::app.join_target)) AND (is_plan = false));


--
-- Name: idx_timeline_fosc; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_timeline_fosc ON app.timeline_entry USING btree (fosc_id);


--
-- Name: idx_uisp_common_event_started; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_uisp_common_event_started ON app.uisp_common_event USING btree (started_at DESC);


--
-- Name: idx_uisp_device_event_device_time; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_uisp_device_event_device_time ON app.uisp_device_event USING btree (device_id, occurred_at DESC);


--
-- Name: idx_uisp_device_event_scoring; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_uisp_device_event_scoring ON app.uisp_device_event USING btree (occurred_at) WHERE (kind = ANY (ARRAY['disconnect'::text, 'restart'::text, 'pon_down'::text]));


--
-- Name: idx_uisp_device_event_time; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX idx_uisp_device_event_time ON app.uisp_device_event USING btree (occurred_at DESC);


--
-- Name: ix_cable_span_tag_span; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX ix_cable_span_tag_span ON app.cable_span_tag USING btree (cable_span_id);


--
-- Name: ix_map_plan_entity; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX ix_map_plan_entity ON app.map_plan USING btree (entity_type, entity_id, sort_order, plan_id);


--
-- Name: ix_sheath_length_geom; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX ix_sheath_length_geom ON app.sheath_length USING gist (geom);


--
-- Name: ix_sheath_length_span; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX ix_sheath_length_span ON app.sheath_length USING btree (cable_span_id);


--
-- Name: ix_uisp_client_gps_log_client; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX ix_uisp_client_gps_log_client ON app.uisp_client_gps_log USING btree (crm_client_id, moved_at DESC);


--
-- Name: lead_association_fosc; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX lead_association_fosc ON app.lead_association USING btree (fosc_id);


--
-- Name: map_note_fpm_wor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX map_note_fpm_wor_idx ON app.map_note USING btree (fpm_wor);


--
-- Name: map_note_geom_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX map_note_geom_idx ON app.map_note USING gist (geom);


--
-- Name: map_note_open_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX map_note_open_idx ON app.map_note USING btree (fpm_wor) WHERE (done_at IS NULL);


--
-- Name: messenger_strand_fpm_wor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX messenger_strand_fpm_wor_idx ON app.messenger_strand USING btree (fpm_wor) WHERE (fpm_wor IS NOT NULL);


--
-- Name: messenger_strand_from_anchor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX messenger_strand_from_anchor_idx ON app.messenger_strand USING btree (from_anchor_id);


--
-- Name: messenger_strand_from_pole_fid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX messenger_strand_from_pole_fid_idx ON app.messenger_strand USING btree (from_pole_fid);


--
-- Name: messenger_strand_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX messenger_strand_geom_gix ON app.messenger_strand USING gist (geom);


--
-- Name: messenger_strand_identical_uidx; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX messenger_strand_identical_uidx ON app.messenger_strand USING btree (LEAST(app.strand_endpoint_key(from_pole_fid, from_anchor_id), app.strand_endpoint_key(to_pole_fid, to_anchor_id)), GREATEST(app.strand_endpoint_key(from_pole_fid, from_anchor_id), app.strand_endpoint_key(to_pole_fid, to_anchor_id)), is_overlash, status, tension, (
CASE
    WHEN (app.strand_endpoint_key(from_pole_fid, from_anchor_id) <= app.strand_endpoint_key(to_pole_fid, to_anchor_id)) THEN from_side
    ELSE to_side
END), (
CASE
    WHEN (app.strand_endpoint_key(from_pole_fid, from_anchor_id) <= app.strand_endpoint_key(to_pole_fid, to_anchor_id)) THEN from_height
    ELSE to_height
END), (
CASE
    WHEN (app.strand_endpoint_key(from_pole_fid, from_anchor_id) <= app.strand_endpoint_key(to_pole_fid, to_anchor_id)) THEN to_side
    ELSE from_side
END), (
CASE
    WHEN (app.strand_endpoint_key(from_pole_fid, from_anchor_id) <= app.strand_endpoint_key(to_pole_fid, to_anchor_id)) THEN to_height
    ELSE from_height
END));


--
-- Name: messenger_strand_to_anchor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX messenger_strand_to_anchor_idx ON app.messenger_strand USING btree (to_anchor_id);


--
-- Name: messenger_strand_to_pole_fid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX messenger_strand_to_pole_fid_idx ON app.messenger_strand USING btree (to_pole_fid);


--
-- Name: nb_civic_address_add_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_civic_address_add_geom_gix ON app.nb_civic_address_add USING gist (geom);


--
-- Name: nb_civic_address_addr_trgm; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_civic_address_addr_trgm ON app.nb_civic_address USING gin (address topology.gin_trgm_ops);


--
-- Name: nb_civic_address_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_civic_address_geom_gix ON app.nb_civic_address USING gist (geom);


--
-- Name: nb_civic_address_pid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_civic_address_pid_idx ON app.nb_civic_address USING btree (pid);


--
-- Name: nb_community_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_community_geom_gix ON app.nb_community USING gist (geom);


--
-- Name: nb_community_name_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_community_name_idx ON app.nb_community USING btree (name);


--
-- Name: nb_parcel_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_parcel_geom_gix ON app.nb_parcel USING gist (geom);


--
-- Name: nb_parcel_last_update_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_parcel_last_update_idx ON app.nb_parcel USING btree (last_update);


--
-- Name: nb_parcel_pid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_parcel_pid_idx ON app.nb_parcel USING btree (pid);


--
-- Name: nb_railway_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_railway_geom_gix ON app.nb_railway USING gist (geom);


--
-- Name: nb_wetland_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_wetland_geom_gix ON app.nb_wetland USING gist (geom);


--
-- Name: nb_wetland_psw_geom_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX nb_wetland_psw_geom_idx ON app.nb_wetland_psw USING gist (geom);


--
-- Name: ont_association_fosc_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX ont_association_fosc_id_idx ON app.ont_association USING btree (fosc_id);


--
-- Name: otdr_result_cable_span_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX otdr_result_cable_span_id_idx ON app.otdr_result USING btree (cable_span_id);


--
-- Name: otdr_result_file_hash_uniq; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX otdr_result_file_hash_uniq ON app.otdr_result USING btree (file_hash) WHERE (file_hash IS NOT NULL);


--
-- Name: otdr_result_fosc_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX otdr_result_fosc_id_idx ON app.otdr_result USING btree (fosc_id);


--
-- Name: otdr_result_uploaded_at_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX otdr_result_uploaded_at_idx ON app.otdr_result USING btree (uploaded_at DESC);


--
-- Name: pole_alden_pole_id_uidx; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX pole_alden_pole_id_uidx ON app.pole USING btree (alden_pole_id) WHERE (alden_pole_id IS NOT NULL);


--
-- Name: pole_nbp_official_uidx; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX pole_nbp_official_uidx ON app.pole USING btree ("nbp#") WHERE (("nbp#")::text ~ '^[0-9]{4}[A-Z0-9]*_'::text);


--
-- Name: pole_work_order_pole_fid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX pole_work_order_pole_fid_idx ON app.pole_work_order USING btree (pole_fid);


--
-- Name: pole_work_order_primary_uk; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX pole_work_order_primary_uk ON app.pole_work_order USING btree (pole_fid) WHERE is_primary;


--
-- Name: pole_work_order_work_order_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX pole_work_order_work_order_idx ON app.pole_work_order USING btree (work_order);


--
-- Name: pop_location_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX pop_location_gix ON app.pop USING gist (location);


--
-- Name: road_coverage_geom_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX road_coverage_geom_idx ON app.road_coverage USING gist (geom);


--
-- Name: road_coverage_osm_way_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX road_coverage_osm_way_id_idx ON app.road_coverage USING btree (osm_way_id);


--
-- Name: salisbury_zoning_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX salisbury_zoning_geom_gix ON app.salisbury_zoning USING gist (geom);


--
-- Name: salisbury_zoning_layer_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX salisbury_zoning_layer_idx ON app.salisbury_zoning USING btree (layer);


--
-- Name: service_association_client; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX service_association_client ON app.service_association USING btree (crm_client_id);


--
-- Name: service_association_fosc; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX service_association_fosc ON app.service_association USING btree (fosc_id);


--
-- Name: sidx_app_timeline_entry_geom; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX sidx_app_timeline_entry_geom ON app.timeline_entry USING gist (geom);


--
-- Name: sidx_fosc_location; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX sidx_fosc_location ON app.fosc USING gist (location);


--
-- Name: sidx_fosc_location_geog; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX sidx_fosc_location_geog ON app.fosc USING gist (((location)::topology.geography));


--
-- Name: sidx_pole_geog; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX sidx_pole_geog ON app.pole USING gist (((geom)::topology.geography));


--
-- Name: sidx_poles_geom; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX sidx_poles_geom ON app.pole USING gist (geom);


--
-- Name: splice_join_fosc_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX splice_join_fosc_id_idx ON app.splice_join USING btree (fosc_id);


--
-- Name: splice_join_fosc_retired; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX splice_join_fosc_retired ON app.splice_join USING btree (fosc_id, retired_at) WHERE (retired_at IS NOT NULL);


--
-- Name: static_customer_mac_address_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX static_customer_mac_address_idx ON app.static_customer USING btree (mac_address);


--
-- Name: static_customer_static_ipv4_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX static_customer_static_ipv4_idx ON app.static_customer USING btree (static_ipv4);


--
-- Name: static_customer_vlan_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX static_customer_vlan_idx ON app.static_customer USING btree (vlan);


--
-- Name: survey_draft_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX survey_draft_geom_gix ON app.survey_draft USING gist (geom);


--
-- Name: uguard_pole_fid_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX uguard_pole_fid_idx ON app.uguard USING btree (pole_fid);


--
-- Name: uisp_device_event_common_event_id_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX uisp_device_event_common_event_id_idx ON app.uisp_device_event USING btree (common_event_id);


--
-- Name: ux_cable_name_fibres; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX ux_cable_name_fibres ON app.cable USING btree (name, fibre_count);


--
-- Name: vault_alden_vault_id_uidx; Type: INDEX; Schema: app; Owner: -
--

CREATE UNIQUE INDEX vault_alden_vault_id_uidx ON app.vault USING btree (alden_vault_id) WHERE (alden_vault_id IS NOT NULL);


--
-- Name: vault_geom_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX vault_geom_idx ON app.vault USING gist (geom);


--
-- Name: vault_model_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX vault_model_idx ON app.vault USING btree (model_id);


--
-- Name: wifi_ap_fpm_wor_idx; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX wifi_ap_fpm_wor_idx ON app.wifi_ap USING btree (fpm_wor);


--
-- Name: wifi_ap_geom_gix; Type: INDEX; Schema: app; Owner: -
--

CREATE INDEX wifi_ap_geom_gix ON app.wifi_ap USING gist (geom);


--
-- Name: anchor aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.anchor FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: app_user aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.app_user FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: asset_density_hex aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.asset_density_hex FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: asset_density_meta aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.asset_density_meta FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: auth_identity aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.auth_identity FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: bell_permit aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.bell_permit FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: bell_permit_pole aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.bell_permit_pole FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: bom_extra aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.bom_extra FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: bom_material aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.bom_material FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: bom_price aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.bom_price FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: building_anchor aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.building_anchor FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: cable aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.cable FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: cable_span aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: cable_span_conduit aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.cable_span_conduit FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: cable_span_strand aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.cable_span_strand FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: cable_span_tag aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.cable_span_tag FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: cable_span_work_order aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.cable_span_work_order FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: circuit aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.circuit FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: circuit_fibre aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.circuit_fibre FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: closure_type aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.closure_type FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: conduit aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.conduit FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: conduit_termination aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.conduit_termination FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: conduit_work_order aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.conduit_work_order FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: dhcp_lease_cache aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.dhcp_lease_cache FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: dimension aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.dimension FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: document aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.document FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: document_pid aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.document_pid FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: document_text aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.document_text FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: easement aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.easement FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: flextin_run aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.flextin_run FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: fosc aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.fosc FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: fosc_device aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.fosc_device FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: fosc_work_order aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.fosc_work_order FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: fpm_permit aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.fpm_permit FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: fpm_permit_stage aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.fpm_permit_stage FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: geonb_sync aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.geonb_sync FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: lead_association aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.lead_association FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: lnd_access_tech aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.lnd_access_tech FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: lnd_external aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.lnd_external FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: lnd_link aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.lnd_link FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: lnd_route aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.lnd_route FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: loss_param aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.loss_param FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: map_note aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.map_note FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: map_plan aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.map_plan FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: messenger_strand aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.messenger_strand FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: microduct_type aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.microduct_type FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_civic_address aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_civic_address FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_civic_address_add aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_civic_address_add FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_civic_address_ignore aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_civic_address_ignore FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_civic_address_move aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_civic_address_move FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_civic_address_stage aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_civic_address_stage FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_community aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_community FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_parcel aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_parcel FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_parcel_stage aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_parcel_stage FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_wetland aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_wetland FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_wetland_psw aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_wetland_psw FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_wetland_psw_stage aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_wetland_psw_stage FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: nb_wetland_stage aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.nb_wetland_stage FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: ont_association aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.ont_association FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: otdr_result aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.otdr_result FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: parcel_owner aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.parcel_owner FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: pole aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.pole FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: pole_owner aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.pole_owner FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: pole_work_order aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.pole_work_order FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: pop aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.pop FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: road_coverage aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.road_coverage FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: road_coverage_service aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.road_coverage_service FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: route_coincidence_cache aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.route_coincidence_cache FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: route_coincidence_version aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.route_coincidence_version FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: salisbury_zoning aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.salisbury_zoning FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: schema_migrations aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.schema_migrations FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: service_association aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.service_association FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: sheath_length aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.sheath_length FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: span_tag aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.span_tag FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: splice_join aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.splice_join FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: static_customer aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.static_customer FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: survey_draft aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.survey_draft FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: timeline_entry aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.timeline_entry FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: timeline_media aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.timeline_media FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: uguard aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.uguard FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: uisp_cache aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.uisp_cache FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: uisp_common_event aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.uisp_common_event FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: uisp_device_event aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.uisp_device_event FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: vault aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.vault FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: wifi_ap aa_stamp_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER aa_stamp_change BEFORE INSERT OR UPDATE ON app.wifi_ap FOR EACH ROW EXECUTE FUNCTION app.stamp_change();


--
-- Name: app_user app_user_set_updated_at; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER app_user_set_updated_at BEFORE UPDATE ON app.app_user FOR EACH ROW EXECUTE FUNCTION app.set_updated_at();


--
-- Name: anchor audit_anchor_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_anchor_change AFTER INSERT OR DELETE OR UPDATE ON app.anchor FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: app_user audit_app_user_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_app_user_change AFTER INSERT OR DELETE OR UPDATE ON app.app_user FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: asset_density_meta audit_asset_density_meta_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_asset_density_meta_change AFTER INSERT OR DELETE OR UPDATE ON app.asset_density_meta FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: auth_identity audit_auth_identity_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_auth_identity_change AFTER INSERT OR DELETE OR UPDATE ON app.auth_identity FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: bell_permit audit_bell_permit_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_bell_permit_change AFTER INSERT OR DELETE OR UPDATE ON app.bell_permit FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: bell_permit_pole audit_bell_permit_pole_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_bell_permit_pole_change AFTER INSERT OR DELETE OR UPDATE ON app.bell_permit_pole FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: bom_extra audit_bom_extra_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_bom_extra_change AFTER INSERT OR DELETE OR UPDATE ON app.bom_extra FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: bom_material audit_bom_material_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_bom_material_change AFTER INSERT OR DELETE OR UPDATE ON app.bom_material FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: bom_price audit_bom_price_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_bom_price_change AFTER INSERT OR DELETE OR UPDATE ON app.bom_price FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: building_anchor audit_building_anchor_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_building_anchor_change AFTER INSERT OR DELETE OR UPDATE ON app.building_anchor FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: cable audit_cable_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_cable_change AFTER INSERT OR DELETE OR UPDATE ON app.cable FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: cable_span audit_cable_span_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_cable_span_change AFTER INSERT OR DELETE OR UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: cable_span_conduit audit_cable_span_conduit_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_cable_span_conduit_change AFTER INSERT OR DELETE OR UPDATE ON app.cable_span_conduit FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: cable_span_strand audit_cable_span_strand_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_cable_span_strand_change AFTER INSERT OR DELETE OR UPDATE ON app.cable_span_strand FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: cable_span_tag audit_cable_span_tag_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_cable_span_tag_change AFTER INSERT OR DELETE OR UPDATE ON app.cable_span_tag FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: cable_span_work_order audit_cable_span_work_order_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_cable_span_work_order_change AFTER INSERT OR DELETE OR UPDATE ON app.cable_span_work_order FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: circuit audit_circuit_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_circuit_change AFTER INSERT OR DELETE OR UPDATE ON app.circuit FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: circuit_fibre audit_circuit_fibre_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_circuit_fibre_change AFTER INSERT OR DELETE OR UPDATE ON app.circuit_fibre FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: closure_type audit_closure_type_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_closure_type_change AFTER INSERT OR DELETE OR UPDATE ON app.closure_type FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: conduit audit_conduit_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_conduit_change AFTER INSERT OR DELETE OR UPDATE ON app.conduit FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: conduit_termination audit_conduit_termination_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_conduit_termination_change AFTER INSERT OR DELETE OR UPDATE ON app.conduit_termination FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: conduit_work_order audit_conduit_work_order_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_conduit_work_order_change AFTER INSERT OR DELETE OR UPDATE ON app.conduit_work_order FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: dimension audit_dimension_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_dimension_change AFTER INSERT OR DELETE OR UPDATE ON app.dimension FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: document audit_document_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_document_change AFTER INSERT OR DELETE OR UPDATE ON app.document FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: document_pid audit_document_pid_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_document_pid_change AFTER INSERT OR DELETE OR UPDATE ON app.document_pid FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: easement audit_easement_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_easement_change AFTER INSERT OR DELETE OR UPDATE ON app.easement FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: flextin_run audit_flextin_run_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_flextin_run_change AFTER INSERT OR DELETE OR UPDATE ON app.flextin_run FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: fosc audit_fosc_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_fosc_change AFTER INSERT OR DELETE OR UPDATE ON app.fosc FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: fosc_device audit_fosc_device_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_fosc_device_change AFTER INSERT OR DELETE OR UPDATE ON app.fosc_device FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: fosc_work_order audit_fosc_work_order_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_fosc_work_order_change AFTER INSERT OR DELETE OR UPDATE ON app.fosc_work_order FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: fpm_permit audit_fpm_permit_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_fpm_permit_change AFTER INSERT OR DELETE OR UPDATE ON app.fpm_permit FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: lead_association audit_lead_association_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_lead_association_change AFTER INSERT OR DELETE OR UPDATE ON app.lead_association FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: lnd_access_tech audit_lnd_access_tech_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_lnd_access_tech_change AFTER INSERT OR DELETE OR UPDATE ON app.lnd_access_tech FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: lnd_external audit_lnd_external_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_lnd_external_change AFTER INSERT OR DELETE OR UPDATE ON app.lnd_external FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: lnd_link audit_lnd_link_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_lnd_link_change AFTER INSERT OR DELETE OR UPDATE ON app.lnd_link FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: lnd_route audit_lnd_route_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_lnd_route_change AFTER INSERT OR DELETE OR UPDATE ON app.lnd_route FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: loss_param audit_loss_param_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_loss_param_change AFTER INSERT OR DELETE OR UPDATE ON app.loss_param FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: map_note audit_map_note_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_map_note_change AFTER INSERT OR DELETE OR UPDATE ON app.map_note FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: map_plan audit_map_plan_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_map_plan_change AFTER INSERT OR DELETE OR UPDATE ON app.map_plan FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: messenger_strand audit_messenger_strand_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_messenger_strand_change AFTER INSERT OR DELETE OR UPDATE ON app.messenger_strand FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: microduct_type audit_microduct_type_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_microduct_type_change AFTER INSERT OR DELETE OR UPDATE ON app.microduct_type FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: nb_civic_address_add audit_nb_civic_address_add_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_nb_civic_address_add_change AFTER INSERT OR DELETE OR UPDATE ON app.nb_civic_address_add FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: nb_civic_address_ignore audit_nb_civic_address_ignore_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_nb_civic_address_ignore_change AFTER INSERT OR DELETE OR UPDATE ON app.nb_civic_address_ignore FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: nb_civic_address_move audit_nb_civic_address_move_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_nb_civic_address_move_change AFTER INSERT OR DELETE OR UPDATE ON app.nb_civic_address_move FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: ont_association audit_ont_association_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_ont_association_change AFTER INSERT OR DELETE OR UPDATE ON app.ont_association FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: otdr_result audit_otdr_result_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_otdr_result_change AFTER INSERT OR DELETE OR UPDATE ON app.otdr_result FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: parcel_owner audit_parcel_owner_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_parcel_owner_change AFTER INSERT OR DELETE OR UPDATE ON app.parcel_owner FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: pole audit_pole_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_pole_change AFTER INSERT OR DELETE OR UPDATE ON app.pole FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: pole_owner audit_pole_owner_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_pole_owner_change AFTER INSERT OR DELETE OR UPDATE ON app.pole_owner FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: pole_work_order audit_pole_work_order_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_pole_work_order_change AFTER INSERT OR DELETE OR UPDATE ON app.pole_work_order FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: pop audit_pop_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_pop_change AFTER INSERT OR DELETE OR UPDATE ON app.pop FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: road_coverage audit_road_coverage_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_road_coverage_change AFTER INSERT OR DELETE OR UPDATE ON app.road_coverage FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: road_coverage_service audit_road_coverage_service_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_road_coverage_service_change AFTER INSERT OR DELETE OR UPDATE ON app.road_coverage_service FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: route_coincidence_version audit_route_coincidence_version_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_route_coincidence_version_change AFTER INSERT OR DELETE OR UPDATE ON app.route_coincidence_version FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: schema_migrations audit_schema_migrations_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_schema_migrations_change AFTER INSERT OR DELETE OR UPDATE ON app.schema_migrations FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: service_association audit_service_association_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_service_association_change AFTER INSERT OR DELETE OR UPDATE ON app.service_association FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: sheath_length audit_sheath_length_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_sheath_length_change AFTER INSERT OR DELETE OR UPDATE ON app.sheath_length FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: span_tag audit_span_tag_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_span_tag_change AFTER INSERT OR DELETE OR UPDATE ON app.span_tag FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: splice_join audit_splice_join_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_splice_join_change AFTER INSERT OR DELETE OR UPDATE ON app.splice_join FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: static_customer audit_static_customer_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_static_customer_change AFTER INSERT OR DELETE OR UPDATE ON app.static_customer FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: survey_draft audit_survey_draft_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_survey_draft_change AFTER INSERT OR DELETE OR UPDATE ON app.survey_draft FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: timeline_entry audit_timeline_entry_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_timeline_entry_change AFTER INSERT OR DELETE OR UPDATE ON app.timeline_entry FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: timeline_media audit_timeline_media_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_timeline_media_change AFTER INSERT OR DELETE OR UPDATE ON app.timeline_media FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: uguard audit_uguard_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_uguard_change AFTER INSERT OR DELETE OR UPDATE ON app.uguard FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: vault audit_vault_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_vault_change AFTER INSERT OR DELETE OR UPDATE ON app.vault FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: wifi_ap audit_wifi_ap_change; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER audit_wifi_ap_change AFTER INSERT OR DELETE OR UPDATE ON app.wifi_ap FOR EACH ROW EXECUTE FUNCTION app.audit_row_change();


--
-- Name: bom_price bb_bom_price_landed_cad; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER bb_bom_price_landed_cad BEFORE INSERT OR UPDATE ON app.bom_price FOR EACH ROW EXECUTE FUNCTION app.bom_price_landed_cad();


--
-- Name: conduit bb_conduit_ends; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER bb_conduit_ends BEFORE INSERT OR UPDATE ON app.conduit FOR EACH ROW EXECUTE FUNCTION app.trg_conduit_ends();


--
-- Name: cable_span biu_stamp_cable_span; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER biu_stamp_cable_span BEFORE INSERT OR UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.trg_stamp();


--
-- Name: cable_span cable_span_check_slack_loop; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER cable_span_check_slack_loop AFTER INSERT OR DELETE OR UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.trg_span_check_slack_loop();


--
-- Name: cable_span cable_span_enforce_endpoints_trg; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER cable_span_enforce_endpoints_trg BEFORE INSERT OR UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.cable_span_enforce_endpoints();


--
-- Name: circuit_fibre circuit_fibre_in_range; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER circuit_fibre_in_range BEFORE INSERT OR UPDATE OF cable_span_id, fibre_num ON app.circuit_fibre FOR EACH ROW EXECUTE FUNCTION app.trg_circuit_fibre_in_range();


--
-- Name: fosc fosc_normalize_bi; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER fosc_normalize_bi BEFORE INSERT OR UPDATE ON app.fosc FOR EACH ROW EXECUTE FUNCTION app.trg_fosc_normalize();


--
-- Name: fosc fosc_upper_splice_trg; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER fosc_upper_splice_trg BEFORE INSERT OR UPDATE ON app.fosc FOR EACH ROW EXECUTE FUNCTION app.fosc_upper_splice_trg();


--
-- Name: pole pole_defaults_bi; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER pole_defaults_bi BEFORE INSERT OR UPDATE ON app.pole FOR EACH ROW EXECUTE FUNCTION app.pole_defaults();


--
-- Name: road_coverage_service road_service_ftth_supersedes_expansion; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER road_service_ftth_supersedes_expansion BEFORE INSERT ON app.road_coverage_service FOR EACH ROW EXECUTE FUNCTION app.road_service_ftth_supersedes_expansion();


--
-- Name: cable route_coincidence_bump; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER route_coincidence_bump AFTER INSERT OR DELETE OR UPDATE OF fibre_count ON app.cable FOR EACH STATEMENT EXECUTE FUNCTION app.trg_route_coincidence_bump();


--
-- Name: cable_span route_coincidence_bump; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER route_coincidence_bump AFTER INSERT OR DELETE OR UPDATE ON app.cable_span FOR EACH STATEMENT EXECUTE FUNCTION app.trg_route_coincidence_bump();


--
-- Name: fosc route_coincidence_bump; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER route_coincidence_bump AFTER UPDATE OF location ON app.fosc FOR EACH STATEMENT EXECUTE FUNCTION app.trg_route_coincidence_bump();


--
-- Name: messenger_strand route_coincidence_bump; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER route_coincidence_bump AFTER INSERT OR DELETE OR UPDATE ON app.messenger_strand FOR EACH STATEMENT EXECUTE FUNCTION app.trg_route_coincidence_bump();


--
-- Name: timeline_entry set_body_tsv_ins; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER set_body_tsv_ins BEFORE INSERT ON app.timeline_entry FOR EACH ROW EXECUTE FUNCTION app.trg_update_body_tsv();


--
-- Name: timeline_entry set_body_tsv_upd; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER set_body_tsv_upd BEFORE UPDATE OF body_text ON app.timeline_entry FOR EACH ROW EXECUTE FUNCTION app.trg_update_body_tsv();


--
-- Name: timeline_entry set_updated_at; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER set_updated_at BEFORE UPDATE ON app.timeline_entry FOR EACH ROW EXECUTE FUNCTION app.trg_set_updated_at();


--
-- Name: building_anchor trg_banchor_strand_geom; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_banchor_strand_geom AFTER UPDATE OF geom ON app.building_anchor FOR EACH ROW EXECUTE FUNCTION app.trg_banchor_refresh_strand_geom();


--
-- Name: building_anchor trg_building_anchor_touch; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_building_anchor_touch BEFORE UPDATE ON app.building_anchor FOR EACH ROW EXECUTE FUNCTION app.touch_updated_at();


--
-- Name: cable_span trg_cable_span_enforce_endpoints; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_cable_span_enforce_endpoints BEFORE INSERT OR UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.cable_span_enforce_endpoints();


--
-- Name: cable_span trg_cable_span_touch; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_cable_span_touch BEFORE UPDATE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.touch_updated_at();


--
-- Name: cable trg_cable_touch; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_cable_touch BEFORE UPDATE ON app.cable FOR EACH ROW EXECUTE FUNCTION app.touch_updated_at();


--
-- Name: fosc trg_closure_placement; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_closure_placement BEFORE INSERT OR UPDATE OF location, kind ON app.fosc FOR EACH ROW EXECUTE FUNCTION app.trg_closure_placement();


--
-- Name: conduit trg_conduit_carry_cable; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_conduit_carry_cable BEFORE UPDATE OF geom ON app.conduit FOR EACH ROW EXECUTE FUNCTION app.trg_conduit_carry_cable();


--
-- Name: conduit trg_conduit_support; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_conduit_support AFTER INSERT OR UPDATE OF geom ON app.conduit FOR EACH ROW EXECUTE FUNCTION app.trg_conduit_support();


--
-- Name: conduit trg_conduit_wo_column_to_link; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_conduit_wo_column_to_link AFTER INSERT OR UPDATE OF fpm_wor ON app.conduit FOR EACH ROW EXECUTE FUNCTION app.work_order_column_to_link('conduit_work_order', 'conduit_id', 'conduit_id');


--
-- Name: conduit_work_order trg_conduit_wo_link_to_column; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_conduit_wo_link_to_column AFTER INSERT OR DELETE OR UPDATE OF is_primary, work_order ON app.conduit_work_order FOR EACH ROW EXECUTE FUNCTION app.work_order_link_to_column('conduit', 'conduit_id', 'conduit_id');


--
-- Name: fosc_work_order trg_fosc_wo_link_to_column; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_fosc_wo_link_to_column AFTER INSERT OR DELETE OR UPDATE OF is_primary, work_order ON app.fosc_work_order FOR EACH ROW EXECUTE FUNCTION app.work_order_link_to_column('fosc', 'fosc_id', 'fosc_id');


--
-- Name: fosc_device trg_guard_device_in_splice; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_guard_device_in_splice BEFORE DELETE ON app.fosc_device FOR EACH ROW EXECUTE FUNCTION app.trg_guard_device_in_splice();


--
-- Name: cable_span trg_guard_span_in_splice; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_guard_span_in_splice BEFORE DELETE ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.trg_guard_span_in_splice();


--
-- Name: messenger_strand trg_messenger_strand_geom; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_messenger_strand_geom BEFORE INSERT OR UPDATE OF from_pole_fid, to_pole_fid, from_anchor_id, to_anchor_id ON app.messenger_strand FOR EACH ROW EXECUTE FUNCTION app.trg_strand_set_geom();


--
-- Name: splice_join trg_no_splices_in_terminal; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_no_splices_in_terminal BEFORE INSERT OR UPDATE ON app.splice_join FOR EACH ROW EXECUTE FUNCTION app.no_splices_in_terminal();


--
-- Name: pole trg_pole_carry_cable_spans; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_pole_carry_cable_spans AFTER UPDATE OF geom ON app.pole FOR EACH ROW EXECUTE FUNCTION app.trg_pole_carry_cable_spans();


--
-- Name: pole trg_pole_strand_geom; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_pole_strand_geom AFTER UPDATE OF geom ON app.pole FOR EACH ROW EXECUTE FUNCTION app.trg_pole_refresh_strand_geom();


--
-- Name: pole_work_order trg_pole_wo_link_to_column; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_pole_wo_link_to_column AFTER INSERT OR DELETE OR UPDATE OF is_primary, work_order ON app.pole_work_order FOR EACH ROW EXECUTE FUNCTION app.work_order_link_to_column('pole', 'fid', 'pole_fid');


--
-- Name: pole trg_pole_work_order_mirror; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_pole_work_order_mirror AFTER INSERT OR UPDATE OF fpm_wor ON app.pole FOR EACH ROW EXECUTE FUNCTION app.pole_work_order_mirror();


--
-- Name: sheath_length trg_sheath_length_bi; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_sheath_length_bi BEFORE INSERT OR UPDATE ON app.sheath_length FOR EACH ROW EXECUTE FUNCTION app.sheath_length_biu();


--
-- Name: cable_span trg_span_support; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_span_support AFTER INSERT OR UPDATE OF geom_snap ON app.cable_span FOR EACH ROW EXECUTE FUNCTION app.trg_span_support();


--
-- Name: cable_span_work_order trg_span_wo_link_to_column; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_span_wo_link_to_column AFTER INSERT OR DELETE OR UPDATE OF is_primary, work_order ON app.cable_span_work_order FOR EACH ROW EXECUTE FUNCTION app.work_order_link_to_column('cable_span', 'cable_span_id', 'cable_span_id');


--
-- Name: splice_join trg_splice_join_no_double_use; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_splice_join_no_double_use BEFORE INSERT OR UPDATE ON app.splice_join FOR EACH ROW EXECUTE FUNCTION app.splice_join_no_double_use();


--
-- Name: messenger_strand trg_strand_support; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_strand_support AFTER INSERT OR UPDATE OF geom ON app.messenger_strand FOR EACH ROW EXECUTE FUNCTION app.trg_strand_support();


--
-- Name: v_cable_span_edit trg_v_cable_span_edit_del; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_v_cable_span_edit_del INSTEAD OF DELETE ON app.v_cable_span_edit FOR EACH ROW EXECUTE FUNCTION app.v_cable_span_edit_del();


--
-- Name: v_cable_span_edit trg_v_cable_span_edit_ins; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_v_cable_span_edit_ins INSTEAD OF INSERT ON app.v_cable_span_edit FOR EACH ROW EXECUTE FUNCTION app.v_cable_span_edit_ins();


--
-- Name: v_cable_span_edit trg_v_cable_span_edit_upd; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_v_cable_span_edit_upd INSTEAD OF UPDATE ON app.v_cable_span_edit FOR EACH ROW EXECUTE FUNCTION app.v_cable_span_edit_upd();


--
-- Name: v_fosc_device_edit trg_v_fosc_device_del; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_v_fosc_device_del INSTEAD OF DELETE ON app.v_fosc_device_edit FOR EACH ROW EXECUTE FUNCTION app.v_fosc_device_edit_del();


--
-- Name: v_fosc_device_edit trg_v_fosc_device_ins; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_v_fosc_device_ins INSTEAD OF INSERT ON app.v_fosc_device_edit FOR EACH ROW EXECUTE FUNCTION app.v_fosc_device_edit_ins();


--
-- Name: v_fosc_device_edit trg_v_fosc_device_upd; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_v_fosc_device_upd INSTEAD OF UPDATE ON app.v_fosc_device_edit FOR EACH ROW EXECUTE FUNCTION app.v_fosc_device_edit_upd();


--
-- Name: splice_join trg_validate_slack_loop_splice; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER trg_validate_slack_loop_splice BEFORE INSERT OR UPDATE ON app.splice_join FOR EACH ROW EXECUTE FUNCTION app.trg_validate_slack_loop_splice();


--
-- Name: v_fosc_qgis v_fosc_qgis_del; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER v_fosc_qgis_del INSTEAD OF DELETE ON app.v_fosc_qgis FOR EACH ROW EXECUTE FUNCTION app.v_fosc_qgis_del();


--
-- Name: v_fosc_qgis v_fosc_qgis_ins; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER v_fosc_qgis_ins INSTEAD OF INSERT ON app.v_fosc_qgis FOR EACH ROW EXECUTE FUNCTION app.v_fosc_qgis_ins();


--
-- Name: v_fosc_qgis v_fosc_qgis_upd; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER v_fosc_qgis_upd INSTEAD OF UPDATE ON app.v_fosc_qgis FOR EACH ROW EXECUTE FUNCTION app.v_fosc_qgis_upd();


--
-- Name: v_pole_qgis v_pole_qgis_no_del; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER v_pole_qgis_no_del INSTEAD OF DELETE ON app.v_pole_qgis FOR EACH ROW EXECUTE FUNCTION app.v_pole_qgis_read_only();


--
-- Name: v_pole_qgis v_pole_qgis_no_ins; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER v_pole_qgis_no_ins INSTEAD OF INSERT ON app.v_pole_qgis FOR EACH ROW EXECUTE FUNCTION app.v_pole_qgis_read_only();


--
-- Name: v_pole_qgis v_pole_qgis_no_upd; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER v_pole_qgis_no_upd INSTEAD OF UPDATE ON app.v_pole_qgis FOR EACH ROW EXECUTE FUNCTION app.v_pole_qgis_read_only();


--
-- Name: conduit zz_conduit_orphan_terminations; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER zz_conduit_orphan_terminations AFTER DELETE OR UPDATE OF geom ON app.conduit FOR EACH STATEMENT EXECUTE FUNCTION app.trg_conduit_orphan_terminations();


--
-- Name: fosc zz_fosc_carry_termination; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER zz_fosc_carry_termination AFTER UPDATE OF location ON app.fosc FOR EACH ROW EXECUTE FUNCTION app.trg_object_moved_carry_termination();


--
-- Name: pole zz_pole_carry_termination; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER zz_pole_carry_termination AFTER UPDATE OF geom ON app.pole FOR EACH ROW EXECUTE FUNCTION app.trg_object_moved_carry_termination();


--
-- Name: pop zz_pop_carry_termination; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER zz_pop_carry_termination AFTER UPDATE OF location ON app.pop FOR EACH ROW EXECUTE FUNCTION app.trg_object_moved_carry_termination();


--
-- Name: conduit_termination zz_termination_carry_conduit_ends; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER zz_termination_carry_conduit_ends AFTER UPDATE OF geom ON app.conduit_termination FOR EACH ROW EXECUTE FUNCTION app.trg_attached_point_moved();


--
-- Name: vault zz_vault_carry_conduit_ends; Type: TRIGGER; Schema: app; Owner: -
--

CREATE TRIGGER zz_vault_carry_conduit_ends AFTER UPDATE OF geom ON app.vault FOR EACH ROW EXECUTE FUNCTION app.trg_attached_point_moved();


--
-- Name: actor actor_app_user_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.actor
    ADD CONSTRAINT actor_app_user_id_fkey FOREIGN KEY (app_user_id) REFERENCES app.app_user(app_user_id);


--
-- Name: anchor anchor_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.anchor
    ADD CONSTRAINT anchor_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: anchor anchor_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.anchor
    ADD CONSTRAINT anchor_pole_fid_fkey FOREIGN KEY (pole_fid) REFERENCES app.pole(fid) ON DELETE SET NULL;


--
-- Name: anchor anchor_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.anchor
    ADD CONSTRAINT anchor_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: app_user app_user_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.app_user
    ADD CONSTRAINT app_user_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: app_user app_user_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.app_user
    ADD CONSTRAINT app_user_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: asset_density_hex asset_density_hex_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.asset_density_hex
    ADD CONSTRAINT asset_density_hex_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: asset_density_hex asset_density_hex_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.asset_density_hex
    ADD CONSTRAINT asset_density_hex_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: asset_density_meta asset_density_meta_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.asset_density_meta
    ADD CONSTRAINT asset_density_meta_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: asset_density_meta asset_density_meta_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.asset_density_meta
    ADD CONSTRAINT asset_density_meta_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: audit_log audit_log_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.audit_log
    ADD CONSTRAINT audit_log_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES app.app_user(app_user_id) ON DELETE SET NULL;


--
-- Name: audit_log audit_log_change_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.audit_log
    ADD CONSTRAINT audit_log_change_id_fkey FOREIGN KEY (change_id) REFERENCES app.change_set(change_id);


--
-- Name: auth_identity auth_identity_app_user_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.auth_identity
    ADD CONSTRAINT auth_identity_app_user_id_fkey FOREIGN KEY (app_user_id) REFERENCES app.app_user(app_user_id) ON DELETE CASCADE;


--
-- Name: auth_identity auth_identity_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.auth_identity
    ADD CONSTRAINT auth_identity_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: auth_identity auth_identity_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.auth_identity
    ADD CONSTRAINT auth_identity_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: bell_permit bell_permit_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit
    ADD CONSTRAINT bell_permit_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: bell_permit_pole bell_permit_pole_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit_pole
    ADD CONSTRAINT bell_permit_pole_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: bell_permit_pole bell_permit_pole_permit_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit_pole
    ADD CONSTRAINT bell_permit_pole_permit_id_fkey FOREIGN KEY (permit_id) REFERENCES app.bell_permit(id) ON DELETE CASCADE;


--
-- Name: bell_permit_pole bell_permit_pole_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit_pole
    ADD CONSTRAINT bell_permit_pole_pole_fid_fkey FOREIGN KEY (pole_fid) REFERENCES app.pole(fid) ON DELETE SET NULL;


--
-- Name: bell_permit_pole bell_permit_pole_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit_pole
    ADD CONSTRAINT bell_permit_pole_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: bell_permit bell_permit_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit
    ADD CONSTRAINT bell_permit_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: bom_extra bom_extra_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_extra
    ADD CONSTRAINT bom_extra_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: bom_extra bom_extra_material_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_extra
    ADD CONSTRAINT bom_extra_material_id_fkey FOREIGN KEY (material_id) REFERENCES app.bom_material(id) ON DELETE SET NULL;


--
-- Name: bom_extra bom_extra_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_extra
    ADD CONSTRAINT bom_extra_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: bom_material bom_material_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_material
    ADD CONSTRAINT bom_material_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: bom_material bom_material_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_material
    ADD CONSTRAINT bom_material_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: bom_price bom_price_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_price
    ADD CONSTRAINT bom_price_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: bom_price bom_price_material_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_price
    ADD CONSTRAINT bom_price_material_id_fkey FOREIGN KEY (material_id) REFERENCES app.bom_material(id) ON DELETE CASCADE;


--
-- Name: bom_price bom_price_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bom_price
    ADD CONSTRAINT bom_price_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: building_anchor building_anchor_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.building_anchor
    ADD CONSTRAINT building_anchor_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: building_anchor building_anchor_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.building_anchor
    ADD CONSTRAINT building_anchor_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: cable cable_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable
    ADD CONSTRAINT cable_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: cable cable_material_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable
    ADD CONSTRAINT cable_material_id_fkey FOREIGN KEY (material_id) REFERENCES app.bom_material(id) ON DELETE SET NULL;


--
-- Name: cable_span cable_span_cable_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT cable_span_cable_id_fkey FOREIGN KEY (cable_id) REFERENCES app.cable(cable_id) ON DELETE CASCADE;


--
-- Name: cable_span_conduit cable_span_conduit_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_conduit
    ADD CONSTRAINT cable_span_conduit_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id) ON DELETE CASCADE;


--
-- Name: cable_span_conduit cable_span_conduit_conduit_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_conduit
    ADD CONSTRAINT cable_span_conduit_conduit_id_fkey FOREIGN KEY (conduit_id) REFERENCES app.conduit(conduit_id) ON DELETE CASCADE;


--
-- Name: cable_span_conduit cable_span_conduit_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_conduit
    ADD CONSTRAINT cable_span_conduit_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span_conduit cable_span_conduit_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_conduit
    ADD CONSTRAINT cable_span_conduit_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span cable_span_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT cable_span_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span cable_span_from_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT cable_span_from_fosc_id_fkey FOREIGN KEY (from_fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE RESTRICT;


--
-- Name: cable_span_strand cable_span_strand_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_strand
    ADD CONSTRAINT cable_span_strand_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id) ON DELETE CASCADE;


--
-- Name: cable_span_strand cable_span_strand_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_strand
    ADD CONSTRAINT cable_span_strand_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span_strand cable_span_strand_strand_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_strand
    ADD CONSTRAINT cable_span_strand_strand_id_fkey FOREIGN KEY (strand_id) REFERENCES app.messenger_strand(id) ON DELETE CASCADE;


--
-- Name: cable_span_strand cable_span_strand_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_strand
    ADD CONSTRAINT cable_span_strand_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span_tag cable_span_tag_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_tag
    ADD CONSTRAINT cable_span_tag_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id) ON DELETE CASCADE;


--
-- Name: cable_span_tag cable_span_tag_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_tag
    ADD CONSTRAINT cable_span_tag_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span_tag cable_span_tag_span_tag_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_tag
    ADD CONSTRAINT cable_span_tag_span_tag_id_fkey FOREIGN KEY (span_tag_id) REFERENCES app.span_tag(span_tag_id) ON DELETE CASCADE;


--
-- Name: cable_span_tag cable_span_tag_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_tag
    ADD CONSTRAINT cable_span_tag_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span cable_span_to_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT cable_span_to_fosc_id_fkey FOREIGN KEY (to_fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE RESTRICT;


--
-- Name: cable_span cable_span_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT cable_span_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span_work_order cable_span_work_order_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order
    ADD CONSTRAINT cable_span_work_order_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id) ON DELETE CASCADE;


--
-- Name: cable_span_work_order cable_span_work_order_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order
    ADD CONSTRAINT cable_span_work_order_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: cable_span_work_order cable_span_work_order_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order
    ADD CONSTRAINT cable_span_work_order_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: cable cable_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable
    ADD CONSTRAINT cable_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: change_set change_set_actor_code_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.change_set
    ADD CONSTRAINT change_set_actor_code_fkey FOREIGN KEY (actor_code) REFERENCES app.actor(actor_code);


--
-- Name: change_set change_set_app_user_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.change_set
    ADD CONSTRAINT change_set_app_user_id_fkey FOREIGN KEY (app_user_id) REFERENCES app.app_user(app_user_id);


--
-- Name: circuit circuit_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit
    ADD CONSTRAINT circuit_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: circuit_fibre circuit_fibre_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre
    ADD CONSTRAINT circuit_fibre_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id) ON DELETE RESTRICT;


--
-- Name: circuit_fibre circuit_fibre_circuit_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre
    ADD CONSTRAINT circuit_fibre_circuit_id_fkey FOREIGN KEY (circuit_id) REFERENCES app.circuit(circuit_id) ON DELETE CASCADE;


--
-- Name: circuit_fibre circuit_fibre_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre
    ADD CONSTRAINT circuit_fibre_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: circuit_fibre circuit_fibre_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit_fibre
    ADD CONSTRAINT circuit_fibre_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: circuit circuit_protected_by_link_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit
    ADD CONSTRAINT circuit_protected_by_link_fkey FOREIGN KEY (protected_by_link) REFERENCES app.lnd_link(link_id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: circuit circuit_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.circuit
    ADD CONSTRAINT circuit_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: closure_type closure_type_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.closure_type
    ADD CONSTRAINT closure_type_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: closure_type closure_type_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.closure_type
    ADD CONSTRAINT closure_type_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit conduit_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit conduit_end_term_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_end_term_id_fkey FOREIGN KEY (end_term_id) REFERENCES app.conduit_termination(termination_id) ON DELETE RESTRICT;


--
-- Name: conduit conduit_end_vault_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_end_vault_id_fkey FOREIGN KEY (end_vault_id) REFERENCES app.vault(vault_id) ON DELETE RESTRICT;


--
-- Name: conduit conduit_microduct_type_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_microduct_type_id_fkey FOREIGN KEY (microduct_type_id) REFERENCES app.microduct_type(id) ON DELETE SET NULL;


--
-- Name: conduit conduit_start_term_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_start_term_id_fkey FOREIGN KEY (start_term_id) REFERENCES app.conduit_termination(termination_id) ON DELETE RESTRICT;


--
-- Name: conduit conduit_start_vault_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_start_vault_id_fkey FOREIGN KEY (start_vault_id) REFERENCES app.vault(vault_id) ON DELETE RESTRICT;


--
-- Name: conduit_termination conduit_termination_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit_termination conduit_termination_fpm_wor_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_fpm_wor_fkey FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: conduit_termination conduit_termination_ref_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_ref_fosc_id_fkey FOREIGN KEY (ref_fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE SET NULL;


--
-- Name: conduit_termination conduit_termination_ref_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_ref_pole_fid_fkey FOREIGN KEY (ref_pole_fid) REFERENCES app.pole(fid) ON DELETE SET NULL;


--
-- Name: conduit_termination conduit_termination_ref_pop_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_ref_pop_id_fkey FOREIGN KEY (ref_pop_id) REFERENCES app.pop(pop_id) ON DELETE SET NULL;


--
-- Name: conduit_termination conduit_termination_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_termination
    ADD CONSTRAINT conduit_termination_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit conduit_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT conduit_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit_work_order conduit_work_order_conduit_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order
    ADD CONSTRAINT conduit_work_order_conduit_id_fkey FOREIGN KEY (conduit_id) REFERENCES app.conduit(conduit_id) ON DELETE CASCADE;


--
-- Name: conduit_work_order conduit_work_order_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order
    ADD CONSTRAINT conduit_work_order_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit_work_order conduit_work_order_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order
    ADD CONSTRAINT conduit_work_order_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: conduit_work_order conduit_work_order_work_order_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit_work_order
    ADD CONSTRAINT conduit_work_order_work_order_fkey FOREIGN KEY (work_order) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: dhcp_lease_cache dhcp_lease_cache_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dhcp_lease_cache
    ADD CONSTRAINT dhcp_lease_cache_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: dhcp_lease_cache dhcp_lease_cache_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dhcp_lease_cache
    ADD CONSTRAINT dhcp_lease_cache_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: dimension dimension_a_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dimension
    ADD CONSTRAINT dimension_a_pole_fid_fkey FOREIGN KEY (a_pole_fid) REFERENCES app.pole(fid) ON DELETE SET NULL;


--
-- Name: dimension dimension_b_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dimension
    ADD CONSTRAINT dimension_b_pole_fid_fkey FOREIGN KEY (b_pole_fid) REFERENCES app.pole(fid) ON DELETE SET NULL;


--
-- Name: dimension dimension_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dimension
    ADD CONSTRAINT dimension_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: dimension dimension_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.dimension
    ADD CONSTRAINT dimension_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: document document_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document
    ADD CONSTRAINT document_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: document_pid document_pid_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_pid
    ADD CONSTRAINT document_pid_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: document_pid document_pid_document_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_pid
    ADD CONSTRAINT document_pid_document_id_fkey FOREIGN KEY (document_id) REFERENCES app.document(document_id) ON DELETE CASCADE;


--
-- Name: document_pid document_pid_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_pid
    ADD CONSTRAINT document_pid_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: document_text document_text_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_text
    ADD CONSTRAINT document_text_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: document_text document_text_document_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_text
    ADD CONSTRAINT document_text_document_id_fkey FOREIGN KEY (document_id) REFERENCES app.document(document_id) ON DELETE CASCADE;


--
-- Name: document_text document_text_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document_text
    ADD CONSTRAINT document_text_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: document document_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.document
    ADD CONSTRAINT document_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: easement easement_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.easement
    ADD CONSTRAINT easement_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: easement easement_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.easement
    ADD CONSTRAINT easement_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: bell_permit fk_bell_permit_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.bell_permit
    ADD CONSTRAINT fk_bell_permit_fpm_permit FOREIGN KEY (fpm_permit_num) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: building_anchor fk_building_anchor_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.building_anchor
    ADD CONSTRAINT fk_building_anchor_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: cable_span fk_cable_span_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span
    ADD CONSTRAINT fk_cable_span_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: cable_span_work_order fk_cable_span_work_order_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.cable_span_work_order
    ADD CONSTRAINT fk_cable_span_work_order_fpm_permit FOREIGN KEY (work_order) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: conduit fk_conduit_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.conduit
    ADD CONSTRAINT fk_conduit_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: fosc fk_fosc_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc
    ADD CONSTRAINT fk_fosc_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: fosc_work_order fk_fosc_work_order_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order
    ADD CONSTRAINT fk_fosc_work_order_fpm_permit FOREIGN KEY (work_order) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: messenger_strand fk_messenger_strand_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT fk_messenger_strand_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: pole fk_pole_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole
    ADD CONSTRAINT fk_pole_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: pole fk_pole_owner; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole
    ADD CONSTRAINT fk_pole_owner FOREIGN KEY (owner) REFERENCES app.pole_owner(owner) ON UPDATE CASCADE;


--
-- Name: pole_work_order fk_pole_work_order_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order
    ADD CONSTRAINT fk_pole_work_order_fpm_permit FOREIGN KEY (work_order) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: uguard fk_uguard_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uguard
    ADD CONSTRAINT fk_uguard_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: vault fk_vault_fpm_permit; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.vault
    ADD CONSTRAINT fk_vault_fpm_permit FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: flextin_run flextin_run_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.flextin_run
    ADD CONSTRAINT flextin_run_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: flextin_run flextin_run_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.flextin_run
    ADD CONSTRAINT flextin_run_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE CASCADE;


--
-- Name: flextin_run flextin_run_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.flextin_run
    ADD CONSTRAINT flextin_run_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: fosc fosc_closure_type_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc
    ADD CONSTRAINT fosc_closure_type_id_fkey FOREIGN KEY (closure_type_id) REFERENCES app.closure_type(closure_type_id);


--
-- Name: fosc fosc_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc
    ADD CONSTRAINT fosc_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: fosc_device fosc_device_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: fosc_device fosc_device_fk_fosc; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_fk_fosc FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE CASCADE;


--
-- Name: fosc_device fosc_device_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id);


--
-- Name: fosc_device fosc_device_parent_device_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_parent_device_id_fkey FOREIGN KEY (parent_device_id) REFERENCES app.fosc_device(fosc_device_id) ON DELETE SET NULL;


--
-- Name: fosc_device fosc_device_pop_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_pop_id_fkey FOREIGN KEY (pop_id) REFERENCES app.pop(pop_id) ON DELETE CASCADE;


--
-- Name: fosc_device fosc_device_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_device
    ADD CONSTRAINT fosc_device_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: fosc fosc_pop_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc
    ADD CONSTRAINT fosc_pop_id_fkey FOREIGN KEY (pop_id) REFERENCES app.pop(pop_id) ON DELETE SET NULL;


--
-- Name: fosc fosc_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc
    ADD CONSTRAINT fosc_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: fosc_work_order fosc_work_order_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order
    ADD CONSTRAINT fosc_work_order_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: fosc_work_order fosc_work_order_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order
    ADD CONSTRAINT fosc_work_order_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE CASCADE;


--
-- Name: fosc_work_order fosc_work_order_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fosc_work_order
    ADD CONSTRAINT fosc_work_order_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: fpm_permit fpm_permit_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit
    ADD CONSTRAINT fpm_permit_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: fpm_permit_stage fpm_permit_stage_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit_stage
    ADD CONSTRAINT fpm_permit_stage_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: fpm_permit_stage fpm_permit_stage_permit_num_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit_stage
    ADD CONSTRAINT fpm_permit_stage_permit_num_fkey FOREIGN KEY (permit_num) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: fpm_permit_stage fpm_permit_stage_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit_stage
    ADD CONSTRAINT fpm_permit_stage_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: fpm_permit fpm_permit_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.fpm_permit
    ADD CONSTRAINT fpm_permit_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: geonb_sync geonb_sync_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.geonb_sync
    ADD CONSTRAINT geonb_sync_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: geonb_sync geonb_sync_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.geonb_sync
    ADD CONSTRAINT geonb_sync_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: lead_association lead_association_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lead_association
    ADD CONSTRAINT lead_association_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: lead_association lead_association_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lead_association
    ADD CONSTRAINT lead_association_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE RESTRICT;


--
-- Name: lead_association lead_association_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lead_association
    ADD CONSTRAINT lead_association_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_access_tech lnd_access_tech_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_access_tech
    ADD CONSTRAINT lnd_access_tech_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_access_tech lnd_access_tech_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_access_tech
    ADD CONSTRAINT lnd_access_tech_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_external lnd_external_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_external
    ADD CONSTRAINT lnd_external_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_external lnd_external_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_external
    ADD CONSTRAINT lnd_external_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_link lnd_link_a_ext_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_a_ext_id_fkey FOREIGN KEY (a_ext_id) REFERENCES app.lnd_external(ext_id);


--
-- Name: lnd_link lnd_link_a_pop_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_a_pop_id_fkey FOREIGN KEY (a_pop_id) REFERENCES app.pop(pop_id) ON DELETE CASCADE;


--
-- Name: lnd_link lnd_link_b_ext_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_b_ext_id_fkey FOREIGN KEY (b_ext_id) REFERENCES app.lnd_external(ext_id);


--
-- Name: lnd_link lnd_link_b_pop_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_b_pop_id_fkey FOREIGN KEY (b_pop_id) REFERENCES app.pop(pop_id) ON DELETE CASCADE;


--
-- Name: lnd_link lnd_link_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_link lnd_link_fpm_wor_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_fpm_wor_fkey FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: lnd_link lnd_link_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_link
    ADD CONSTRAINT lnd_link_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_route lnd_route_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_route
    ADD CONSTRAINT lnd_route_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: lnd_route lnd_route_fpm_wor_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_route
    ADD CONSTRAINT lnd_route_fpm_wor_fkey FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: lnd_route lnd_route_main_cable_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_route
    ADD CONSTRAINT lnd_route_main_cable_id_fkey FOREIGN KEY (main_cable_id) REFERENCES app.cable(cable_id) ON DELETE CASCADE;


--
-- Name: lnd_route lnd_route_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.lnd_route
    ADD CONSTRAINT lnd_route_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: loss_param loss_param_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.loss_param
    ADD CONSTRAINT loss_param_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: loss_param loss_param_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.loss_param
    ADD CONSTRAINT loss_param_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: map_note map_note_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_note
    ADD CONSTRAINT map_note_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: map_note map_note_fpm_wor_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_note
    ADD CONSTRAINT map_note_fpm_wor_fkey FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: map_note map_note_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_note
    ADD CONSTRAINT map_note_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: map_plan map_plan_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_plan
    ADD CONSTRAINT map_plan_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: map_plan map_plan_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.map_plan
    ADD CONSTRAINT map_plan_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: messenger_strand messenger_strand_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: messenger_strand messenger_strand_from_anchor_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_from_anchor_id_fkey FOREIGN KEY (from_anchor_id) REFERENCES app.building_anchor(id) ON DELETE RESTRICT;


--
-- Name: messenger_strand messenger_strand_from_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_from_pole_fid_fkey FOREIGN KEY (from_pole_fid) REFERENCES app.pole(fid) ON DELETE RESTRICT;


--
-- Name: messenger_strand messenger_strand_to_anchor_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_to_anchor_id_fkey FOREIGN KEY (to_anchor_id) REFERENCES app.building_anchor(id) ON DELETE RESTRICT;


--
-- Name: messenger_strand messenger_strand_to_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_to_pole_fid_fkey FOREIGN KEY (to_pole_fid) REFERENCES app.pole(fid) ON DELETE RESTRICT;


--
-- Name: messenger_strand messenger_strand_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.messenger_strand
    ADD CONSTRAINT messenger_strand_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: microduct_type microduct_type_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.microduct_type
    ADD CONSTRAINT microduct_type_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: microduct_type microduct_type_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.microduct_type
    ADD CONSTRAINT microduct_type_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_add nb_civic_address_add_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_add
    ADD CONSTRAINT nb_civic_address_add_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_add nb_civic_address_add_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_add
    ADD CONSTRAINT nb_civic_address_add_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address nb_civic_address_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address
    ADD CONSTRAINT nb_civic_address_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_ignore nb_civic_address_ignore_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_ignore
    ADD CONSTRAINT nb_civic_address_ignore_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_ignore nb_civic_address_ignore_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_ignore
    ADD CONSTRAINT nb_civic_address_ignore_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_move nb_civic_address_move_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_move
    ADD CONSTRAINT nb_civic_address_move_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_move nb_civic_address_move_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_move
    ADD CONSTRAINT nb_civic_address_move_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_stage nb_civic_address_stage_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_stage
    ADD CONSTRAINT nb_civic_address_stage_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address_stage nb_civic_address_stage_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address_stage
    ADD CONSTRAINT nb_civic_address_stage_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_civic_address nb_civic_address_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_civic_address
    ADD CONSTRAINT nb_civic_address_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_community nb_community_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_community
    ADD CONSTRAINT nb_community_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_community nb_community_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_community
    ADD CONSTRAINT nb_community_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_parcel nb_parcel_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_parcel
    ADD CONSTRAINT nb_parcel_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_parcel_stage nb_parcel_stage_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_parcel_stage
    ADD CONSTRAINT nb_parcel_stage_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_parcel_stage nb_parcel_stage_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_parcel_stage
    ADD CONSTRAINT nb_parcel_stage_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_parcel nb_parcel_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_parcel
    ADD CONSTRAINT nb_parcel_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland nb_wetland_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland
    ADD CONSTRAINT nb_wetland_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland_psw nb_wetland_psw_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_psw
    ADD CONSTRAINT nb_wetland_psw_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland_psw_stage nb_wetland_psw_stage_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_psw_stage
    ADD CONSTRAINT nb_wetland_psw_stage_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland_psw_stage nb_wetland_psw_stage_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_psw_stage
    ADD CONSTRAINT nb_wetland_psw_stage_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland_psw nb_wetland_psw_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_psw
    ADD CONSTRAINT nb_wetland_psw_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland_stage nb_wetland_stage_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_stage
    ADD CONSTRAINT nb_wetland_stage_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland_stage nb_wetland_stage_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland_stage
    ADD CONSTRAINT nb_wetland_stage_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: nb_wetland nb_wetland_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.nb_wetland
    ADD CONSTRAINT nb_wetland_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: ont_association ont_association_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.ont_association
    ADD CONSTRAINT ont_association_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: ont_association ont_association_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.ont_association
    ADD CONSTRAINT ont_association_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE RESTRICT;


--
-- Name: ont_association ont_association_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.ont_association
    ADD CONSTRAINT ont_association_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: otdr_result otdr_result_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id);


--
-- Name: otdr_result otdr_result_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: otdr_result otdr_result_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE SET NULL;


--
-- Name: otdr_result otdr_result_linked_by_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_linked_by_fkey FOREIGN KEY (linked_by) REFERENCES app.app_user(app_user_id);


--
-- Name: otdr_result otdr_result_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: otdr_result otdr_result_uploaded_by_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.otdr_result
    ADD CONSTRAINT otdr_result_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES app.app_user(app_user_id);


--
-- Name: parcel_owner parcel_owner_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.parcel_owner
    ADD CONSTRAINT parcel_owner_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: parcel_owner parcel_owner_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.parcel_owner
    ADD CONSTRAINT parcel_owner_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: pole pole_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole
    ADD CONSTRAINT pole_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: pole_owner pole_owner_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_owner
    ADD CONSTRAINT pole_owner_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: pole_owner pole_owner_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_owner
    ADD CONSTRAINT pole_owner_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: pole pole_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole
    ADD CONSTRAINT pole_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: pole_work_order pole_work_order_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order
    ADD CONSTRAINT pole_work_order_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: pole_work_order pole_work_order_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order
    ADD CONSTRAINT pole_work_order_pole_fid_fkey FOREIGN KEY (pole_fid) REFERENCES app.pole(fid) ON DELETE CASCADE;


--
-- Name: pole_work_order pole_work_order_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pole_work_order
    ADD CONSTRAINT pole_work_order_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: pop pop_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pop
    ADD CONSTRAINT pop_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: pop pop_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.pop
    ADD CONSTRAINT pop_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: road_coverage road_coverage_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage
    ADD CONSTRAINT road_coverage_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: road_coverage_service road_coverage_service_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage_service
    ADD CONSTRAINT road_coverage_service_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: road_coverage_service road_coverage_service_seg_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage_service
    ADD CONSTRAINT road_coverage_service_seg_id_fkey FOREIGN KEY (seg_id) REFERENCES app.road_coverage(seg_id) ON DELETE CASCADE;


--
-- Name: road_coverage_service road_coverage_service_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage_service
    ADD CONSTRAINT road_coverage_service_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: road_coverage road_coverage_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.road_coverage
    ADD CONSTRAINT road_coverage_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: route_coincidence_cache route_coincidence_cache_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.route_coincidence_cache
    ADD CONSTRAINT route_coincidence_cache_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: route_coincidence_cache route_coincidence_cache_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.route_coincidence_cache
    ADD CONSTRAINT route_coincidence_cache_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: route_coincidence_version route_coincidence_version_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.route_coincidence_version
    ADD CONSTRAINT route_coincidence_version_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: route_coincidence_version route_coincidence_version_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.route_coincidence_version
    ADD CONSTRAINT route_coincidence_version_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: salisbury_zoning salisbury_zoning_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.salisbury_zoning
    ADD CONSTRAINT salisbury_zoning_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: salisbury_zoning salisbury_zoning_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.salisbury_zoning
    ADD CONSTRAINT salisbury_zoning_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: schema_migrations schema_migrations_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.schema_migrations
    ADD CONSTRAINT schema_migrations_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: schema_migrations schema_migrations_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.schema_migrations
    ADD CONSTRAINT schema_migrations_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: service_association service_association_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.service_association
    ADD CONSTRAINT service_association_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: service_association service_association_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.service_association
    ADD CONSTRAINT service_association_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE CASCADE;


--
-- Name: service_association service_association_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.service_association
    ADD CONSTRAINT service_association_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: sheath_length sheath_length_cable_span_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.sheath_length
    ADD CONSTRAINT sheath_length_cable_span_id_fkey FOREIGN KEY (cable_span_id) REFERENCES app.cable_span(cable_span_id) ON DELETE CASCADE;


--
-- Name: sheath_length sheath_length_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.sheath_length
    ADD CONSTRAINT sheath_length_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: sheath_length sheath_length_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.sheath_length
    ADD CONSTRAINT sheath_length_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: span_tag span_tag_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.span_tag
    ADD CONSTRAINT span_tag_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: span_tag span_tag_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.span_tag
    ADD CONSTRAINT span_tag_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: splice_join splice_join_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.splice_join
    ADD CONSTRAINT splice_join_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: splice_join splice_join_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.splice_join
    ADD CONSTRAINT splice_join_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id);


--
-- Name: splice_join splice_join_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.splice_join
    ADD CONSTRAINT splice_join_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: static_customer static_customer_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.static_customer
    ADD CONSTRAINT static_customer_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: static_customer static_customer_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.static_customer
    ADD CONSTRAINT static_customer_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: survey_draft survey_draft_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.survey_draft
    ADD CONSTRAINT survey_draft_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: survey_draft survey_draft_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.survey_draft
    ADD CONSTRAINT survey_draft_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: timeline_entry timeline_entry_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_entry
    ADD CONSTRAINT timeline_entry_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: timeline_entry timeline_entry_fosc_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_entry
    ADD CONSTRAINT timeline_entry_fosc_id_fkey FOREIGN KEY (fosc_id) REFERENCES app.fosc(fosc_id) ON DELETE CASCADE;


--
-- Name: timeline_entry timeline_entry_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_entry
    ADD CONSTRAINT timeline_entry_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: timeline_media timeline_media_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_media
    ADD CONSTRAINT timeline_media_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: timeline_media timeline_media_timeline_entry_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_media
    ADD CONSTRAINT timeline_media_timeline_entry_id_fkey FOREIGN KEY (timeline_entry_id) REFERENCES app.timeline_entry(timeline_entry_id) ON DELETE CASCADE;


--
-- Name: timeline_media timeline_media_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.timeline_media
    ADD CONSTRAINT timeline_media_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: uguard uguard_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uguard
    ADD CONSTRAINT uguard_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: uguard uguard_pole_fid_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uguard
    ADD CONSTRAINT uguard_pole_fid_fkey FOREIGN KEY (pole_fid) REFERENCES app.pole(fid) ON DELETE SET NULL;


--
-- Name: uguard uguard_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uguard
    ADD CONSTRAINT uguard_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: uisp_cache uisp_cache_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_cache
    ADD CONSTRAINT uisp_cache_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: uisp_cache uisp_cache_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_cache
    ADD CONSTRAINT uisp_cache_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: uisp_common_event uisp_common_event_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_common_event
    ADD CONSTRAINT uisp_common_event_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: uisp_common_event uisp_common_event_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_common_event
    ADD CONSTRAINT uisp_common_event_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: uisp_device_event uisp_device_event_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_device_event
    ADD CONSTRAINT uisp_device_event_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: uisp_device_event uisp_device_event_mass_event_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_device_event
    ADD CONSTRAINT uisp_device_event_mass_event_id_fkey FOREIGN KEY (common_event_id) REFERENCES app.uisp_common_event(common_event_id) ON DELETE SET NULL;


--
-- Name: uisp_device_event uisp_device_event_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.uisp_device_event
    ADD CONSTRAINT uisp_device_event_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: vault vault_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.vault
    ADD CONSTRAINT vault_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: vault vault_model_id_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.vault
    ADD CONSTRAINT vault_model_id_fkey FOREIGN KEY (model_id) REFERENCES app.bom_material(id) ON DELETE SET NULL;


--
-- Name: vault vault_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.vault
    ADD CONSTRAINT vault_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- Name: wifi_ap wifi_ap_created_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.wifi_ap
    ADD CONSTRAINT wifi_ap_created_change_fkey FOREIGN KEY (created_change) REFERENCES app.change_set(change_id);


--
-- Name: wifi_ap wifi_ap_fpm_wor_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.wifi_ap
    ADD CONSTRAINT wifi_ap_fpm_wor_fkey FOREIGN KEY (fpm_wor) REFERENCES app.fpm_permit(permit_num) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: wifi_ap wifi_ap_updated_change_fkey; Type: FK CONSTRAINT; Schema: app; Owner: -
--

ALTER TABLE ONLY app.wifi_ap
    ADD CONSTRAINT wifi_ap_updated_change_fkey FOREIGN KEY (updated_change) REFERENCES app.change_set(change_id);


--
-- PostgreSQL database dump complete
--

