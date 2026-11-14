<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// POST /api/merge_span.php  {cable_span_id, with_span_id}
// The reverse of split_span.php: two spans of the same cable that meet at one closure become one span,
// and the closure between them is removed when nothing else uses it.
//
// Refused when the spans are on different cables, do not share a closure, the shared closure has any other
// cable span, a splice or a device. Splices at the far ends of the spans are carried to the joined span
// (see phpHelpers/span_merge.helper.php). The first span keeps its id; the second is deleted; the shared
// closure is deleted if no row refers to it, otherwise it is left and reported.

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../phpHelpers/span_merge.helper.php';
/** @var PDO $pdo */

if (api_method() !== 'POST') api_err('Method not allowed', 405);
api_require_csrf();
$body = api_json_body();
$a = (int)($body['cable_span_id'] ?? 0);
$b = (int)($body['with_span_id'] ?? 0);
if ($a <= 0 || $b <= 0 || $a === $b) api_err('Two different spans are required');

try {
    $pdo->beginTransaction();
    $j = cable_spans_join($pdo, $a, $b);
    $shared = $j['shared'];

    // remove the now-unused closure if nothing refers to it
    $removed = false;
    $pdo->exec('SAVEPOINT drop_closure');
    try {
        $pdo->prepare('DELETE FROM app.fosc WHERE fosc_id = ?')->execute([$shared]);
        $removed = true;
    } catch (PDOException $e) {
        $pdo->exec('ROLLBACK TO SAVEPOINT drop_closure');
    }
    $pdo->commit();
    api_ok(['cable_span_id' => $j['span'], 'closure_removed' => $removed, 'closure_id' => $shared]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_err($e->getMessage(), 400);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_err('Database error: ' . $e->getMessage(), 500);
}
