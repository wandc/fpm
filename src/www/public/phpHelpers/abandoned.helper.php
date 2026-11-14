<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// phpHelpers/abandoned.helper.php — abandoned work orders (migration 0305).
//
// A work order flagged abandoned (app.fpm_permit.abandoned_at) hides the plant that belongs ONLY to
// abandoned work orders: app.v_hidden_asset lists it by (kind, id), app.v_hidden_splice_join lists the splices
// that touch a hidden span or sit in a hidden closure. Nothing is deleted; clearing the flag restores it.

if (!function_exists('abandoned_sets')) {
    /** @return array<string, array<int, true>>  kind => [id => true] of everything currently hidden. */
    function abandoned_sets(PDO $pdo): array
    {
        static $sets = null;
        if ($sets === null) {
            $sets = [];
            foreach ($pdo->query("SELECT kind, id FROM app.v_hidden_asset")->fetchAll(PDO::FETCH_NUM) as [$k, $id]) {
                $sets[$k][(int)$id] = true;
            }
        }
        return $sets;
    }

    /** True when the page was asked to show abandoned plant as well (?abandoned=1). */
    function abandoned_show(): bool
    {
        return ($_GET['abandoned'] ?? '') === '1';
    }

    /** @return array<string, array{at:string, note:string}>  abandoned work orders, by permit number. */
    function abandoned_work_orders(PDO $pdo): array
    {
        static $list = null;
        if ($list === null) {
            $list = [];
            foreach ($pdo->query("SELECT permit_num, abandoned_at::date::text AS at, abandoned_note FROM app.fpm_permit
                                   WHERE abandoned_at IS NOT NULL ORDER BY permit_num")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $list[$r['permit_num']] = ['at' => $r['at'], 'note' => (string)$r['abandoned_note']];
            }
        }
        return $list;
    }

    /** SQL condition that keeps a splice_join (alias $a) out when it is hidden. */
    function abandoned_splice_sql(string $a = 'j'): string
    {
        return "NOT EXISTS (SELECT 1 FROM app.v_hidden_splice_join hs WHERE hs.splice_join_id = {$a}.splice_join_id)";
    }

    /** SQL condition that keeps a cable span (alias $a) out when it is hidden. */
    function abandoned_span_sql(string $a = 'cs'): string
    {
        return "NOT EXISTS (SELECT 1 FROM app.v_hidden_asset ha WHERE ha.kind = 'span' AND ha.id = {$a}.cable_span_id)";
    }
}
