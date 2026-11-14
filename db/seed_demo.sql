-- Demo data for the shared edition: one made-up road, a handful of assets and a WiFi access point, so a new install
-- shows something on the map. Nothing here is real. Load after db/schema.sql and db/reference_data.sql; it is optional.
-- Delete it all again from the map (select an asset, Delete) or start from an empty database.

BEGIN;

INSERT INTO app.fpm_permit (permit_num, notes) VALUES ('2026-1-Demo', 'Demo work order: the made-up road in the sample data');

-- six poles about 40 m apart on "Demo Road" (a field in New Brunswick; move them anywhere with the Move button)
INSERT INTO app.pole (geom, owner, type, pole_class, street, fpm_wor, "nbp#")
SELECT ST_SetSRID(ST_MakePoint(-66.3000 + (i - 1) * 0.00048, 45.8000), 4326),
       CASE WHEN i <= 3 THEN 'Bell Aliant' ELSE 'NB Power' END,
       'wood', '3', 'Demo Road', '2026-1-Demo', format('9001_D%s', i)
  FROM generate_series(1, 6) AS i;

INSERT INTO app.messenger_strand (from_pole_fid, to_pole_fid, from_side, from_height, to_side, to_height, status, fpm_wor, geom)
SELECT a.fid, b.fid, 'road', 'bottom', 'road', 'bottom', 'active', '2026-1-Demo',
       ST_SetSRID(ST_MakeLine(a.geom, b.geom), 4326)
  FROM app.pole a
  JOIN app.pole b ON b."nbp#" = format('9001_D%s', right(a."nbp#", 1)::int + 1)
 WHERE a.street = 'Demo Road';

-- a POP with its patch panel, a splice closure on pole 3, and a second closure on pole 6
INSERT INTO app.pop (pop_id, name, notes, location)
VALUES (9001, 'Demo POP', 'Sample point of presence', ST_SetSRID(ST_MakePoint(-66.30015, 45.80010), 4326));

INSERT INTO app.fosc (splice_num, status, kind, location, closure_type_id, pop_id, fpm_wor, enclosure_rating, notes)
VALUES ('DMO0001', 'active', 'patch_panel', ST_SetSRID(ST_MakePoint(-66.30015, 45.80010), 4326),
        (SELECT min(closure_type_id) FROM app.closure_type), 9001, '2026-1-Demo', 'inside', 'Patch panel in the demo POP');
INSERT INTO app.fosc (splice_num, status, kind, location, closure_type_id, fpm_wor, notes)
SELECT 'DMO0002', 'active', 'closure', geom, (SELECT min(closure_type_id) FROM app.closure_type), '2026-1-Demo', 'Demo splice closure'
  FROM app.pole WHERE "nbp#" = '9001_D3';
INSERT INTO app.fosc (splice_num, status, kind, location, closure_type_id, fpm_wor, notes)
SELECT 'DMO0003', 'plan', 'closure', geom, (SELECT min(closure_type_id) FROM app.closure_type), '2026-1-Demo', 'Planned closure at the end of the road'
  FROM app.pole WHERE "nbp#" = '9001_D6';

INSERT INTO app.cable (name, fibre_count, notes) VALUES ('Demo 24F', 24, 'Sample cable');
INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id, fpm_wor, installed, installation)
SELECT c.cable_id, a.fosc_id, b.fosc_id, '2026-1-Demo', (b.splice_num = 'DMO0002'), 'aerial'
  FROM app.cable c, app.fosc a, app.fosc b
 WHERE c.name = 'Demo 24F'
   AND ((a.splice_num = 'DMO0001' AND b.splice_num = 'DMO0002') OR (a.splice_num = 'DMO0002' AND b.splice_num = 'DMO0003'));

-- a WiFi access point beside the road
INSERT INTO app.wifi_ap (name, model, status, fpm_wor, geom, azimuth_deg, notes)
SELECT 'Demo AP', model, 'plan', '2026-1-Demo', ST_SetSRID(ST_MakePoint(-66.29945, 45.80008), 4326), 270, 'Sample access point'
  FROM app.wifi_ap_model ORDER BY model LIMIT 1;

COMMIT;
