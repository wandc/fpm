<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// /var/www/html/config.php (or src/public/config.php)
$DB_HOST = getenv('APP_DB_HOST') ?: 'db';
$DB_PORT = getenv('APP_DB_PORT') ?: '5432';
$DB_NAME = getenv('APP_DB_NAME') ?: 'fpm_db';   // your database name
$DB_USER = getenv('APP_DB_USER') ?: 'appuser';
$DB_PASS = getenv('APP_DB_PASS') ?: 'supersecret';

$dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

  // PostGIS is installed in the `topology` schema in this project (see db/init/02-postgis.sql).
  // Ensure `topology` is on the search_path so unqualified ST_* calls work.
  // Keep the default "$user", public entries, and append topology.
  $pdo->exec('SET search_path TO "$user", public, topology');

  // Say who is writing (CLI script or an anonymous web request); the login refines it later.
  require_once __DIR__ . '/change_tracking.php';
  try { fpm_bind_actor($pdo); } catch (Throwable $e) { error_log('change tracking bind failed: ' . $e->getMessage()); }
} catch (PDOException $e) {
  http_response_code(500);
  echo "Database connection failed: " . htmlspecialchars($e->getMessage());
  exit;
}

require_once __DIR__ . "/org.php";
