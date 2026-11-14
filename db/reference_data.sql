-- Rows every installation needs before anything else can be written (change tracking, pole owners, own name).
-- Loaded by db/reference_data.sql, after db/schema.sql.
INSERT INTO app.actor (actor_code, display_name, kind, app_user_id, active, notes) VALUES
  ('CRON', 'Pollers and cron', 'script', NULL, true, 'Pollers, collectors, syncs and scheduled jobs'),
  ('SYST', 'System', 'system', NULL, true, 'Migrations and maintenance scripts'),
  ('WEBX', 'Web, user unknown', 'system', NULL, true, 'A web or API request whose login is not tied to a person'),
  ('UNKN', 'Untracked write', 'system', NULL, true, 'A write from a session that did not say who it was'),
  ('LEGC', 'Legacy', 'system', NULL, true, 'Before change tracking existed; who wrote it is not recorded')
ON CONFLICT DO NOTHING;
INSERT INTO app.change_set (change_id, change_no, actor_code, app_user_id, started_at, source, note)
VALUES ('LEGC_0', 0, 'LEGC', NULL, now(), 'legacy', 'Rows written before change tracking')
ON CONFLICT DO NOTHING;
-- 'Unknown' is a real value (a trigger turns a blank owner into it). The last name is your own network:
-- change it here and in app/org.local.php (own_name), and update app.org_setting, to use your company's name.
INSERT INTO app.pole_owner (owner) VALUES ('Unknown'), ('Private'), ('Bell Aliant'), ('NB Power'), ('Own network')
ON CONFLICT DO NOTHING;
INSERT INTO app.org_setting (key, value) VALUES ('own_name', 'Own network') ON CONFLICT DO NOTHING;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'audit_log', 'the log itself' WHERE to_regclass('app.audit_log') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'change_set', 'the change ledger' WHERE to_regclass('app.change_set') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'actor', 'identity list' WHERE to_regclass('app.actor') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'audit_exempt', 'this list' WHERE to_regclass('app.audit_exempt') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'uisp_device_event', 'telemetry' WHERE to_regclass('app.uisp_device_event') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'uisp_common_event', 'telemetry' WHERE to_regclass('app.uisp_common_event') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'uisp_device_alert', 'telemetry' WHERE to_regclass('app.uisp_device_alert') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'uisp_cache', 'cache' WHERE to_regclass('app.uisp_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bgp_traffic_sample', 'telemetry' WHERE to_regclass('app.bgp_traffic_sample') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bgp_route_observation', 'telemetry' WHERE to_regclass('app.bgp_route_observation') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bgp_asn_info', 'cache' WHERE to_regclass('app.bgp_asn_info') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bgp_prefix_asn_cache', 'cache' WHERE to_regclass('app.bgp_prefix_asn_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'router_iface_metric', 'telemetry' WHERE to_regclass('app.router_iface_metric') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'router_metric', 'telemetry' WHERE to_regclass('app.router_metric') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'router_sfp_metric', 'telemetry' WHERE to_regclass('app.router_sfp_metric') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'router_health', 'telemetry' WHERE to_regclass('app.router_health') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'router_event', 'telemetry' WHERE to_regclass('app.router_event') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'snmp_metric', 'telemetry' WHERE to_regclass('app.snmp_metric') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'netflow_daily', 'telemetry' WHERE to_regclass('app.netflow_daily') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'http_check', 'telemetry' WHERE to_regclass('app.http_check') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'pdu_outlet', 'telemetry' WHERE to_regclass('app.pdu_outlet') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'pdu_power', 'telemetry' WHERE to_regclass('app.pdu_power') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'pdu_device', 'telemetry' WHERE to_regclass('app.pdu_device') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'live_optitap', 'telemetry' WHERE to_regclass('app.live_optitap') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'dhcp_lease_cache', 'cache' WHERE to_regclass('app.dhcp_lease_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'email_inbox_cache', 'cache' WHERE to_regclass('app.email_inbox_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'geocode_cache', 'cache' WHERE to_regclass('app.geocode_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'overpass_cache', 'cache' WHERE to_regclass('app.overpass_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'route_coincidence_cache', 'cache' WHERE to_regclass('app.route_coincidence_cache') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'alden_density_hex', 'derived, rebuilt' WHERE to_regclass('app.alden_density_hex') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'asset_density_hex', 'derived, rebuilt' WHERE to_regclass('app.asset_density_hex') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_civic_address', 'bulk import from GeoNB' WHERE to_regclass('app.nb_civic_address') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_civic_address_stage', 'bulk import staging' WHERE to_regclass('app.nb_civic_address_stage') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_parcel', 'bulk import from GeoNB' WHERE to_regclass('app.nb_parcel') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_parcel_stage', 'bulk import staging' WHERE to_regclass('app.nb_parcel_stage') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_wetland', 'bulk import from GeoNB' WHERE to_regclass('app.nb_wetland') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_wetland_stage', 'bulk import staging' WHERE to_regclass('app.nb_wetland_stage') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_wetland_psw', 'bulk import from GeoNB' WHERE to_regclass('app.nb_wetland_psw') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_wetland_psw_stage', 'bulk import staging' WHERE to_regclass('app.nb_wetland_psw_stage') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'gnb_wan_site', 'bulk import from GeoNB' WHERE to_regclass('app.gnb_wan_site') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'geonb_sync', 'sync state' WHERE to_regclass('app.geonb_sync') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'alden_pole', 'staging loaded from Alden One files' WHERE to_regclass('app.alden_pole') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'alden_vault', 'staging loaded from Alden One files' WHERE to_regclass('app.alden_vault') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'sms_message', 'message log' WHERE to_regclass('app.sms_message') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'call_log', 'message log' WHERE to_regclass('app.call_log') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'plant_build_log', 'is itself a log' WHERE to_regclass('app.plant_build_log') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bell_permit_import', 'bulk import staging' WHERE to_regclass('app.bell_permit_import') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bell_dump', 'NDA data: never copied into the unencrypted audit log' WHERE to_regclass('app.bell_dump') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'bell_location', 'NDA data: never copied into the unencrypted audit log' WHERE to_regclass('app.bell_location') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'document_text', 'extracted PDF text, rebuildable from app.document' WHERE to_regclass('app.document_text') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_community', 'bulk import from GeoNB' WHERE to_regclass('app.nb_community') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_railway', 'bulk import from GeoNB' WHERE to_regclass('app.nb_railway') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'nb_railway_stage', 'bulk import staging' WHERE to_regclass('app.nb_railway_stage') IS NOT NULL;
INSERT INTO app.audit_exempt (table_name, reason) SELECT 'fpm_permit_stage', 'bulk import staging' WHERE to_regclass('app.fpm_permit_stage') IS NOT NULL;
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (5, 'Coyote Dome 17', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (8, 'Unknown', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (10, 'GJS-NJ447', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (11, 'Fibre Cable', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (12, 'Cabinet (Pole Mount)', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (13, 'OptiTap', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (14, 'MST', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (15, 'Corning WCH', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (16, 'Generic Plastic Terminal Box', NULL, 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (1, 'CommScope 450 A4', 'D06322000', 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (2, 'CommScope 450 B6', '468040000', 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (4, 'Coyote One', 'COYOTE-ONE', 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (6, 'Coyote Inline Runt', 'COYOTE-RUNT', 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (7, 'Coyote LCC', 'COYOTE-LCC', 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (3, 'CommScope 450 D', '266348000', 'LEGC_0', 'LEGC_0');
INSERT INTO app.closure_type (closure_type_id, name, primary_bom_sku, created_change, updated_change) VALUES (17, 'CommScope FDH3000', NULL, 'CLAU_141438', 'CLAU_141438');
INSERT INTO app.loss_param (param_key, loss_db, notes, created_change, updated_change) VALUES ('splice_fusion', 0.02, 'Fusion splice loss (dB per splice)', 'LEGC_0', 'LEGC_0');
INSERT INTO app.loss_param (param_key, loss_db, notes, created_change, updated_change) VALUES ('splice_mechanical', 0.10, 'Mechanical splice loss (dB per splice)', 'LEGC_0', 'LEGC_0');
INSERT INTO app.loss_param (param_key, loss_db, notes, created_change, updated_change) VALUES ('connector_sc_upc', 0.35, 'SC/UPC connector pair loss (dB per mated pair)', 'LEGC_0', 'LEGC_0');
INSERT INTO app.loss_param (param_key, loss_db, notes, created_change, updated_change) VALUES ('fibre_smf_db_per_m', 0.00025, 'Single-mode fibre attenuation (dB/m = 0.25 dB/km)', 'LEGC_0', 'LEGC_0');
INSERT INTO app.loss_param (param_key, loss_db, notes, created_change, updated_change) VALUES ('olt_class_b_plus_tx_dbm', 3.25, 'Class B+ GPON OLT midpoint TX power (range 1.5–5 dBm)', 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (2, '3/4" Duct SDR 11 HDPE Smoothwall', 1, '[]', '', true, 10, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (3, '1" Duct SDR 11 HDPE Smoothwall', 1, '[]', 'Coilable — plough / bore', true, 11, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (4, '1-1/4" Duct SDR 11 HDPE Smoothwall', 1, '[]', 'Coilable — plough / bore', true, 12, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (5, '2" Duct SDR 11 HDPE Smoothwall', 1, '[]', 'Coilable — plough / bore', true, 13, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (6, '3/4" Rigid PVC DB2', 1, '[]', 'Customer drops, short laterals', true, 20, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (7, '1" Rigid PVC DB2', 1, '[]', 'Drops, small laterals', true, 30, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (8, '1-1/4" Rigid PVC DB2', 1, '[]', 'Typical fibre lateral', true, 40, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (9, '1-1/2" Rigid PVC DB2', 1, '[]', '', true, 50, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (10, '2" Rigid PVC DB2', 1, '[]', 'Workhorse distribution duct', true, 60, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (11, '3" Rigid PVC DB2', 1, '[]', '', true, 70, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (12, '4" Rigid PVC DB2', 1, '[]', 'Trunk duct, POP entrance', true, 80, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (13, '2" Rigid PVC Sch 40', 1, '[]', 'Road crossings, encasement', true, 100, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (14, '3" Rigid PVC Sch 40', 1, '[]', 'Road crossings, encasement', true, 105, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (15, '4" Rigid PVC Sch 40', 1, '[]', 'Duct bank, road crossings', true, 110, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (16, '2" Rigid PVC Sch 80', 1, '[]', 'Pole riser, exposed runs', true, 120, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (17, '4" Rigid PVC Sch 40 + 3× 1-1/4" innerduct', 1, '[{"size": "1-1/4\"", "count": 3}]', 'Trunk duct with innerduct', true, 130, false, false, 'LEGC_0', 'LEGC_0');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (18, 'Duraline 3/4" DB2 black UV', 1, '[]', 'Duraline. Same as 3/4" Rigid PVC DB2 but black with UV protection (exposed runs)', true, 21, false, false, 'CLAU_53802', 'CLAU_53802');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (1, 'FCST 2-way 14/10 w/tracer', 1, '[{"size": "10/14mm", "count": 2}]', 'FCST microduct, 2 x 10/14 mm ways with tracer wire. Our usual microduct.', true, 0, true, true, 'LEGC_0', 'CLAU_168459');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (19, 'Dura-Line 2-way 14/10 w/tracer', 1, '[{"size": "10/14mm", "count": 2}]', 'Dura-Line FuturePath 2-way 14/10 mm, blue oversheath, 20 AWG copper tracer (BOM CTSP-MD-02W-14.0X10.0-BLU-T-E).', true, 1, true, true, 'CLAU_168459', 'CLAU_168459');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (20, '3/4" PEX', 1, '[]', 'PEX tubing, 3/4". Flexible, coilable.', true, 2, false, false, 'CLAU_172395', 'CLAU_172395');
INSERT INTO app.microduct_type (id, name, way_count, inner_ducts, notes, active, sort_order, has_tracer, is_microduct, created_change, updated_change) VALUES (21, '1/2" PEX', 1, '[]', 'PEX tubing, 1/2". Flexible, coilable.', true, 2, false, false, 'CLAU_172395', 'CLAU_172395');
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-Pro-XGS', 'UniFi U7 Pro XGS', 'omni', 360, 100, 'Best guess, open ground', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-Pro-Max', 'UniFi U7 Pro Max', 'omni', 360, 40, 'Indoor ceiling unit; best guess', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-LR', 'UniFi U7 Long-Range', 'omni', 360, 50, 'Best guess', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U6-Mesh', 'UniFi U6 Mesh', 'omni', 360, 60, 'Best guess', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U6-LR', 'UniFi U6 Long-Range', 'omni', 360, 50, 'Best guess', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('UAP-AC-M', 'UniFi AC Mesh', 'omni', 360, 50, 'Best guess', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('UWB-XG', 'UniFi Wi-Fi BaseStation XG', 'omni', 360, 60, 'Best guess', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('Sector-120', 'Generic 120 degree sector', 'directional', 120, 120, 'Placeholder for a sector antenna', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('Omni-generic', 'Generic omnidirectional', 'omni', 360, 60, 'Placeholder', NULL, NULL);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-Outdoor', 'UniFi U7 Outdoor', 'directional', 45, 49, 'Built-in directional antenna (45 deg at 5 GHz, 90 deg at 2.4 GHz). Link budget: 50 Mbit on 5 GHz / usable (slower) on 2.4 GHz. Ubiquiti rated area 465 m2.', 130, 90);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-Pro-Outdoor', 'UniFi U7 Pro Outdoor', 'directional', 90, 43, 'Built-in directional antenna (90 deg). Link budget: 50 Mbit on 5 GHz / usable (slower) on 2.4 GHz. Ubiquiti rated area 465 m2. Needs external omni antennas for 360 deg.', 130, 90);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-Pro', 'UniFi U7 Pro (ceiling)', 'omni', 360, 27, 'Ceiling unit. Link budget: 50 Mbit on 5 GHz / usable (slower) on 2.4 GHz. Ubiquiti rated area 140 m2.', 94, 360);
INSERT INTO app.wifi_ap_model (model, label, pattern, beam_deg, range_m, notes, fringe_range_m, fringe_beam_deg) VALUES ('U7-Pro-Wall', 'UniFi U7 Pro Wall', 'directional', 180, 25, 'Wall plate, beam taken as 180 deg (not published). Link budget: 50 Mbit on 5 GHz / usable (slower) on 2.4 GHz. Ubiquiti rated area 140 m2.', 94, 180);
