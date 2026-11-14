<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/sheath_length.helper.php
// Helpers for app.sheath_length (create, list recent, delete)


if (!function_exists('sheath_length_insert')) {

    /**
     * Insert a new sheath_length row.
     *
     * $data keys:
     *   - cable_span_id (int)
     *   - measure_m     (float|string)
     *   - lat           (?float|string)
     *   - lon           (?float|string)
     *   - notes         (?string)
     *
     * Returns: ['ok'=>bool, 'error'=>?string]
     */
    function sheath_length_insert(PDO $pdo, array $data): array
    {
        $cableSpanId = (int)($data['cable_span_id'] ?? 0);
        $measureM    = trim((string)($data['measure_m'] ?? ''));
        $lat         = trim((string)($data['lat'] ?? ''));
        $lon         = trim((string)($data['lon'] ?? ''));
        $notes       = trim((string)($data['notes'] ?? ''));

        if ($cableSpanId <= 0) {
            return ['ok' => false, 'error' => 'cable_span_id is required and must be > 0.'];
        }
        if ($measureM === '' || !is_numeric($measureM) || (float)$measureM <= 0) {
            return ['ok' => false, 'error' => 'measure (m) is required and must be > 0.'];
        }

        $hasGeom = ($lat !== '' && $lon !== '' && is_numeric($lat) && is_numeric($lon));

        try {
            $cols = ['cable_span_id', 'measure_m', 'notes'];
            $vals = [':cable_span_id', ':measure_m', ':notes'];

            if ($hasGeom) {
                $cols[] = 'geom';
                $vals[] = "ST_SetSRID(ST_MakePoint(:lon,:lat),4326)";
            }

            $sql = sprintf(
                "INSERT INTO app.sheath_length (%s)
                 VALUES (%s)",
                implode(', ', $cols),
                implode(', ', $vals)
            );

            $st = $pdo->prepare($sql);
            $st->bindValue(':cable_span_id', $cableSpanId, PDO::PARAM_INT);
            $st->bindValue(':measure_m', (float)$measureM);
            if ($notes !== '') {
                $st->bindValue(':notes', $notes, PDO::PARAM_STR);
            } else {
                $st->bindValue(':notes', null, PDO::PARAM_NULL);
            }
            if ($hasGeom) {
                $st->bindValue(':lat', (float)$lat);
                $st->bindValue(':lon', (float)$lon);
            }

            $st->execute();
            return ['ok' => true, 'error' => null];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch the most recent sheath_length rows.
     *
     * Returns array of rows:
     *   sheath_length_id, cable_span_id, measure_m,
     *   lat, lon, notes, created_at
     */
    function sheath_length_recent(PDO $pdo, int $limit = 10): array
    {
        if ($limit <= 0) $limit = 10;
        if ($limit > 100) $limit = 100;

        $sql = "
          SELECT
            sheath_length_id,
            cable_span_id,
            measure_m,
            ST_Y(geom) AS lat,
            ST_X(geom) AS lon,
            notes,
            created_at
          FROM app.sheath_length
          ORDER BY sheath_length_id DESC
          LIMIT :limit
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    /**
     * Delete one sheath_length row by id.
     *
     * Returns ['ok'=>bool, 'error'=>?string]
     */
    function sheath_length_delete(PDO $pdo, int $id): array
    {
        if ($id <= 0) return ['ok' => false, 'error' => 'Invalid id'];

        try {
            $st = $pdo->prepare("DELETE FROM app.sheath_length WHERE sheath_length_id = :id");
            $st->execute([':id' => $id]);
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
