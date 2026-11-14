<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/closure.php
// Closures (FOSCs) list + detail view + splices + timeline
set_time_limit(120);

/** @var \FPM\App\Template $PAGE */

global $pdo, $CSRF;


require_once $_SERVER['DOCUMENT_ROOT'] . '/phpHelpers/closure.helper.php';
// ------------------------------
// Inputs (GET)
// ------------------------------

$foscId    = isset($_GET['fosc_id']) ? (int)$_GET['fosc_id'] : 0;
$spliceNum = trim($_GET['splice_num'] ?? '');
$q         = trim($_GET['q'] ?? '');
$status    = trim($_GET['status'] ?? '');
$typeId    = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
$pageNum   = max(1, (int)($_GET['page_num'] ?? 1));   // avoid collision with ?page=
$perPage   = min(50, max(1, (int)($_GET['per_page'] ?? 12)));
$offset    = ($pageNum - 1) * $perPage;

// If splice_num present but not fosc_id, redirect to detail
if ($foscId === 0 && $spliceNum !== '') {
    $maybe = fetch_fosc_id_by_splice($pdo, $spliceNum);
    if ($maybe) {
        header('Location: ' . closure_url_build(['fosc_id' => $maybe]));
        exit;
    }
}

// Preload closure types (for filters and forms)
$types = fetch_closure_types($pdo);

// ------------------------------
// POST routing (add/edit FOSC, add splice)
// ------------------------------

$formErrors = $editErrors = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_fosc') {
        [$newId, $formErrors] = handle_add_fosc($pdo, $CSRF, $_POST);
        if ($newId) {
            header('Location: ' . closure_url_build(['fosc_id' => $newId]));
            exit;
        }

    } elseif ($action === 'add_splice') {
        $errors = [];
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $errors[] = 'Bad CSRF token.';
        }

        $isPlan = ($_POST['is_plan'] ?? '0') === '1';

        $aType = (string)($_POST['a_type'] ?? 'span');   // 'span' | 'device'
        $bType = (string)($_POST['b_type'] ?? 'span');

        // A side — span
        $aEp     = (string)($_POST['a_endpoint']  ?? '');
        $aFibLo  = (int)($_POST['a_fiber_lo']     ?? 0);
        $aFibHi  = (int)($_POST['a_fiber_hi']     ?? 0);
        // A side — device
        $aDevId  = (int)($_POST['a_device_id']    ?? 0);
        $aPortLo = (int)($_POST['a_port_lo']      ?? -1);
        $aPortHi = (int)($_POST['a_port_hi']      ?? -1);

        // B side — span
        $bEp     = (string)($_POST['b_endpoint']  ?? '');
        $bFibLo  = (int)($_POST['b_fiber_lo']     ?? 0);
        $bFibHi  = (int)($_POST['b_fiber_hi']     ?? 0);
        // B side — device
        $bDevId  = (int)($_POST['b_device_id']    ?? 0);
        $bPortLo = (int)($_POST['b_port_lo']      ?? -1);
        $bPortHi = (int)($_POST['b_port_hi']      ?? -1);

        $joinKind = (string)($_POST['join_kind'] ?? 'single');
        $notes    = trim((string)($_POST['notes']  ?? ''));

        $allowedKinds = ['single', 'ribbon', 'pass_through', 'connector'];
        if (!in_array($joinKind, $allowedKinds, true)) $joinKind = 'single';

        $aSpanEp = $bSpanEp = null;
        if ($aType === 'span') {
            $aSpanEp = parse_endpoint_key($aEp);
            if (!$aSpanEp)                                    $errors[] = 'Select A cable span endpoint.';
            elseif ($aFibLo <= 0 || $aFibHi <= 0 || $aFibLo > $aFibHi)
                                                              $errors[] = 'A fiber range invalid (min 1).';
        } else {
            if ($aDevId <= 0)                                 $errors[] = 'Select A device.';
            elseif ($aPortLo < 0 || $aPortHi < $aPortLo)    $errors[] = 'A port range invalid (0=IN, 1+=outputs).';
        }
        if ($bType === 'span') {
            $bSpanEp = parse_endpoint_key($bEp);
            if (!$bSpanEp)                                    $errors[] = 'Select B cable span endpoint.';
            elseif ($bFibLo <= 0 || $bFibHi <= 0 || $bFibLo > $bFibHi)
                                                              $errors[] = 'B fiber range invalid (min 1).';
        } else {
            if ($bDevId <= 0)                                 $errors[] = 'Select B device.';
            elseif ($bPortLo < 0 || $bPortHi < $bPortLo)    $errors[] = 'B port range invalid (0=IN, 1+=outputs).';
        }
        if (!$errors && $aType === 'span' && $bType === 'span'
            && $aSpanEp['span_id'] === $bSpanEp['span_id']
            && $aSpanEp['end']     === $bSpanEp['end']) {
            $errors[] = 'A and B endpoints must differ.';
        }

        if ($errors) {
            $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => (int)$foscId]));
            exit;
        }

        $aTarget  = $aType === 'span' ? 'cable_span' : 'fosc_device';
        $aSpanId  = $aType === 'span' ? $aSpanEp['span_id'] : $aDevId;
        $aEnd     = $aType === 'span' ? $aSpanEp['end']     : 'device';
        $aRangeLo = $aType === 'span' ? $aFibLo  : $aPortLo;
        $aRangeHi = $aType === 'span' ? $aFibHi  : $aPortHi;

        $bTarget  = $bType === 'span' ? 'cable_span' : 'fosc_device';
        $bSpanId  = $bType === 'span' ? $bSpanEp['span_id'] : $bDevId;
        $bEnd     = $bType === 'span' ? $bSpanEp['end']     : 'device';
        $bRangeLo = $bType === 'span' ? $bFibLo  : $bPortLo;
        $bRangeHi = $bType === 'span' ? $bFibHi  : $bPortHi;

        // A grid selection may hold several disjoint runs. splice_join stores ONE range
        // per side, so the selection becomes one row per aligned run pair — see
        // align_range_runs(). The lo/hi pair above is the first run, and stays the
        // whole answer whenever only one run was picked.
        $aRunsRaw = (string)($_POST[($aType === 'span' ? 'a_fiber_lo' : 'a_port_lo') . '_runs'] ?? '');
        $bRunsRaw = (string)($_POST[($bType === 'span' ? 'b_fiber_lo' : 'b_port_lo') . '_runs'] ?? '');
        $aMin     = $aType === 'span' ? 1 : 0;
        $bMin     = $bType === 'span' ? 1 : 0;

        $aRuns = parse_range_runs($aRunsRaw, $aRangeLo, $aRangeHi, $aMin);
        $bRuns = parse_range_runs($bRunsRaw, $bRangeLo, $bRangeHi, $bMin);
        if ($aRuns === null || $bRuns === null) {
            $_SESSION['flash'] = ['msg' => 'Could not read the selected range.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => (int)$foscId]));
            exit;
        }

        $pairs = align_range_runs($aRuns, $bRuns);
        if ($pairs === null) {
            $_SESSION['flash'] = [
                'msg' => sprintf(
                    'A side has %d, B side has %d — both sides must cover the same count to splice them.',
                    range_runs_count($aRuns), range_runs_count($bRuns)
                ),
                'ok'  => false,
            ];
            header('Location: ' . closure_url_build(['fosc_id' => (int)$foscId]));
            exit;
        }

        try {
            $st = $pdo->prepare("
                INSERT INTO app.splice_join
                  (fosc_id,
                   a_target, a_span_id, a_end, a_range,
                   b_target, b_span_id, b_end, b_range,
                   join_kind, notes, is_plan)
                VALUES
                  (:fosc_id,
                   :atgt, :asid, :aend, int4range(:alo, :ahi_plus1),
                   :btgt, :bsid, :bend, int4range(:blo, :bhi_plus1),
                   :join_kind::app.splice_join_kind, :notes, :is_plan::boolean)
                RETURNING splice_join_id
            ");
            // All or nothing: a half-written multi-run splice is worse than none, because
            // the missing rows look like fibres that were deliberately left unspliced.
            $pdo->beginTransaction();
            $ids = [];
            foreach ($pairs as [[$alo, $ahi], [$blo, $bhi]]) {
                $st->execute([
                    ':fosc_id'    => $foscId,
                    ':atgt'       => $aTarget,
                    ':asid'       => $aSpanId,
                    ':aend'       => $aEnd,
                    ':alo'        => $alo,
                    ':ahi_plus1'  => $ahi + 1,
                    ':btgt'       => $bTarget,
                    ':bsid'       => $bSpanId,
                    ':bend'       => $bEnd,
                    ':blo'        => $blo,
                    ':bhi_plus1'  => $bhi + 1,
                    ':join_kind'  => $joinKind,
                    ':notes'      => $notes !== '' ? $notes : null,
                    ':is_plan'    => $isPlan ? 'true' : 'false',
                ]);
                $ids[] = (int)$st->fetchColumn();
            }
            $pdo->commit();
            $_SESSION['flash'] = [
                'msg' => count($ids) === 1
                    ? 'Splice added (#' . $ids[0] . ').'
                    : count($ids) . ' splices added (#' . implode(', #', $ids) . ').',
                'ok'  => true,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['msg' => 'Insert failed: ' . $e->getMessage(), 'ok' => false];
        }

        header('Location: ' . closure_url_build(['fosc_id' => (int)$foscId]));
        exit;

    } elseif ($action === 'add_patch_cable') {
        // Creates a cable_span (the patch cord) + a splice_join in one step.
        $errors = [];
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) $errors[] = 'Bad CSRF token.';

        $pcCableId   = (int)($_POST['pc_cable_id']   ?? 0);
        $pcFarFoscId = (int)($_POST['pc_far_fosc_id'] ?? 0);
        $pcAFibLo    = (int)($_POST['pc_a_fiber_lo']  ?? 1);
        $pcAFibHi    = (int)($_POST['pc_a_fiber_hi']  ?? 1);
        $pcBEp       = (string)($_POST['pc_b_endpoint'] ?? '');
        $pcBFibLo    = (int)($_POST['pc_b_fiber_lo']  ?? 0);
        $pcBFibHi    = (int)($_POST['pc_b_fiber_hi']  ?? 0);
        $pcIsPlan    = ($_POST['is_plan'] ?? '0') === '1';
        $pcNotes     = trim((string)($_POST['notes'] ?? ''));

        if ($pcCableId <= 0)              $errors[] = 'Select a cable.';
        if ($pcFarFoscId <= 0)            $errors[] = 'Select the far-end closure.';
        if ($pcFarFoscId === $foscId)     $errors[] = 'Far-end closure must differ from this closure.';
        $pcBSpanEp = parse_endpoint_key($pcBEp);
        if (!$pcBSpanEp)                  $errors[] = 'Select destination endpoint.';
        if ($pcAFibLo <= 0 || $pcAFibHi < $pcAFibLo) $errors[] = 'Patch cable fiber range invalid.';
        if ($pcBFibLo <= 0 || $pcBFibHi < $pcBFibLo) $errors[] = 'Destination fiber range invalid.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                // 1. Create the cable span (patch cord)
                $stSpan = $pdo->prepare("
                    INSERT INTO app.cable_span (cable_id, from_fosc_id, to_fosc_id)
                    VALUES (:cid, :from, :to)
                    RETURNING cable_span_id
                ");
                $stSpan->execute([':cid' => $pcCableId, ':from' => $foscId, ':to' => $pcFarFoscId]);
                $newSpanId = (int)$stSpan->fetchColumn();
                // 2. Create the splice
                $stSplice = $pdo->prepare("
                    INSERT INTO app.splice_join
                      (fosc_id,
                       a_target, a_span_id, a_end, a_range,
                       b_target, b_span_id, b_end, b_range,
                       join_kind, notes, is_plan)
                    VALUES
                      (:fosc_id,
                       'cable_span', :asid, 'from', int4range(:alo, :ahi_plus1),
                       'cable_span', :bsid, :bend, int4range(:blo, :bhi_plus1),
                       'connector', :notes, :is_plan::boolean)
                    RETURNING splice_join_id
                ");
                $stSplice->execute([
                    ':fosc_id'    => $foscId,
                    ':asid'       => $newSpanId,
                    ':alo'        => $pcAFibLo,
                    ':ahi_plus1'  => $pcAFibHi + 1,
                    ':bsid'       => $pcBSpanEp['span_id'],
                    ':bend'       => $pcBSpanEp['end'],
                    ':blo'        => $pcBFibLo,
                    ':bhi_plus1'  => $pcBFibHi + 1,
                    ':notes'      => $pcNotes !== '' ? $pcNotes : null,
                    ':is_plan'    => $pcIsPlan ? 'true' : 'false',
                ]);
                $pdo->commit();
                $_SESSION['flash'] = ['msg' => 'Patch cable added (#' . $stSplice->fetchColumn() . ').', 'ok' => true];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['msg' => 'Failed: ' . $e->getMessage(), 'ok' => false];
            }
        } else {
            $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => (int)$foscId]));
        exit;

    } elseif ($action === 'terminate_cable') {
        // Terminate a cable onto N connectors: auto-create (or reuse) a patch_panel
        // device and splice cable fibre range -> panel port range 1:1 (port N == fibre N).
        $errors = [];
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) $errors[] = 'Bad CSRF token.';

        $tcEp        = (string)($_POST['tc_endpoint'] ?? '');
        $tcFibLo     = (int)($_POST['tc_fiber_lo'] ?? 0);
        $tcFibHi     = (int)($_POST['tc_fiber_hi'] ?? 0);
        $tcConnector = trim((string)($_POST['tc_connector'] ?? 'LC/UPC'));
        $tcPanelId   = (int)($_POST['tc_panel_id'] ?? 0);   // 0 = create new
        $tcName      = trim((string)($_POST['tc_panel_name'] ?? ''));
        $tcIsPlan    = ($_POST['is_plan'] ?? '0') === '1';

        $allowedConnectors = ['LC', 'LC/UPC', 'LC/APC', 'SC', 'SC/APC', 'FC/APC', 'FC/PC', 'E2000', 'MTP'];

        $tcEpParsed = parse_endpoint_key($tcEp);
        if (!$tcEpParsed)                              $errors[] = 'Select the cable endpoint to terminate.';
        if ($tcFibLo <= 0 || $tcFibHi < $tcFibLo)      $errors[] = 'Fibre range invalid (min 1).';
        if (!in_array($tcConnector, $allowedConnectors, true)) $errors[] = 'Invalid connector type.';
        if ($tcPanelId <= 0 && $tcName === '')         $errors[] = 'Enter a name for the new panel.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($tcPanelId > 0) {
                    // Reuse an existing patch_panel here; bump port count if needed.
                    $stChk = $pdo->prepare("
                        SELECT attrs FROM app.fosc_device
                         WHERE fosc_device_id = :id AND fosc_id = :fid AND device_type = 'patch_panel'
                    ");
                    $stChk->execute([':id' => $tcPanelId, ':fid' => $foscId]);
                    $panelRow = $stChk->fetch();
                    if (!$panelRow) throw new RuntimeException('Target panel not found at this closure.');
                    $panelAttrs = json_decode(is_array($panelRow['attrs']) ? json_encode($panelRow['attrs']) : (string)$panelRow['attrs'], true) ?: [];
                    $curPorts   = (int)($panelAttrs['ports'] ?? 0);
                    if ($tcFibHi > $curPorts) {
                        $panelAttrs['ports'] = (string)$tcFibHi;
                        $stUp = $pdo->prepare("UPDATE app.fosc_device SET attrs = :a::jsonb WHERE fosc_device_id = :id");
                        $stUp->execute([':a' => json_encode($panelAttrs), ':id' => $tcPanelId]);
                    }
                    $panelId = $tcPanelId;
                } else {
                    $attrs = json_encode(['connector' => $tcConnector, 'ports' => (string)$tcFibHi]);
                    $stDev = $pdo->prepare("
                        INSERT INTO app.fosc_device (fosc_id, device_type, name, attrs)
                        VALUES (:fid, 'patch_panel', :name, :attrs::jsonb)
                        RETURNING fosc_device_id
                    ");
                    $stDev->execute([':fid' => $foscId, ':name' => $tcName, ':attrs' => $attrs]);
                    $panelId = (int)$stDev->fetchColumn();
                }

                // Splice the cable fibre range to the panel port range 1:1 (port == fibre).
                $stSplice = $pdo->prepare("
                    INSERT INTO app.splice_join
                      (fosc_id,
                       a_target, a_span_id, a_end, a_range,
                       b_target, b_span_id, b_end, b_range,
                       join_kind, is_plan)
                    VALUES
                      (:fosc_id,
                       'cable_span', :asid, :aend, int4range(:alo, :ahi_plus1),
                       'fosc_device', :bsid, 'device', int4range(:blo, :bhi_plus1),
                       'connector', :is_plan::boolean)
                    RETURNING splice_join_id
                ");
                $stSplice->execute([
                    ':fosc_id'   => $foscId,
                    ':asid'      => $tcEpParsed['span_id'],
                    ':aend'      => $tcEpParsed['end'],
                    ':alo'       => $tcFibLo,
                    ':ahi_plus1' => $tcFibHi + 1,
                    ':bsid'      => $panelId,
                    ':blo'       => $tcFibLo,
                    ':bhi_plus1' => $tcFibHi + 1,
                    ':is_plan'   => $tcIsPlan ? 'true' : 'false',
                ]);
                $pdo->commit();
                $n = $tcFibHi - $tcFibLo + 1;
                $_SESSION['flash'] = ['msg' => "Cable terminated onto {$n} connector(s).", 'ok' => true];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash'] = ['msg' => 'Failed: ' . $e->getMessage(), 'ok' => false];
            }
        } else {
            $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => (int)$foscId]));
        exit;

    } elseif ($action === 'edit_fosc') {
        [$updatedId, $editErrors] = handle_edit_fosc($pdo, $CSRF, $_POST);
        if ($updatedId) {
            header('Location: ' . closure_url_build(['fosc_id' => $updatedId]));
            exit;
        }
        // If errors, show the modal again on detail view:
        if (!empty($_POST['fosc_id'])) {
            $_GET['fosc_id'] = (string)((int)$_POST['fosc_id']);
            $_GET['edit']    = '1';
            $foscId          = (int)$_GET['fosc_id'];
        }

    } elseif ($action === 'add_device') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
            exit;
        }
        $devName      = trim($_POST['device_name']      ?? '');
        $devType      = trim($_POST['device_type']      ?? '');
        $devOuts      = (int)($_POST['device_outs']     ?? 4);
        $devConnector = trim($_POST['device_connector'] ?? 'LC');
        $devPorts     = (int)($_POST['device_ports']    ?? 12);
        $mptMode      = trim($_POST['device_mpt_mode']  ?? 'passive');
        $mptPorts     = (int)($_POST['device_mpt_ports'] ?? 4);
        $devNotes     = trim($_POST['device_notes']     ?? '');
        // Quick-add button posts no name — number it from what is already in the tray.
        if ($devName === '' && ($_POST['auto_name'] ?? '') === 'plc') {
            $devName = closure_next_plc_name($pdo, $foscId);
        }
        // Mirrors chk_closure_device_passive — active gear belongs at a POP.
        $allowedTypes = ['splitter', 'oadm', 'patch_panel', 'mpt'];
        $allowedConnectors = ['LC', 'LC/UPC', 'LC/APC', 'SC', 'SC/APC', 'FC/APC', 'FC/PC', 'E2000', 'MTP'];
        $errors = [];
        if ($devName === '')                              $errors[] = 'Name is required.';
        if (!in_array($devType, $allowedTypes, true))    $errors[] = 'Invalid device type.';
        if (in_array($devType, ['splitter', 'dwdm'], true) && ($devOuts < 2 || $devOuts > 128))
            $errors[] = 'Outputs must be 2–128.';
        if (in_array($devType, ['olt_pon', 'sfp'], true) && ($devOuts < 1 || $devOuts > 128))
            $errors[] = 'Source ports must be 1–128.';
        if ($devType === 'patch_panel') {
            if (!in_array($devConnector, $allowedConnectors, true)) $errors[] = 'Invalid connector type.';
            if ($devPorts < 1 || $devPorts > 1000)                 $errors[] = 'Port count must be 1–1000.';
        }
        if ($devType === 'mpt') {
            if (!in_array($mptMode, ['passive', 'split'], true)) $errors[] = 'Invalid MPT mode.';
            $mptMin = $mptMode === 'split' ? 2 : 1;
            if ($mptPorts < $mptMin || $mptPorts > 128)          $errors[] = 'MPT ports must be ' . $mptMin . '–128.';
        }
        if ($errors) {
            $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
        } else {
            if (in_array($devType, ['splitter', 'dwdm', 'olt_pon', 'sfp'], true)) {
                // Laser sources (olt_pon/sfp) carry a port count too: each port is a
                // source output (OUT 1..N) — e.g. an 8-port OLT or a 14-port router.
                $attrs = json_encode(['outs' => (string)max(1, $devOuts)]);
            } elseif ($devType === 'patch_panel') {
                $attrs = json_encode(['connector' => $devConnector, 'ports' => (string)$devPorts]);
            } elseif ($devType === 'mpt') {
                // passive = 1:1 pass-through ports; split = internal 1×N splitter
                $attrs = json_encode(['mode' => $mptMode, 'ports' => (string)$mptPorts]);
            } else {
                $attrs = '{}';
            }
            try {
                $st = $pdo->prepare("
                    INSERT INTO app.fosc_device (fosc_id, device_type, name, attrs, notes)
                    VALUES (:fid, :type, :name, :attrs::jsonb, :notes)
                ");
                $st->execute([
                    ':fid'   => $foscId,
                    ':type'  => $devType,
                    ':name'  => $devName,
                    ':attrs' => $attrs,
                    ':notes' => $devNotes !== '' ? $devNotes : null,
                ]);
                $_SESSION['flash'] = ['msg' => 'Device added.', 'ok' => true];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['msg' => closure_device_error($e), 'ok' => false];
            }
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
        exit;

    } elseif ($action === 'delete_device') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
            exit;
        }
        $devId = (int)($_POST['fosc_device_id'] ?? 0);
        if ($devId > 0) {
            try {
                $st = $pdo->prepare("
                    DELETE FROM app.fosc_device
                     WHERE fosc_device_id = :id AND fosc_id = :fid
                ");
                $st->execute([':id' => $devId, ':fid' => $foscId]);
                $_SESSION['flash'] = ['msg' => 'Device deleted.', 'ok' => true];
            } catch (Throwable $e) {
                $msg = str_contains($e->getMessage(), 'foreign key')
                    ? 'Cannot delete — device has splices. Remove those first.'
                    : 'Delete failed: ' . $e->getMessage();
                $_SESSION['flash'] = ['msg' => $msg, 'ok' => false];
            }
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
        exit;

    } elseif ($action === 'edit_device') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
            exit;
        }
        $devId        = (int)($_POST['fosc_device_id'] ?? 0);
        $devName      = trim($_POST['device_name']      ?? '');
        $devType      = trim($_POST['device_type']      ?? '');
        $devOuts      = (int)($_POST['device_outs']     ?? 4);
        $devConnector = trim($_POST['device_connector'] ?? 'LC');
        $devPorts     = (int)($_POST['device_ports']    ?? 12);
        $mptMode      = trim($_POST['device_mpt_mode']  ?? 'passive');
        $mptPorts     = (int)($_POST['device_mpt_ports'] ?? 4);
        $devNotes     = trim($_POST['device_notes']     ?? '');
        $allowedTypes      = ['splitter', 'oadm', 'patch_panel', 'mpt'];
        $allowedConnectors = ['LC', 'LC/UPC', 'LC/APC', 'SC', 'SC/APC', 'FC/APC', 'FC/PC', 'E2000', 'MTP'];
        $errors = [];
        if ($devId <= 0)                                  $errors[] = 'Invalid device.';
        if ($devName === '')                              $errors[] = 'Name is required.';
        if (!in_array($devType, $allowedTypes, true))    $errors[] = 'Invalid device type.';
        if (in_array($devType, ['splitter', 'dwdm'], true) && ($devOuts < 2 || $devOuts > 128))
            $errors[] = 'Outputs must be 2–128.';
        if (in_array($devType, ['olt_pon', 'sfp'], true) && ($devOuts < 1 || $devOuts > 128))
            $errors[] = 'Source ports must be 1–128.';
        if ($devType === 'patch_panel') {
            if (!in_array($devConnector, $allowedConnectors, true)) $errors[] = 'Invalid connector type.';
            if ($devPorts < 1 || $devPorts > 1000)                 $errors[] = 'Port count must be 1–1000.';
        }
        if ($devType === 'mpt') {
            if (!in_array($mptMode, ['passive', 'split'], true)) $errors[] = 'Invalid MPT mode.';
            $mptMin = $mptMode === 'split' ? 2 : 1;
            if ($mptPorts < $mptMin || $mptPorts > 128)          $errors[] = 'MPT ports must be ' . $mptMin . '–128.';
        }
        if ($errors) {
            $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
        } else {
            if (in_array($devType, ['splitter', 'dwdm', 'olt_pon', 'sfp'], true)) {
                // Laser sources (olt_pon/sfp) carry a port count too — see add_device.
                $attrs = json_encode(['outs' => (string)max(1, $devOuts)]);
            } elseif ($devType === 'patch_panel') {
                $attrs = json_encode(['connector' => $devConnector, 'ports' => (string)$devPorts]);
            } elseif ($devType === 'mpt') {
                $attrs = json_encode(['mode' => $mptMode, 'ports' => (string)$mptPorts]);
            } else {
                $attrs = '{}';
            }
            try {
                $st = $pdo->prepare("
                    UPDATE app.fosc_device
                       SET device_type = :type,
                           name        = :name,
                           attrs       = :attrs::jsonb,
                           notes       = :notes
                     WHERE fosc_device_id = :id AND fosc_id = :fid
                ");
                $st->execute([
                    ':id'    => $devId,
                    ':fid'   => $foscId,
                    ':type'  => $devType,
                    ':name'  => $devName,
                    ':attrs' => $attrs,
                    ':notes' => $devNotes !== '' ? $devNotes : null,
                ]);
                $_SESSION['flash'] = ['msg' => 'Device updated.', 'ok' => true];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['msg' => 'Update failed: ' . $e->getMessage(), 'ok' => false];
            }
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
        exit;

    } elseif ($action === 'set_feed') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
            exit;
        }
        $spanKey = trim((string)($_POST['feed_span_key'] ?? ''));
        if ($spanKey === '') {
            try {
                $pdo->prepare("UPDATE app.fosc
                    SET attrs = attrs - 'feed_span_id' - 'feed_end', updated_at = now()
                  WHERE fosc_id = :id")
                ->execute([':id' => $foscId]);
                $_SESSION['flash'] = ['msg' => 'Feed direction cleared.', 'ok' => true];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['msg' => 'Failed: ' . $e->getMessage(), 'ok' => false];
            }
        } else {
            $ep = parse_endpoint_key($spanKey);
            if (!$ep) {
                $_SESSION['flash'] = ['msg' => 'Invalid span endpoint.', 'ok' => false];
            } else {
                $patch = json_encode(['feed_span_id' => $ep['span_id'], 'feed_end' => $ep['end']]);
                try {
                    $pdo->prepare("UPDATE app.fosc
                        SET attrs = attrs || :patch::jsonb, updated_at = now()
                      WHERE fosc_id = :id")
                    ->execute([':patch' => $patch, ':id' => $foscId]);
                    $_SESSION['flash'] = ['msg' => 'Feed direction saved.', 'ok' => true];
                } catch (Throwable $e) {
                    $_SESSION['flash'] = ['msg' => 'Failed: ' . $e->getMessage(), 'ok' => false];
                }
            }
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
        exit;

    } elseif ($action === 'toggle_damaged') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
            exit;
        }
        try {
            $cur = $pdo->prepare("SELECT (attrs->>'damaged')::boolean FROM app.fosc WHERE fosc_id = :id");
            $cur->execute([':id' => $foscId]);
            $isDamaged = (bool)$cur->fetchColumn();
            if ($isDamaged) {
                $pdo->prepare("UPDATE app.fosc SET attrs = attrs - 'damaged', updated_at = now() WHERE fosc_id = :id")
                    ->execute([':id' => $foscId]);
                $_SESSION['flash'] = ['msg' => 'Damage flag cleared.', 'ok' => true];
            } else {
                $pdo->prepare("UPDATE app.fosc SET attrs = attrs || '{\"damaged\":true}'::jsonb, updated_at = now() WHERE fosc_id = :id")
                    ->execute([':id' => $foscId]);
                $_SESSION['flash'] = ['msg' => 'Marked as damaged.', 'ok' => true];
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['msg' => 'Failed: ' . $e->getMessage(), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId]));
        exit;

    } elseif ($action === 'delete_splice') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
            exit;
        }
        $sjId = (int)($_POST['splice_join_id'] ?? 0);
        if ($sjId > 0) {
            try {
                $st = $pdo->prepare("UPDATE app.splice_join SET retired_at = now() WHERE splice_join_id = :id AND fosc_id = :fid AND retired_at IS NULL");
                $st->execute([':id' => $sjId, ':fid' => $foscId]);
                $_SESSION['flash'] = ['msg' => 'Splice deleted.', 'ok' => true];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['msg' => 'Delete failed: ' . $e->getMessage(), 'ok' => false];
            }
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
        exit;

    } elseif ($action === 'promote_plan') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
            exit;
        }
        try {
            $pdo->beginTransaction();
            // Retire all current actuals
            $st = $pdo->prepare("UPDATE app.splice_join SET retired_at = now() WHERE fosc_id = :fid AND is_plan = false AND retired_at IS NULL");
            $st->execute([':fid' => $foscId]);
            // Promote plan splices to actuals
            $st = $pdo->prepare("UPDATE app.splice_join SET is_plan = false WHERE fosc_id = :fid AND is_plan = true AND retired_at IS NULL");
            $st->execute([':fid' => $foscId]);
            $pdo->commit();
            $_SESSION['flash'] = ['msg' => 'Plan promoted to active. Previous actuals saved in history.', 'ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['msg' => 'Promote failed: ' . $e->getMessage(), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
        exit;

    } elseif ($action === 'add_plan_to_active') {
        // Additive: built splices stay, the planned ones are built alongside them.
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
            exit;
        }
        try {
            $pdo->beginTransaction();
            // A planned cut (butt splice) over a built pass-through replaces it: the cable is cut there, so the
            // pass-through is retired first and the cut is built below.
            $st = $pdo->prepare("
                UPDATE app.splice_join a SET retired_at = now()
                 WHERE a.fosc_id = :fid AND a.is_plan = false AND a.retired_at IS NULL AND a.join_kind = 'pass_through'
                   AND EXISTS (SELECT 1 FROM app.splice_join p
                                WHERE p.fosc_id = a.fosc_id AND p.is_plan = true AND p.retired_at IS NULL AND p.join_kind <> 'pass_through'
                                  AND p.a_target = a.a_target AND p.a_span_id = a.a_span_id AND p.a_end = a.a_end
                                  AND p.b_target = a.b_target AND p.b_span_id = a.b_span_id AND p.b_end = a.b_end
                                  AND p.a_range && a.a_range)");
            $st->execute([':fid' => $foscId]);
            // A planned splice identical to a built one (either way round) is the same fibre twice: retire it.
            $st = $pdo->prepare("
                UPDATE app.splice_join p SET retired_at = now()
                 WHERE p.fosc_id = :fid AND p.is_plan = true AND p.retired_at IS NULL
                   AND EXISTS (SELECT 1 FROM app.splice_join a
                                WHERE a.fosc_id = p.fosc_id AND a.is_plan = false AND a.retired_at IS NULL
                                  AND (a.join_kind = 'pass_through') = (p.join_kind = 'pass_through')
                                  AND ((a.a_target, a.a_span_id, a.a_end, a.a_range, a.b_target, a.b_span_id, a.b_end, a.b_range)
                                     = (p.a_target, p.a_span_id, p.a_end, p.a_range, p.b_target, p.b_span_id, p.b_end, p.b_range)
                                    OR (a.a_target, a.a_span_id, a.a_end, a.a_range, a.b_target, a.b_span_id, a.b_end, a.b_range)
                                     = (p.b_target, p.b_span_id, p.b_end, p.b_range, p.a_target, p.a_span_id, p.a_end, p.a_range)))");
            $st->execute([':fid' => $foscId]);
            $st = $pdo->prepare("UPDATE app.splice_join SET is_plan = false WHERE fosc_id = :fid AND is_plan = true AND retired_at IS NULL");
            $st->execute([':fid' => $foscId]);
            $n = $st->rowCount();
            $pdo->commit();
            $_SESSION['flash'] = ['msg' => "$n planned splice" . ($n !== 1 ? 's' : '') . ' added to the built splices. Existing built splices were kept.', 'ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['msg' => 'Add to active failed (a fibre may already be spliced): ' . $e->getMessage(), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
        exit;

    } elseif ($action === 'restore_snapshot') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
            exit;
        }
        $snapTs = trim($_POST['retired_at'] ?? '');
        if ($snapTs === '') {
            $_SESSION['flash'] = ['msg' => 'Invalid snapshot timestamp.', 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
            exit;
        }
        try {
            $pdo->beginTransaction();
            // Retire current actuals
            $st = $pdo->prepare("UPDATE app.splice_join SET retired_at = now() WHERE fosc_id = :fid AND is_plan = false AND retired_at IS NULL");
            $st->execute([':fid' => $foscId]);
            // Re-insert historical actual splices as new active records
            $st = $pdo->prepare("
                INSERT INTO app.splice_join
                    (fosc_id, a_target, a_span_id, a_end, a_range,
                     b_target, b_span_id, b_end, b_range, join_kind, notes, is_plan)
                SELECT fosc_id, a_target, a_span_id, a_end, a_range,
                       b_target, b_span_id, b_end, b_range, join_kind, notes, false
                FROM app.splice_join
                WHERE fosc_id = :fid AND retired_at = :snap_ts::timestamptz AND is_plan = false
            ");
            $st->execute([':fid' => $foscId, ':snap_ts' => $snapTs]);
            $pdo->commit();
            $_SESSION['flash'] = ['msg' => 'Snapshot restored. Current actuals saved in history.', 'ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['msg' => 'Restore failed: ' . $e->getMessage(), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
        exit;

    } elseif ($action === 'edit_splice') {
        $errors = [];
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $errors[] = 'Bad CSRF token.';
        }

        $sjId    = (int)($_POST['splice_join_id'] ?? 0);
        if ($sjId <= 0) $errors[] = 'Invalid splice ID.';

        $aType   = (string)($_POST['ea_type'] ?? 'span');
        $bType   = (string)($_POST['eb_type'] ?? 'span');

        $aEp     = (string)($_POST['a_endpoint']  ?? '');
        $aFibLo  = (int)($_POST['a_fiber_lo']     ?? 0);
        $aFibHi  = (int)($_POST['a_fiber_hi']     ?? 0);
        $aDevId  = (int)($_POST['a_device_id']    ?? 0);
        $aPortLo = (int)($_POST['a_port_lo']      ?? -1);
        $aPortHi = (int)($_POST['a_port_hi']      ?? -1);

        $bEp     = (string)($_POST['b_endpoint']  ?? '');
        $bFibLo  = (int)($_POST['b_fiber_lo']     ?? 0);
        $bFibHi  = (int)($_POST['b_fiber_hi']     ?? 0);
        $bDevId  = (int)($_POST['b_device_id']    ?? 0);
        $bPortLo = (int)($_POST['b_port_lo']      ?? -1);
        $bPortHi = (int)($_POST['b_port_hi']      ?? -1);

        $joinKind = (string)($_POST['join_kind'] ?? 'single');
        $notes    = trim((string)($_POST['notes']  ?? ''));
        $isPlan   = ($_POST['is_plan'] ?? '0') === '1';

        $allowedKinds = ['single', 'ribbon', 'pass_through', 'connector'];
        if (!in_array($joinKind, $allowedKinds, true)) $joinKind = 'single';

        $aSpanEp = $bSpanEp = null;
        if ($aType === 'span') {
            $aSpanEp = parse_endpoint_key($aEp);
            if (!$aSpanEp)                                    $errors[] = 'Select A cable span endpoint.';
            elseif ($aFibLo <= 0 || $aFibHi <= 0 || $aFibLo > $aFibHi)
                                                              $errors[] = 'A fiber range invalid (min 1).';
        } else {
            if ($aDevId <= 0)                                 $errors[] = 'Select A device.';
            elseif ($aPortLo < 0 || $aPortHi < $aPortLo)    $errors[] = 'A port range invalid (0=IN, 1+=outputs).';
        }
        if ($bType === 'span') {
            $bSpanEp = parse_endpoint_key($bEp);
            if (!$bSpanEp)                                    $errors[] = 'Select B cable span endpoint.';
            elseif ($bFibLo <= 0 || $bFibHi <= 0 || $bFibLo > $bFibHi)
                                                              $errors[] = 'B fiber range invalid (min 1).';
        } else {
            if ($bDevId <= 0)                                 $errors[] = 'Select B device.';
            elseif ($bPortLo < 0 || $bPortHi < $bPortLo)    $errors[] = 'B port range invalid (0=IN, 1+=outputs).';
        }
        if (!$errors && $aType === 'span' && $bType === 'span'
            && $aSpanEp['span_id'] === $bSpanEp['span_id']
            && $aSpanEp['end']     === $bSpanEp['end']) {
            $errors[] = 'A and B endpoints must differ.';
        }

        if ($errors) {
            $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'ok' => false];
            header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
            exit;
        }

        $aTarget  = $aType === 'span' ? 'cable_span' : 'fosc_device';
        $aSpanId  = $aType === 'span' ? $aSpanEp['span_id'] : $aDevId;
        $aEnd     = $aType === 'span' ? $aSpanEp['end']     : 'device';
        $aRangeLo = $aType === 'span' ? $aFibLo  : $aPortLo;
        $aRangeHi = $aType === 'span' ? $aFibHi  : $aPortHi;

        $bTarget  = $bType === 'span' ? 'cable_span' : 'fosc_device';
        $bSpanId  = $bType === 'span' ? $bSpanEp['span_id'] : $bDevId;
        $bEnd     = $bType === 'span' ? $bSpanEp['end']     : 'device';
        $bRangeLo = $bType === 'span' ? $bFibLo  : $bPortLo;
        $bRangeHi = $bType === 'span' ? $bFibHi  : $bPortHi;

        try {
            $st = $pdo->prepare("
                UPDATE app.splice_join SET
                  a_target = :atgt, a_span_id = :asid, a_end = :aend,
                  a_range  = int4range(:alo, :ahi_plus1),
                  b_target = :btgt, b_span_id = :bsid, b_end = :bend,
                  b_range  = int4range(:blo, :bhi_plus1),
                  join_kind = :join_kind::app.splice_join_kind,
                  notes    = :notes,
                  is_plan  = :is_plan::boolean
                WHERE splice_join_id = :id AND fosc_id = :fosc_id
            ");
            $st->execute([
                ':id'        => $sjId,
                ':fosc_id'   => $foscId,
                ':atgt'      => $aTarget,
                ':asid'      => $aSpanId,
                ':aend'      => $aEnd,
                ':alo'       => $aRangeLo,
                ':ahi_plus1' => $aRangeHi + 1,
                ':btgt'      => $bTarget,
                ':bsid'      => $bSpanId,
                ':bend'      => $bEnd,
                ':blo'       => $bRangeLo,
                ':bhi_plus1' => $bRangeHi + 1,
                ':join_kind' => $joinKind,
                ':notes'     => $notes !== '' ? $notes : null,
                ':is_plan'   => $isPlan ? 'true' : 'false',
            ]);
            $_SESSION['flash'] = ['msg' => "Splice #{$sjId} updated.", 'ok' => true];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['msg' => 'Update failed: ' . $e->getMessage(), 'ok' => false];
        }
        header('Location: ' . closure_url_build(['fosc_id' => $foscId, 'tab' => 'splices']));
        exit;

    } elseif ($action === 'delete_fosc') {
        if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = ['msg' => 'Bad CSRF token.', 'ok' => false];
            header('Location: ' . closure_url_build([]));
            exit;
        }
        $delId = (int)($_POST['fosc_id'] ?? 0);
        if ($delId > 0) {
            $err = closure_delete_with_tail_span($pdo, $delId);
            if ($err !== null) {
                $_SESSION['flash'] = ['msg' => $err, 'ok' => false];
                header('Location: ' . closure_url_build(['fosc_id' => $delId]));
                exit;
            }
            $_SESSION['flash'] = ['msg' => 'Closure deleted.', 'ok' => true];
        }
        header('Location: ' . closure_url_build([]));
        exit;
    }
}

// ------------------------------
// DETAIL or LIST data
// ------------------------------

$flash = flash_get();

if ($foscId > 0) {
    // DETAIL VIEW
    $fosc          = fetch_fosc_by_id($pdo, $foscId);
    $spanEndpoints = $fosc ? fetch_span_endpoints_for_fosc($pdo, $foscId) : [];
    $splicesHere   = $fosc ? fetch_splices_at_fosc($pdo, $foscId)         : [];
    $devicesHere   = $fosc ? fetch_devices_at_fosc($pdo, $foscId)         : [];
    // Devices spliceable here = own devices + POP-homed active devices (Step 5).
    $devicesAvailable = $fosc ? fetch_devices_available_at_fosc($pdo, $foscId) : [];
    $liveMap       = [];   // populated asynchronously — see live_fibers.php

    // Data for the Add Patch Cable modal
    $allCables = $fosc ? $pdo->query("SELECT cable_id, name, fibre_count FROM app.cable ORDER BY name")->fetchAll() : [];
    $allFoscs  = $fosc ? $pdo->query("SELECT fosc_id, splice_num FROM app.fosc ORDER BY splice_num")->fetchAll()  : [];
    $allPops   = $fosc ? $pdo->query("SELECT pop_id, name FROM app.pop ORDER BY name")->fetchAll()                : [];

    // Detect smart defaults: cable_id and far-fosc from existing cable-span splices here
    $patchSuggestCableId  = 0;
    $patchSuggestFarFoscId = 0;
    if ($fosc) {
        $stDefaults = $pdo->prepare("
            SELECT cs.cable_id, cs.from_fosc_id, cs.to_fosc_id, COUNT(*) AS cnt
              FROM app.splice_join sj
              JOIN app.cable_span cs ON cs.cable_span_id = sj.a_span_id
             WHERE sj.fosc_id = :fid
               AND sj.a_target = 'cable_span'
               AND sj.join_kind = 'connector'
             GROUP BY cs.cable_id, cs.from_fosc_id, cs.to_fosc_id
             ORDER BY cnt DESC
             LIMIT 1
        ");
        $stDefaults->execute([':fid' => $foscId]);
        if ($row = $stDefaults->fetch()) {
            $patchSuggestCableId   = (int)$row['cable_id'];
            $patchSuggestFarFoscId = ((int)$row['from_fosc_id'] === $foscId)
                ? (int)$row['to_fosc_id']
                : (int)$row['from_fosc_id'];
        }
    }
    $spliceHistory = $fosc ? fetch_splice_history_at_fosc($pdo, $foscId) : [];
    $timeline      = $fosc ? timeline_fosc($pdo, $foscId) : ['html' => '', 'count' => 0];
    $prefillCoord  = ($fosc && $fosc['lon'] !== null && $fosc['lat'] !== null)
        ? ($fosc['lon'] . ', ' . $fosc['lat'])
        : '';
    $openEdit      = (isset($_GET['edit']) && $_GET['edit'] == '1') || !empty($editErrors);

    // Extract kind and feed-span from attrs
    $foscKind  = $fosc ? ($fosc['kind'] ?? 'closure') : 'closure';
    $isCabinet    = ($foscKind === 'cabinet');
    $isPatchPanel = ($foscKind === 'patch_panel');
    $hasPinout    = $isCabinet || $isPatchPanel;
    $foscAttrs = ($fosc && isset($fosc['attrs_json']))
        ? (json_decode($fosc['attrs_json'], true) ?: []) : [];
    $feedSpanId  = isset($foscAttrs['feed_span_id']) ? (int)$foscAttrs['feed_span_id'] : null;
    $feedEnd     = isset($foscAttrs['feed_end'])     ? $foscAttrs['feed_end']           : null;
    $feedSpanKey = ($feedSpanId && $feedEnd) ? ($feedSpanId . '-' . $feedEnd) : null;
    $feedItemKey = $feedSpanKey ? ('cable_span-' . $feedSpanId . '-' . $feedEnd) : null;

    // Server-side reverse geocoding — runs once, caches result in attrs->street_address
    if ($fosc && $fosc['lat'] !== null && empty($foscAttrs['street_address'])) {
        $geocodeUrl = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
                    . '&lat=' . (float)$fosc['lat']
                    . '&lon=' . (float)$fosc['lon']
                    . '&accept-language=en';
        $ctx  = stream_context_create(['http' => [
            'header'  => "User-Agent: TinuumFibreManager/1.0\r\nAccept: application/json\r\n",
            'timeout' => 5,
        ]]);
        $json = @file_get_contents($geocodeUrl, false, $ctx);
        if ($json !== false) {
            $geo = json_decode($json, true);
            if ($geo) {
                $a    = $geo['address'] ?? [];
                $road = trim(($a['house_number'] ?? '') . ' ' . ($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? ''));
                $parts = array_filter([
                    $road,
                    $a['suburb'] ?? $a['neighbourhood'] ?? '',
                    $a['city']   ?? $a['town'] ?? $a['village'] ?? '',
                    $a['postcode'] ?? '',
                ], 'strlen');
                $resolvedAddr = implode(', ', $parts) ?: ($geo['display_name'] ?? '');
                if ($resolvedAddr !== '') {
                    $attrsPatch = json_encode(['street_address' => $resolvedAddr]);
                    $pdo->prepare("UPDATE app.fosc SET attrs = attrs || :p::jsonb WHERE fosc_id = :id")
                        ->execute([':p' => $attrsPatch, ':id' => $foscId]);
                    $foscAttrs['street_address'] = $resolvedAddr;
                }
            }
        }
    }

    $PAGE->setTitle($fosc ? ('Closure ' . $fosc['splice_num']) : 'Closure not found');
    $PAGE->addExternalJs('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js');
    // Shared connection-diagram renderer + styling (also reused by the POP detail page)
    $PAGE->addExternalCss('/css/splice_diagram.css');
    $PAGE->addExternalJs('/js/splice_diagram.helper.js?v=' . filemtime(__DIR__ . '/../js/splice_diagram.helper.js'));
    $PAGE->addExternalCss('/css/fiber-range-picker.css');
    $PAGE->addExternalJs('/js/fiber-range-picker.js');
} else {
    // LIST VIEW
    $where  = '1=1';
    $params = [];
    // closures that belong only to abandoned work orders are left out (restore the work order to bring them back)
    $where .= " AND NOT EXISTS (SELECT 1 FROM app.v_hidden_asset ha WHERE ha.kind = 'fosc' AND ha.id = f.fosc_id)";

    if ($q !== '') {
        $where .= " AND (f.splice_num ILIKE '%' || :q || '%' OR f.notes ILIKE '%' || :q || '%')";
        $params[':q'] = $q;
    }
    if ($status !== '') {
        $where .= " AND f.status = :st";
        $params[':st'] = $status;
    }
    if ($typeId > 0) {
        $where .= " AND f.closure_type_id = :tid";
        $params[':tid'] = $typeId;
    }

    // Count + page rows
    $stC = $pdo->prepare("SELECT COUNT(*) FROM app.fosc f WHERE $where");
    $stC->execute($params);
    $total      = (int)$stC->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $sql = "
      SELECT f.fosc_id, f.splice_num, f.status, ct.name AS closure_type,
             topology.ST_Y(f.location) AS lat, topology.ST_X(f.location) AS lon, f.updated_at
        FROM app.fosc f
        JOIN app.closure_type ct ON ct.closure_type_id = f.closure_type_id
       WHERE $where
       ORDER BY f.updated_at DESC
       LIMIT :lim OFFSET :off
    ";
    $stL = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stL->bindValue($k, $v);
    }
    $stL->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stL->bindValue(':off', $offset, PDO::PARAM_INT);
    $stL->execute();
    $list = $stL->fetchAll();
    
    // Fetch ALL closures for the map (ignoring pagination)
    $sqlAll = "
      SELECT f.fosc_id, f.splice_num, f.status, ct.name AS closure_type,
             topology.ST_Y(f.location) AS lat, topology.ST_X(f.location) AS lon
        FROM app.fosc f
        JOIN app.closure_type ct ON ct.closure_type_id = f.closure_type_id
       WHERE $where
    ";
    $stAll = $pdo->prepare($sqlAll);
    foreach ($params as $k => $v) {
        $stAll->bindValue($k, $v);
    }
    $stAll->execute();
    $allClosures = $stAll->fetchAll();

    $baseParams = [
        'q'        => $q,
        'status'   => $status,
        'type_id'  => $typeId,
        'per_page' => $perPage,
        // note: page_id for pagination is "page_num" to avoid ?page=
    ];

    $PAGE->setTitle('Closures');
}






// ------------------------------
// Render via Template
// ------------------------------

// Normalize values for the view so we can safely capture them in the closure
$viewFosc          = isset($fosc) ? $fosc : null;
$viewSpanEndpoints = isset($spanEndpoints) ? $spanEndpoints : [];
$viewSplicesHere   = isset($splicesHere) ? $splicesHere : [];
$viewDevicesHere   = isset($devicesHere) ? $devicesHere : [];
// Label shown on the quick-add PLC button; resolved here because the content
// renderer below is a closure and has no $pdo.
$viewNextPlcName   = $foscId > 0 ? closure_next_plc_name($pdo, $foscId) : 'PLC-1';
$viewDevicesAvailable = isset($devicesAvailable) ? $devicesAvailable : [];
$viewAllPops       = isset($allPops) ? $allPops : [];
$viewLiveMap       = isset($liveMap)       ? $liveMap       : [];
$viewSpliceHistory = isset($spliceHistory) ? $spliceHistory : [];
$viewTimeline      = isset($timeline) ? $timeline : ['html' => '', 'count' => 0];
$viewPrefillCoord  = isset($prefillCoord) ? $prefillCoord : '';
$viewOpenEdit      = isset($openEdit) ? $openEdit : false;
$viewFoscKind      = isset($foscKind)     ? $foscKind     : 'closure';
$viewIsCabinet     = isset($isCabinet)    ? $isCabinet    : false;
$viewIsPatchPanel  = isset($isPatchPanel) ? $isPatchPanel : false;
$viewHasPinout     = isset($hasPinout)    ? $hasPinout    : false;
$viewFoscAttrs     = isset($foscAttrs)    ? $foscAttrs    : [];
$viewFeedSpanKey   = isset($feedSpanKey)  ? $feedSpanKey  : null;
$viewFeedItemKey   = isset($feedItemKey)  ? $feedItemKey  : null;
$viewAllCables             = isset($allCables)             ? $allCables             : [];
$viewAllFoscs              = isset($allFoscs)              ? $allFoscs              : [];
$viewPatchSuggestCableId   = isset($patchSuggestCableId)   ? $patchSuggestCableId   : 0;
$viewPatchSuggestFarFoscId = isset($patchSuggestFarFoscId) ? $patchSuggestFarFoscId : 0;
$viewTotal         = isset($total) ? $total : 0;
$viewTotalPages    = isset($totalPages) ? $totalPages : 1;
$viewList          = isset($list) ? $list : [];
$viewAllClosures   = isset($allClosures) ? $allClosures : [];
$viewBaseParams    = isset($baseParams) ? $baseParams : [];


if ($foscId > 0 && $viewFosc) {
    $PAGE->setInlineJsRenderer(function () {
        return <<<JS
document.addEventListener('DOMContentLoaded', function () {
  // safe copy-to-clipboard that works in HTTP or HTTPS
  window.copyCoords = function copyCoords(txt, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt)
        .then(() => { btn.innerText = 'Copied!'; })
        .catch(() => { fallbackCopy(txt, btn); });
    } else {
      fallbackCopy(txt, btn);
    }
  };

  function fallbackCopy(txt, btn) {
    const ta = document.createElement('textarea');
    ta.value = txt;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); btn.innerText = 'Copied!'; }
    catch (err) { alert('Copy failed: ' + err); }
    document.body.removeChild(ta);
  }
});
JS;
    });
}



$PAGE->setContentRenderer(function () use (
    $foscId,
    $viewFosc, $viewSpanEndpoints, $viewSplicesHere, $viewDevicesHere, $viewDevicesAvailable, $viewAllPops, $viewLiveMap, $viewSpliceHistory,
    $viewTimeline, $viewPrefillCoord, $viewOpenEdit,
    $viewFoscKind, $viewIsCabinet, $viewIsPatchPanel, $viewHasPinout, $viewFoscAttrs, $viewFeedSpanKey, $viewFeedItemKey,
    $types, $formErrors, $editErrors,
    $q, $status, $typeId, $pageNum, $perPage,
    $viewTotal, $viewTotalPages, $viewList, $viewAllClosures, $viewBaseParams,
    $viewAllCables, $viewAllFoscs, $viewPatchSuggestCableId, $viewPatchSuggestFarFoscId,
    $viewNextPlcName,
    $flash, $CSRF
) {
    ob_start();

    // Re-map view variables back to the original names used below
    $fosc          = $viewFosc;
    $spanEndpoints = $viewSpanEndpoints;
    $splicesHere   = $viewSplicesHere;
    $devicesHere   = $viewDevicesHere;
    $devicesAvailable = $viewDevicesAvailable;
    $allPops       = $viewAllPops;
    $liveMap       = $viewLiveMap;
    $spliceHistory = $viewSpliceHistory;
    $timeline      = $viewTimeline;
    $prefillCoord  = $viewPrefillCoord;
    $openEdit      = $viewOpenEdit;
    $foscKind      = $viewFoscKind;
    $isCabinet     = $viewIsCabinet;
    $isPatchPanel  = $viewIsPatchPanel;
    $hasPinout     = $viewHasPinout;
    $foscAttrs     = $viewFoscAttrs;
    $feedSpanKey   = $viewFeedSpanKey;
    $feedItemKey   = $viewFeedItemKey;
    $allCables             = $viewAllCables;
    $allFoscs              = $viewAllFoscs;
    $patchSuggestCableId   = $viewPatchSuggestCableId;
    $patchSuggestFarFoscId = $viewPatchSuggestFarFoscId;
    $total         = $viewTotal;
    $totalPages    = $viewTotalPages;
    $list          = $viewList;
    $allClosures   = $viewAllClosures;
    $baseParams    = $viewBaseParams;

    if ($foscId > 0): ?>
        <?php if ($flash): ?>
          <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?> mb-3">
            <?= h($flash['msg']) ?>
          </div>
        <?php endif; ?>

        <?php if (!$fosc): ?>
          <div class="alert alert-danger">Closure not found.</div>
          <?php return ob_get_clean(); ?>
        <?php endif; ?>

        <!-- Page header -->
        <div class="sd-pagehead d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
          <div>
            <h3 class="mb-0">
              <?= h($fosc['splice_num']) ?>
              <?php $badge = status_badge_class($fosc['status']); ?>
              <span class="badge bg-<?= $badge ?> fs-6 ms-1 align-middle"><?= h($fosc['status']) ?></span>
              <?php if ($isCabinet): ?>
                <span class="badge bg-warning text-dark fs-6 ms-1 align-middle">FDC</span>
              <?php elseif ($isPatchPanel): ?>
                <span class="badge bg-info text-dark fs-6 ms-1 align-middle">Patch Panel</span>
              <?php endif; ?>
            </h3>
            <div class="text-muted small"><?= h($fosc['closure_type']) ?></div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" href="index.php?page=closure">Back to list</a>
            <a class="btn btn-outline-secondary btn-sm"
               href="index.php?page=map&fosc_id=<?= (int)$fosc['fosc_id'] ?>">View on Map</a>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editClosureModal">
              Edit
            </button>
            <?php if ($hasPinout): ?>
              <a class="btn btn-outline-info btn-sm"
                 href="index.php?page=fdc_pinout&fosc_id=<?= (int)$fosc['fosc_id'] ?>">
                Pinout
              </a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" target="_blank"
               title="Printable splice plan and latest timeline for a splicing contractor (print or save as PDF)"
               href="closure_sheet.php?fosc_id=<?= (int)$fosc['fosc_id'] ?>">Splice sheet (PDF)</a>
            <div class="btn-group">
              <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">Export</button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">This closure + 3 span hops</h6></li>
                <li><a class="dropdown-item" target="_blank"
                       href="splice_export.php?fosc=<?= (int)$fosc['fosc_id'] ?>&hops=3">View JSON</a></li>
                <li><a class="dropdown-item"
                       href="splice_export.php?fosc=<?= (int)$fosc['fosc_id'] ?>&hops=3&download=1">Download JSON</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item"
                       href="splice_export.php?fosc=<?= (int)$fosc['fosc_id'] ?>&hops=3&compact=1&download=1">Download compact (for AI review)</a></li>
              </ul>
            </div>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Delete closure <?= h(addslashes($fosc['splice_num'])) ?>? This cannot be undone.');">
              <input type="hidden" name="action"  value="delete_fosc">
              <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
              <input type="hidden" name="fosc_id" value="<?= (int)$fosc['fosc_id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
            </form>
          </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs" id="closureTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-overview-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">
              Overview
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-splices-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-splices" type="button">
              Splices
              <?php if ($splicesHere): ?>
                <span class="badge bg-secondary ms-1"><?= count($splicesHere) ?></span>
              <?php endif; ?>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-devices-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-devices" type="button">
              Devices
              <?php if ($devicesHere): ?>
                <span class="badge bg-secondary ms-1"><?= count($devicesHere) ?></span>
              <?php endif; ?>
            </button>
          </li>
          <?php if ($devicesHere): ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-patch-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-patch" type="button">
              Patch
            </button>
          </li>
          <?php endif; ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-timeline-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-timeline" type="button">
              Timeline
              <?php if ($timeline['count']): ?>
                <span class="badge bg-secondary ms-1"><?= $timeline['count'] ?></span>
              <?php endif; ?>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-otdr-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-otdr" type="button">
              OTDR
            </button>
          </li>
        </ul>

        <div class="tab-content border border-top-0 rounded-bottom mb-4" id="closureTabContent">

          <!-- ── Overview ─────────────────────────────────────────────────── -->
          <div class="tab-pane fade show active p-3" id="tab-overview" role="tabpanel">
            <div class="row g-3">
              <div class="col-md-5">
                <dl class="row mb-0 small">
                  <dt class="col-sm-5 text-muted fw-normal">Closure ID</dt>
                  <dd class="col-sm-7"><code class="text-muted"><?= (int)$fosc['fosc_id'] ?></code></dd>

                  <dt class="col-sm-5 text-muted fw-normal">Splice #</dt>
                  <dd class="col-sm-7"><code><?= h($fosc['splice_num']) ?></code></dd>

                  <dt class="col-sm-5 text-muted fw-normal">Status</dt>
                  <dd class="col-sm-7">
                    <span class="badge bg-<?= $badge ?>"><?= h($fosc['status']) ?></span>
                    <?php if (!empty($foscAttrs['damaged'])): ?>
                      <span class="badge bg-danger ms-1">&#x2715; Damaged</span>
                    <?php endif; ?>
                  </dd>

                  <dt class="col-sm-5 text-muted fw-normal">Type</dt>
                  <dd class="col-sm-7"><?= h($fosc['closure_type']) ?></dd>

                  <dt class="col-sm-5 text-muted fw-normal">POP</dt>
                  <dd class="col-sm-7">
                    <?php if (!empty($fosc['pop_id'])): ?>
                      <a class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-decoration-none"
                         href="index.php?page=pop"><?= h($fosc['pop_name']) ?></a>
                    <?php else: ?>
                      <span class="text-muted fst-italic small">— none —</span>
                    <?php endif; ?>
                    <span class="badge <?= ($fosc['enclosure_rating'] ?? 'outside') === 'inside' ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-secondary-subtle text-secondary-emphasis border' ?> ms-1">
                      <?= h(ucfirst($fosc['enclosure_rating'] ?? 'outside')) ?>-rated
                    </span>
                  </dd>

                  <?php if ($fosc['lat'] !== null): ?>
                  <dt class="col-sm-5 text-muted fw-normal">Address</dt>
                  <dd class="col-sm-7" id="fosc-address-dd">
                    <?php if (!empty($foscAttrs['street_address'])): ?>
                      <?= h($foscAttrs['street_address']) ?>
                    <?php else: ?>
                      <span class="text-muted fst-italic small">Not available</span>
                    <?php endif; ?>
                  </dd>
                  <?php endif; ?>

                  <?php if ($fosc['lat'] !== null): ?>
                  <dt class="col-sm-5 text-muted fw-normal">Coordinates</dt>
                  <dd class="col-sm-7">
                    <code><?= h($fosc['lat']) ?>, <?= h($fosc['lon']) ?></code>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1"
                            onclick="copyCoords('<?= h($fosc['lat']) ?>, <?= h($fosc['lon']) ?>', this)">
                      Copy
                    </button>
                  </dd>
                  <?php endif; ?>

                  <dt class="col-sm-5 text-muted fw-normal">Created</dt>
                  <dd class="col-sm-7"><?= h($fosc['created_at']) ?></dd>

                  <dt class="col-sm-5 text-muted fw-normal">Updated</dt>
                  <dd class="col-sm-7"><?= h($fosc['updated_at']) ?></dd>
                </dl>

                <?php if ($fosc['notes']): ?>
                  <div class="mt-2 border rounded p-2 small">
                    <?= nl2br(h($fosc['notes'])) ?>
                  </div>
                <?php else: ?>
                  <div class="mt-2 text-secondary small fst-italic">No notes</div>
                <?php endif; ?>

                <div class="mt-3">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action"  value="toggle_damaged">
                    <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
                    <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">
                    <?php if (!empty($foscAttrs['damaged'])): ?>
                      <button type="submit" class="btn btn-sm btn-outline-secondary">
                        Clear Damaged Flag
                      </button>
                    <?php else: ?>
                      <button type="submit" class="btn btn-sm btn-outline-danger"
                              onclick="return confirm('Mark this closure as damaged?')">
                        &#x2715; Mark as Damaged
                      </button>
                    <?php endif; ?>
                  </form>
                </div>

                <?php if ($isCabinet && $spanEndpoints): ?>
                  <div class="mt-3 pt-3 border-top">
                    <div class="small text-muted fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">
                      Feed Direction
                    </div>
                    <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                      <input type="hidden" name="action"  value="set_feed">
                      <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
                      <input type="hidden" name="fosc_id" value="<?= (int)$foscId ?>">
                      <select name="feed_span_key" class="form-select form-select-sm" style="max-width:280px">
                        <option value="">(none)</option>
                        <?= render_endpoint_options($spanEndpoints, $feedSpanKey ?? '') ?>
                      </select>
                      <button type="submit" class="btn btn-sm btn-warning">Set Feed</button>
                    </form>
                  </div>
                <?php endif; ?>

              </div>

              <?php if ($fosc['lat'] !== null && $fosc['lon'] !== null): ?>
              <div class="col-md-7 d-flex flex-column gap-2 justify-content-start pt-1">
                <div>
                  <a class="btn btn-outline-secondary btn-sm"
                     href="/index.php?page=map&fosc_id=<?= (int)$fosc['fosc_id'] ?>">
                    &#x1F5FA; View on Map
                  </a>
                </div>
                <div class="d-flex gap-3 small text-muted flex-wrap">
                  <a class="text-decoration-none" target="_blank"
                     href="https://www.openstreetmap.org/?mlat=<?= h($fosc['lat']) ?>&mlon=<?= h($fosc['lon']) ?>#map=18/<?= h($fosc['lat']) ?>/<?= h($fosc['lon']) ?>">OpenStreetMap &#x2197;</a>
                  <a class="text-decoration-none" target="_blank"
                     href="https://www.google.com/maps/search/?api=1&query=<?= h($fosc['lat']) ?>,<?= h($fosc['lon']) ?>">Google Maps &#x2197;</a>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- ── Splices ───────────────────────────────────────────────────── -->
          <div class="tab-pane fade p-3" id="tab-splices" role="tabpanel">
            <?php
              $countActual = 0; $countPlan = 0;
              foreach ($splicesHere as $sj) {
                  if ($sj['is_plan'] === 't' || $sj['is_plan'] === true) $countPlan++;
                  else $countActual++;
              }
            ?>
            <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
              <button class="btn btn-outline-primary btn-sm"
                      data-bs-toggle="modal" data-bs-target="#addSpliceModal">
                + Add Splice
              </button>
              <button class="btn btn-outline-success btn-sm"
                      data-bs-toggle="modal" data-bs-target="#addPatchCableModal">
                + Add Patch Cable
              </button>
              <button class="btn btn-outline-secondary btn-sm"
                      data-bs-toggle="modal" data-bs-target="#terminateCableModal">
                &#9173; Terminate Cable
              </button>
              <?php if ($countActual > 0 && $countPlan > 0): ?>
                <button class="btn btn-warning btn-sm"
                        data-bs-toggle="modal" data-bs-target="#promotePlanModal">
                  Replace Active with Plan
                </button>
              <?php endif; ?>
              <?php if ($countPlan > 0): ?>
                <button class="btn btn-success btn-sm"
                        data-bs-toggle="modal" data-bs-target="#addPlanModal"
                        title="Build the planned splices and keep every built splice">
                  <?= $countActual > 0 ? 'Add Plan to Active' : 'Promote Plan to Active' ?>
                </button>
              <?php endif; ?>
            </div>

            <?php if (!$splicesHere): ?>
              <div class="alert alert-secondary">No splices recorded at this closure.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>ID</th>
                      <th>A</th>
                      <th>Fibers/port</th>
                      <th>B</th>
                      <th>Fibers/port</th>
                      <th>Type</th>
                      <th>Notes</th>
                      <th>Layer</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($splicesHere as $sj): ?>
                      <?php
                        $aIsDevice = ($sj['a_target'] === 'fosc_device');
                        $bIsDevice = ($sj['b_target'] === 'fosc_device');
                        $aDevAttrs = $aIsDevice ? (json_decode($sj['a_device_attrs'] ?? '{}', true) ?: []) : [];
                        $bDevAttrs = $bIsDevice ? (json_decode($sj['b_device_attrs'] ?? '{}', true) ?: []) : [];
                        // Check live traffic for cable-span sides
                        $aHasLive = false;
                        if (!$aIsDevice && !empty($liveMap[(int)$sj['a_span_id']])) {
                            for ($f = (int)$sj['a_fiber_lo']; $f < (int)$sj['a_fiber_hi']; $f++) {
                                if (!empty($liveMap[(int)$sj['a_span_id']][$f])) { $aHasLive = true; break; }
                            }
                        }
                        $bHasLive = false;
                        if (!$bIsDevice && !empty($liveMap[(int)$sj['b_span_id']])) {
                            for ($f = (int)$sj['b_fiber_lo']; $f < (int)$sj['b_fiber_hi']; $f++) {
                                if (!empty($liveMap[(int)$sj['b_span_id']][$f])) { $bHasLive = true; break; }
                            }
                        }
                      ?>
                      <tr class="sj-row" data-sj-id="<?= (int)$sj['splice_join_id'] ?>">
                        <td class="text-muted small"><?= (int)$sj['splice_join_id'] ?></td>
                        <td>
                          <?php if ($aIsDevice): ?>
                            <?= h($sj['a_device_name']) ?>
                            <div class="text-muted small"><?= h(device_type_label($sj['a_device_type'], $aDevAttrs)) ?></div>
                          <?php else: ?>
                            <?= h($sj['a_cable_name']) ?>
                            <?php foreach (array_filter(explode(',', $sj['a_tag_names'] ?? '')) as $atag): ?>
                              <span class="badge bg-secondary fw-normal ms-1"><?= h($atag) ?></span>
                            <?php endforeach; ?>
                            <?php if ($sj['a_far_fosc_label']): ?>
                              <div class="text-muted small">
                                → <a href="<?= h(closure_url_build(['fosc_id' => (int)$sj['a_far_fosc_id'], 'tab' => 'splices'])) ?>">
                                  <?= h($sj['a_far_fosc_label']) ?>
                                </a>
                              </div>
                            <?php endif; ?>
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <?php if ($aIsDevice): ?>
                            <?= h(device_port_label((int)$sj['a_fiber_lo'], (int)$sj['a_fiber_hi'])) ?>
                          <?php else: ?>
                            F<?= (int)$sj['a_fiber_lo'] ?>–<?= (int)$sj['a_fiber_hi'] - 1 ?>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($bIsDevice): ?>
                            <?= h($sj['b_device_name']) ?>
                            <div class="text-muted small"><?= h(device_type_label($sj['b_device_type'], $bDevAttrs)) ?></div>
                          <?php else: ?>
                            <?= h($sj['b_cable_name']) ?>
                            <?php foreach (array_filter(explode(',', $sj['b_tag_names'] ?? '')) as $btag): ?>
                              <span class="badge bg-secondary fw-normal ms-1"><?= h($btag) ?></span>
                            <?php endforeach; ?>
                            <?php if ($sj['b_far_fosc_label']): ?>
                              <div class="text-muted small">
                                → <a href="<?= h(closure_url_build(['fosc_id' => (int)$sj['b_far_fosc_id'], 'tab' => 'splices'])) ?>">
                                  <?= h($sj['b_far_fosc_label']) ?>
                                </a>
                              </div>
                            <?php endif; ?>
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <?php if ($bIsDevice): ?>
                            <?= h(device_port_label((int)$sj['b_fiber_lo'], (int)$sj['b_fiber_hi'])) ?>
                          <?php else: ?>
                            F<?= (int)$sj['b_fiber_lo'] ?>–<?= (int)$sj['b_fiber_hi'] - 1 ?>
                          <?php endif; ?>
                        </td>
                        <?php
                          $kindLabel = match($sj['join_kind'] ?? 'single') {
                              'ribbon'       => 'Ribbon',
                              'pass_through' => 'Pass-thru',
                              'connector'    => 'Connector',
                              default        => 'Single',
                          };
                          // A cable spliced onto a panel's pigtails is a splice, not a connector (the join keeps kind
                          // "connector" because the cabinet pinout page maps panel ports from it).
                          if (($sj['join_kind'] ?? '') === 'connector' && ($aIsDevice xor $bIsDevice)) {
                              $per = $aIsDevice ? ((int)$sj['b_fiber_hi'] - (int)$sj['b_fiber_lo']) : ((int)$sj['a_fiber_hi'] - (int)$sj['a_fiber_lo']);
                              $kindLabel = $per > 1 ? 'Ribbon splice' : 'Splice';
                          }
                        ?>
                        <td class="small text-nowrap"><?= $kindLabel ?></td>
                        <td class="small"><?= $sj['notes'] ? nl2br(h($sj['notes'])) : '' ?></td>
                        <td class="text-nowrap">
                          <?php if ($sj['is_plan'] === 't' || $sj['is_plan'] === true): ?>
                            <span class="badge bg-warning text-dark">Plan</span>
                          <?php else: ?>
                            <span class="badge bg-success">Actual</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <?php
                            $sjEditData = [
                              'id'        => (int)$sj['splice_join_id'],
                              'join_kind' => $sj['join_kind'] ?? 'single',
                              'is_plan'   => ($sj['is_plan'] === 't' || $sj['is_plan'] === true),
                              'a_target'  => $sj['a_target'],
                              'a_span_id' => (int)$sj['a_span_id'],
                              'a_end'     => $sj['a_end'],
                              'a_ep_key'  => (int)$sj['a_span_id'] . '-' . $sj['a_end'],
                              'a_ep_label'=> '→ ' . ($sj['a_far_fosc_label'] ?? '?'),
                              'a_lo'      => (int)$sj['a_fiber_lo'],
                              'a_hi'      => (int)$sj['a_fiber_hi'] - 1,
                              'b_target'  => $sj['b_target'],
                              'b_span_id' => (int)$sj['b_span_id'],
                              'b_end'     => $sj['b_end'],
                              'b_ep_key'  => (int)$sj['b_span_id'] . '-' . $sj['b_end'],
                              'b_ep_label'=> '→ ' . ($sj['b_far_fosc_label'] ?? '?'),
                              'b_lo'      => (int)$sj['b_fiber_lo'],
                              'b_hi'      => (int)$sj['b_fiber_hi'] - 1,
                              'notes'     => $sj['notes'] ?? '',
                            ];
                          ?>
                          <button type="button" class="btn btn-sm btn-outline-secondary"
                                  onclick="openEditSpliceModal(<?= h(json_encode($sjEditData)) ?>)">Edit</button>
                          <button type="button" class="btn btn-sm btn-outline-secondary"
                                  title="New splice with the same endpoints and kind — pick free fibres"
                                  onclick="openDuplicateSpliceModal(<?= h(json_encode($sjEditData)) ?>)">Duplicate</button>
                          <form method="post" class="d-inline"
                                onsubmit="return confirm('Delete splice #<?= (int)$sj['splice_join_id'] ?>?');">
                            <input type="hidden" name="action"         value="delete_splice">
                            <input type="hidden" name="csrf"           value="<?= h($CSRF) ?>">
                            <input type="hidden" name="splice_join_id" value="<?= (int)$sj['splice_join_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php
              // Build JSON for the connection diagram — split by is_plan, then defer to
              // the shared payload builder (also used by the POP detail page).
              $splicesActual = [];
              $splicesPlan   = [];
              foreach ($splicesHere as $sj) {
                  if ($sj['is_plan'] === 't' || $sj['is_plan'] === true) $splicesPlan[] = $sj;
                  else                                                   $splicesActual[] = $sj;
              }
              $jFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
              $dgJsonActual = json_encode(
                  build_diagram_payload($splicesActual, $spanEndpoints, $devicesAvailable, $liveMap, $feedItemKey),
                  $jFlags);
              $dgJsonPlan   = json_encode(
                  build_diagram_payload($splicesPlan,   $spanEndpoints, $devicesAvailable, $liveMap, $feedItemKey),
                  $jFlags);
              // Hybrid: everything built plus whatever is planned and not built yet.
              // A planned splice identical to a built one is the same fibre twice, so it drops out.
              $sjSig = function ($sj) {
                  $a = $sj['a_target'].':'.$sj['a_span_id'].':'.$sj['a_end'].':'.$sj['a_fiber_lo'].':'.$sj['a_fiber_hi'];
                  $b = $sj['b_target'].':'.$sj['b_span_id'].':'.$sj['b_end'].':'.$sj['b_fiber_lo'].':'.$sj['b_fiber_hi'];
                  return (($sj['join_kind'] ?? '') === 'pass_through' ? 'P|' : 'S|') . ($a < $b ? "$a|$b" : "$b|$a");
              };
              $builtSigs = [];
              foreach ($splicesActual as $sj) $builtSigs[$sjSig($sj)] = true;
              $splicesHybrid = $splicesActual;
              foreach ($splicesPlan as $sj) if (!isset($builtSigs[$sjSig($sj)])) $splicesHybrid[] = $sj;
              $dgJsonHybrid = json_encode(
                  build_diagram_payload($splicesHybrid, $spanEndpoints, $devicesAvailable, $liveMap, $feedItemKey)
                      + ['mixed' => true],
                  $jFlags);
              // Default to Actual tab unless there are no actual splices
              $dgDefaultTab = count($splicesActual) > 0 ? 'actual' : 'plan';
              ?>

              <!-- Sub-tabs: Actual / Plan -->
              <style>
              .sd-printhead { display:none; }
              @media print {
                body.sd-printing .navbar, body.sd-printing aside, body.sd-printing nav[aria-label="breadcrumb"],
                body.sd-printing #closureTabs, body.sd-printing .sd-noprint, body.sd-printing .sd-orient,
                body.sd-printing #tab-splices > .mb-3, body.sd-printing #tab-splices > .table-responsive,
                body.sd-printing #tab-splices > .alert, body.sd-printing .modal,
                body.sd-printing .sd-pagehead, body.sd-printing details { display:none !important; }
                body.sd-printing { background:#fff; }
                body.sd-printing .container-fluid, body.sd-printing .col, body.sd-printing [class*="col-"],
                body.sd-printing main.app-main { width:100% !important; max-width:none !important; flex:0 0 100% !important; padding:0 !important; margin:0 !important; }
                body.sd-printing .sd-printhead { display:block; font-size:14px; margin:0 0 6px; }
                body.sd-printing .tab-content > .tab-pane:not(.active) { display:none !important; }
                body.sd-printing #tab-splices { padding:0 !important; }
                body.sd-printing #tab-splices .d-flex.flex-xl-row { flex-direction:column !important; }
                body.sd-printing .sd-col-diagram, body.sd-printing .sd-col-instr { width:100% !important; max-width:none !important; flex:none !important; }
                body.sd-printing .sd-col-diagram > div { overflow:visible !important; border:0 !important; padding:0 !important; }
                body.sd-printing .sd-col-diagram svg { width:100% !important; height:auto !important; max-height:6.6in; }
                body.sd-printing .sd-col-instr { break-before:page; }
                body.sd-printing .sd-col-instr > div { border:0 !important; font-size:12px; }
                body.sd-printing tr { break-inside:avoid; }
              }
              </style>
              <div class="sd-printhead" id="sdPrintHead"></div>
              <ul class="nav nav-pills nav-sm mt-4 mb-2 align-items-center sd-noprint" role="tablist">
                <li class="nav-item">
                  <button class="nav-link <?= $dgDefaultTab === 'actual' ? 'active' : '' ?> py-1 px-3"
                          data-bs-toggle="tab" data-bs-target="#diag-actual" type="button">Actual</button>
                </li>
                <li class="nav-item">
                  <button class="nav-link <?= $dgDefaultTab === 'plan' ? 'active' : '' ?> py-1 px-3"
                          data-bs-toggle="tab" data-bs-target="#diag-plan" type="button">Plan</button>
                </li>
                <li class="nav-item">
                  <button class="nav-link py-1 px-3"
                          data-bs-toggle="tab" data-bs-target="#diag-hybrid" type="button"
                          title="Built splices solid, planned splices dashed">Hybrid</button>
                </li>
                <li class="nav-item ms-auto sd-noprint">
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0" id="sdPrintBtn"
                          title="Print the selected diagram and its splice instructions">&#128424; Print</button>
                </li>
              </ul>
              <div class="tab-content">

              <!-- Actual pane -->
              <div class="tab-pane fade <?= $dgDefaultTab === 'actual' ? 'show active' : '' ?>" id="diag-actual" role="tabpanel">
                <div class="d-flex flex-column flex-xl-row gap-3">
                  <div class="sd-col-diagram">
                    <h6 class="mt-2 mb-2 text-muted fw-semibold small text-uppercase ls-1">Connection Diagram</h6>
                    <div id="spliceDiagram-actual" class="border rounded bg-white p-2" style="overflow-x:auto"></div>
                  </div>
                  <div class="sd-col-instr">
                    <h6 class="mt-2 mb-2 text-muted fw-semibold small text-uppercase ls-1">Splice Instructions</h6>
                    <div id="spliceInstr-actual" class="border rounded bg-white p-2"></div>
                  </div>
                </div>
              </div>

              <!-- Plan pane -->
              <div class="tab-pane fade <?= $dgDefaultTab === 'plan' ? 'show active' : '' ?>" id="diag-plan" role="tabpanel">
                <div class="d-flex flex-column flex-xl-row gap-3">
                  <div class="sd-col-diagram">
                    <h6 class="mt-2 mb-2 text-muted fw-semibold small text-uppercase ls-1">Connection Diagram</h6>
                    <div id="spliceDiagram-plan" class="border rounded bg-white p-2" style="overflow-x:auto"></div>
                  </div>
                  <div class="sd-col-instr">
                    <h6 class="mt-2 mb-2 text-muted fw-semibold small text-uppercase ls-1">Splice Instructions</h6>
                    <div id="spliceInstr-plan" class="border rounded bg-white p-2"></div>
                  </div>
                </div>
              </div>

              <!-- Hybrid pane -->
              <div class="tab-pane fade" id="diag-hybrid" role="tabpanel">
                <p class="small text-muted mt-2 mb-0">Built splices are solid; planned splices are dashed with a <span class="badge bg-warning text-dark">Plan</span> tag.</p>
                <div class="d-flex flex-column flex-xl-row gap-3">
                  <div class="sd-col-diagram">
                    <h6 class="mt-2 mb-2 text-muted fw-semibold small text-uppercase ls-1">Connection Diagram</h6>
                    <div id="spliceDiagram-hybrid" class="border rounded bg-white p-2" style="overflow-x:auto"></div>
                  </div>
                  <div class="sd-col-instr">
                    <h6 class="mt-2 mb-2 text-muted fw-semibold small text-uppercase ls-1">Splice Instructions</h6>
                    <div id="spliceInstr-hybrid" class="border rounded bg-white p-2"></div>
                  </div>
                </div>
              </div>

              </div><!-- /tab-content -->

              <script>
              document.addEventListener('DOMContentLoaded', function () {
              _renderSpliceDiagram('spliceDiagram-actual', <?= $dgJsonActual ?>);
              _renderSpliceDiagram('spliceDiagram-plan',   <?= $dgJsonPlan ?>);
              _renderSpliceDiagram('spliceDiagram-hybrid', <?= $dgJsonHybrid ?>);
              // Planned splices are dashed so built and planned read apart.
              (<?= $dgJsonHybrid ?>).splices.forEach(function (sj) {
                if (!sj.plan) return;
                document.querySelectorAll('#spliceDiagram-hybrid [data-sj="' + sj.id + '"]').forEach(function (el) {
                  el.setAttribute('stroke-dasharray', '9,6');
                  el.setAttribute('stroke-opacity', '0.75');
                  el.setAttribute('stroke-linecap', 'butt');
                });
              });
              renderSpliceInstructions('spliceInstr-hybrid', <?= $dgJsonHybrid ?>);
              renderSpliceInstructions('spliceInstr-actual', <?= $dgJsonActual ?>);
              // The plan list is the work order: the built layer is passed as the
              // baseline so a planned splice that already exists drops out of it.
              renderSpliceInstructions('spliceInstr-plan',   <?= $dgJsonPlan ?>, <?= $dgJsonActual ?>);

              // ── Live traffic bolts — drawn asynchronously ─────────────────────
              function rcApplyLiveMap(liveMap) {
                // Remove any previously drawn bolts
                document.querySelectorAll('.live-bolt').forEach(function(el){ el.remove(); });
                if (!liveMap) return;
                var NS = 'http://www.w3.org/2000/svg';
                liveBoltPositions.forEach(function(b) {
                  var spanData = liveMap[b.spanId] || liveMap[String(b.spanId)];
                  if (!spanData || !spanData[b.fiberNum]) return;
                  var svg = document.querySelector('#' + b.containerId + ' svg');
                  if (!svg) return;
                  // Bolt positions are recorded in the layout's own coordinates. In
                  // "Across" the whole diagram sits inside a rotate(-90) group, so the
                  // bolts have to go in with it or they land somewhere in open canvas.
                  var host = svg.querySelector('g[data-sd-rot]') || svg;
                  var c = document.createElementNS(NS, 'circle');
                  c.setAttribute('cx', String(b.x)); c.setAttribute('cy', String(b.y));
                  c.setAttribute('r', '7'); c.setAttribute('fill', '#0f172a');
                  c.setAttribute('opacity', '0.92'); c.setAttribute('class', 'live-bolt');
                  if (b.sjId != null) c.setAttribute('data-sj', String(b.sjId));
                  host.appendChild(c);
                  var t = document.createElementNS(NS, 'text');
                  t.setAttribute('font-size', '10'); t.setAttribute('text-anchor', 'middle');
                  t.setAttribute('class', 'live-bolt');
                  if (b.sjId != null) t.setAttribute('data-sj', String(b.sjId));
                  if (host === svg) {
                    t.setAttribute('x', String(b.x)); t.setAttribute('y', String(b.y + 3));
                  } else {
                    // Counter-rotate the glyph about the bolt's centre so the symbol
                    // itself stays upright while everything around it is on its side.
                    t.setAttribute('x', String(b.x)); t.setAttribute('y', String(b.y));
                    t.setAttribute('dominant-baseline', 'central');
                    t.setAttribute('transform', 'rotate(90 ' + b.x + ' ' + b.y + ')');
                  }
                  t.textContent = '\u26a1';
                  host.appendChild(t);
                });
              }

              (function() {
                var foscId = <?= (int)($foscId ?? 0) ?>;
                if (!foscId || !liveBoltPositions.length) return;
                fetch('api/live_fibers.php?fosc_id=' + foscId)
                  .then(function(r){ return r.json(); })
                  .then(function(d){ if (d.ok) rcApplyLiveMap(d.live); })
                  .catch(function(){});
              })();

                (function () {
                  var btn = document.getElementById('sdPrintBtn');
                  if (!btn) return;
                  btn.addEventListener('click', function () {
                    var act = document.querySelector('#tab-splices .tab-content > .tab-pane.active');
                    var view = act ? act.id.replace('diag-', '') : 'actual';
                    var label = {actual: 'Actual (built)', plan: 'Plan', hybrid: 'Hybrid (built + planned)'}[view] || view;
                    document.getElementById('sdPrintHead').innerHTML =
                      '<strong><?= h($fosc['splice_num']) ?></strong> &middot; connection diagram &amp; splice instructions &middot; '
                      + label + ' &middot; printed ' + new Date().toISOString().slice(0, 10);
                    var st = document.createElement('style');
                    st.textContent = '@page { size: letter landscape; margin: 0.4in; }';
                    document.head.appendChild(st);
                    document.body.classList.add('sd-printing');
                    var done = function () {
                      document.body.classList.remove('sd-printing'); st.remove();
                      window.removeEventListener('afterprint', done);
                    };
                    window.addEventListener('afterprint', done);
                    window.print();
                  });
                })();
                initFibreTrace({'spliceDiagram-actual': false, 'spliceDiagram-plan': true, 'spliceDiagram-hybrid': true},
                               <?= (int)($foscId ?? 0) ?>);
                initSpliceDiagramHover(['spliceDiagram-actual', 'spliceDiagram-plan', 'spliceDiagram-hybrid']);
              });
              </script>

            <?php endif; ?>

            <!-- ── Splice History ──────────────────────────────────────────── -->
            <?php if (!empty($spliceHistory)): ?>
            <details class="mt-4">
              <summary class="text-muted small fw-semibold" style="cursor:pointer">
                History (<?= count($spliceHistory) ?> snapshot<?= count($spliceHistory) !== 1 ? 's' : '' ?>)
              </summary>
              <div class="mt-2">
                <?php foreach ($spliceHistory as $snap):
                  $splices = $snap['splices'];
                  $ts = htmlspecialchars($snap['retired_at'], ENT_QUOTES);
                  $tsDisplay = htmlspecialchars(substr($snap['retired_at'], 0, 19) . ' UTC', ENT_QUOTES);
                ?>
                <div class="card card-body p-2 mb-2 small">
                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-muted"><?= $tsDisplay ?> — <?= (int)$snap['splice_count'] ?> splice<?= $snap['splice_count'] !== 1 ? 's' : '' ?> retired</span>
                    <form method="post" onsubmit="return confirm('Restore this snapshot? Current actuals will be retired to history.')">
                      <input type="hidden" name="csrf"       value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action"     value="restore_snapshot">
                      <input type="hidden" name="fosc_id"    value="<?= $foscId ?>">
                      <input type="hidden" name="retired_at" value="<?= $ts ?>">
                      <button class="btn btn-outline-secondary btn-sm py-0 px-2">Restore</button>
                    </form>
                  </div>
                  <?php if (!empty($splices)): ?>
                  <details class="mt-1">
                    <summary class="text-muted" style="cursor:pointer">Show splices</summary>
                    <table class="table table-sm table-borderless mt-1 mb-0 small">
                      <tbody>
                        <?php foreach ($splices as $sp): ?>
                        <tr>
                          <td><?php
                            if ($sp['a_target'] === 'cable_span') {
                                echo h($sp['a_cable'] ?? '?') . ' ' . (int)$sp['a_lo'] . '–' . (int)$sp['a_hi'];
                            } else {
                                echo h($sp['a_device'] ?? '?') . ' port ' . (int)$sp['a_lo'] . '–' . (int)$sp['a_hi'];
                            }
                          ?></td>
                          <td class="text-muted">↔</td>
                          <td><?php
                            if ($sp['b_target'] === 'cable_span') {
                                echo h($sp['b_cable'] ?? '?') . ' ' . (int)$sp['b_lo'] . '–' . (int)$sp['b_hi'];
                            } else {
                                echo h($sp['b_device'] ?? '?') . ' port ' . (int)$sp['b_lo'] . '–' . (int)$sp['b_hi'];
                            }
                          ?></td>
                          <td class="text-muted"><?= h($sp['join_kind'] ?? '') ?></td>
                          <?php if ($sp['is_plan']): ?><td><span class="badge bg-warning text-dark">Plan</span></td><?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </details>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </details>
            <?php endif; ?>

          </div>

          <!-- ── Promote Plan Modal ─────────────────────────────────────────── -->
          <?php if ($countActual > 0 && $countPlan > 0): ?>
          <div class="modal fade" id="promotePlanModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Replace Active with Plan</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <p>This will retire the <strong><?= $countActual ?> current actual splice<?= $countActual !== 1 ? 's' : '' ?></strong> and make the <strong><?= $countPlan ?> plan splice<?= $countPlan !== 1 ? 's' : '' ?></strong> the new actuals.</p>
                  <p class="text-muted small mb-0">The old actuals will be saved in History and can be restored at any time.</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <form method="post">
                    <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action"  value="promote_plan">
                    <input type="hidden" name="fosc_id" value="<?= $foscId ?>">
                    <button type="submit" class="btn btn-warning">Replace Active with Plan</button>
                  </form>
                </div>
              </div>
            </div>
          </div>

          <?php endif; ?>

          <!-- ── Add Plan to Active Modal ───────────────────────────────────── -->
          <?php if ($countPlan > 0): ?>
          <div class="modal fade" id="addPlanModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title"><?= $countActual > 0 ? 'Add Plan to Active' : 'Promote Plan to Active' ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <p>The <strong><?= $countPlan ?> plan splice<?= $countPlan !== 1 ? 's' : '' ?></strong> become built splices.<?php if ($countActual > 0): ?> All <strong><?= $countActual ?> current actual splice<?= $countActual !== 1 ? 's' : '' ?></strong> stay as they are.<?php else: ?> This closure has no built splices yet.<?php endif; ?></p>
                  <p class="text-muted small mb-0">A planned splice identical to a built one is dropped as a duplicate. If a planned splice uses a fibre that is already spliced, nothing changes and you are told why.</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <form method="post">
                    <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action"  value="add_plan_to_active">
                    <input type="hidden" name="fosc_id" value="<?= $foscId ?>">
                    <button type="submit" class="btn btn-success"><?= $countActual > 0 ? 'Add Plan to Active' : 'Promote Plan to Active' ?></button>
                  </form>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- ── Devices ───────────────────────────────────────────────────── -->
          <div class="tab-pane fade p-3" id="tab-devices" role="tabpanel">
            <div class="mb-3 d-flex gap-2 flex-wrap">
              <button class="btn btn-outline-primary btn-sm"
                      data-bs-toggle="modal" data-bs-target="#addDeviceModal">
                + Add Device
              </button>
              <!-- The overwhelmingly common device here is a 1x4 PLC splitter, so
                   skip the dialog: the name is the next free PLC-N in this closure. -->
              <form method="post" class="d-inline">
                <input type="hidden" name="action"      value="add_device">
                <input type="hidden" name="csrf"        value="<?= h($CSRF) ?>">
                <input type="hidden" name="device_type" value="splitter">
                <input type="hidden" name="device_outs" value="4">
                <input type="hidden" name="auto_name"   value="plc">
                <button class="btn btn-outline-secondary btn-sm" type="submit"
                        title="Add a 1&times;4 PLC splitter named <?= h($viewNextPlcName) ?>">
                  + 1&times;4 PLC
                </button>
              </form>
            </div>

            <?php if (!$devicesHere): ?>
              <div class="alert alert-secondary">No devices in this closure.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>Name</th><th>Type</th><th>Ports</th><th>Notes</th><th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($devicesHere as $dev): ?>
                      <?php
                        $outs     = isset($dev['attrs']['outs']) ? (int)$dev['attrs']['outs'] : null;
                        $devLabel = device_type_label($dev['device_type'], $dev['attrs']);
                      ?>
                      <tr>
                        <td><strong><?= h($dev['name']) ?></strong></td>
                        <td class="text-nowrap">
                          <span class="text-muted me-1"><?= device_type_icon($dev['device_type']) ?></span><?= h($devLabel) ?>
                        </td>
                        <td class="small text-muted">
                          <?php
                            $ports = isset($dev['attrs']['ports']) ? (int)$dev['attrs']['ports'] : null;
                          ?>
                          <?php if (in_array($dev['device_type'], ['olt_pon', 'sfp'], true)): ?>
                            <?= ($outs && $outs > 1) ? 'OUT 1–' . $outs : 'OUT 1' ?>
                          <?php elseif (in_array($dev['device_type'], ['splitter', 'dwdm'], true) && $outs): ?>
                            IN, OUT 1–<?= $outs ?>
                          <?php elseif ($dev['device_type'] === 'patch_panel' && $ports): ?>
                            IN, P1–P<?= $ports ?>
                          <?php elseif ($dev['device_type'] === 'mpt' && $ports): ?>
                            <?php if (($dev['attrs']['mode'] ?? '') === 'split'): ?>
                              IN, OUT 1–<?= $ports ?>
                            <?php else: ?>
                              P1–P<?= $ports ?>
                            <?php endif; ?>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td class="small"><?= $dev['notes'] ? h($dev['notes']) : '' ?></td>
                        <td class="text-end text-nowrap">
                          <button class="btn btn-sm btn-outline-secondary"
                                  onclick="openDeviceSpliceModal(<?= (int)$dev['fosc_device_id'] ?>, <?= json_encode($dev['name']) ?>)">
                            Add Splice
                          </button>
                          <button class="btn btn-sm btn-outline-primary"
                                  onclick="openEditDeviceModal(<?= (int)$dev['fosc_device_id'] ?>, <?= htmlspecialchars(json_encode($dev['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($dev['device_type']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($dev['attrs']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($dev['notes'] ?? ''), ENT_QUOTES) ?>)">
                            Edit
                          </button>
                          <form method="post" class="d-inline"
                                onsubmit="return confirm('Delete device <?= h(addslashes($dev['name'])) ?>?');">
                            <input type="hidden" name="action"         value="delete_device">
                            <input type="hidden" name="csrf"           value="<?= h($CSRF) ?>">
                            <input type="hidden" name="fosc_device_id" value="<?= (int)$dev['fosc_device_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php
              // Active devices homed at this closure's POP — spliceable here but
              // managed on the POP/Devices pages, so shown read-only.
              $popDevices = array_values(array_filter($devicesAvailable, fn($d) => !empty($d['is_pop_device'])));
            ?>
            <?php if ($popDevices): ?>
              <h6 class="text-muted mt-4 mb-2">POP devices
                <a class="small ms-1" href="index.php?page=pop">manage</a>
              </h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead class="table-light">
                    <tr><th>Name</th><th>Type</th><th></th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($popDevices as $dev): ?>
                      <tr>
                        <td><strong><?= h($dev['name']) ?></strong>
                          <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-1">POP</span>
                        </td>
                        <td class="text-nowrap">
                          <span class="text-muted me-1"><?= device_type_icon($dev['device_type']) ?></span><?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?>
                        </td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-secondary"
                                  onclick="openDeviceSpliceModal(<?= (int)$dev['fosc_device_id'] ?>, <?= htmlspecialchars(json_encode($dev['name']), ENT_QUOTES) ?>)">
                            Add Splice
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- ── Patch ─────────────────────────────────────────────────────── -->
          <?php if ($devicesHere):
            // Build used-fiber/port sets from existing splice records
            $patchUsedFibers = [];  // ["$spanId-$end"][$fiberNum] = note string
            $patchUsedPorts  = [];  // [$deviceId][$portNum] = note string
            foreach ($splicesHere as $sj) {
                $note = trim((string)($sj['notes'] ?? ''));
                if ($sj['a_target'] === 'cable_span') {
                    $k = (int)$sj['a_span_id'] . '-' . $sj['a_end'];
                    for ($f = (int)$sj['a_fiber_lo']; $f < (int)$sj['a_fiber_hi']; $f++) {
                        $patchUsedFibers[$k][$f] = $note;
                    }
                }
                if ($sj['b_target'] === 'cable_span') {
                    $k = (int)$sj['b_span_id'] . '-' . $sj['b_end'];
                    for ($f = (int)$sj['b_fiber_lo']; $f < (int)$sj['b_fiber_hi']; $f++) {
                        $patchUsedFibers[$k][$f] = $note;
                    }
                }
                if ($sj['a_target'] === 'fosc_device') {
                    for ($p = (int)$sj['a_fiber_lo']; $p < (int)$sj['a_fiber_hi']; $p++) {
                        $patchUsedPorts[(int)$sj['a_span_id']][$p] = $note;
                    }
                }
                if ($sj['b_target'] === 'fosc_device') {
                    for ($p = (int)$sj['b_fiber_lo']; $p < (int)$sj['b_fiber_hi']; $p++) {
                        $patchUsedPorts[(int)$sj['b_span_id']][$p] = $note;
                    }
                }
            }
          ?>
          <div class="tab-pane fade p-3" id="tab-patch" role="tabpanel">

            <!-- ── Cable span fibers (top) ── -->
            <?php if ($spanEndpoints): ?>
            <p class="text-muted small mb-2">Cable fibers &mdash; select one to patch</p>
            <?php endif; ?>
            <?php foreach ($spanEndpoints as $ep):
                $epKey = (int)$ep['cable_span_id'] . '-' . $ep['ep_end'];
            ?>
            <div class="mb-4">
              <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <strong class="small"><?= h($ep['cable_name']) ?></strong>
                <?php foreach (array_filter(explode(',', $ep['tag_names'] ?? '')) as $tag): ?>
                  <span class="badge bg-secondary fw-normal"><?= h(trim($tag)) ?></span>
                <?php endforeach; ?>
                <span class="text-muted small">→ <?= h($ep['far_fosc_label'] ?? '?') ?></span>
                <span class="badge bg-light text-dark border small"><?= h(strtoupper($ep['ep_end'])) ?> end</span>
              </div>
              <?php
              $totalFibers = (int)$ep['fibre_count'];
              $half = (int)ceil($totalFibers / 2);
              // Two half-panels side by side, column-major (1-12 down col 1, 13-24 down col 2 …)
              ?>
              <div class="d-flex gap-3">
                <?php foreach ([[1, $half], [$half + 1, $totalFibers]] as [$lo, $hi]):
                    if ($lo > $totalFibers) break;
                ?>
                <div style="display:grid;grid-template-rows:repeat(12,auto);grid-auto-flow:column;gap:2px;">
                  <?php for ($f = $lo; $f <= $hi; $f++):
                      $fu = isset($patchUsedFibers[$epKey][$f]);
                      $fuNote = $fu ? ($patchUsedFibers[$epKey][$f] ?? '') : '';
                  ?>
                    <?php if ($fu): ?>
                    <span title="<?= h($fuNote ?: 'Already connected') ?>" style="display:inline-block">
                    <?php endif; ?>
                    <button type="button"
                            class="btn btn-sm<?= $fu ? ' btn-secondary cab-used' : ' btn-outline-primary cab-fiber-btn' ?>"
                            style="width:36px;padding:2px 0;font-size:.6rem;line-height:1.6"
                            data-span="<?= h($epKey) ?>"
                            data-fiber="<?= $f ?>"
                            data-label="F<?= $f ?>"
                            <?= $fu ? 'disabled' : '' ?>>
                      <?= $f ?>
                    </button>
                    <?php if ($fu): ?></span><?php endif; ?>
                  <?php endfor; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <?php if ($spanEndpoints): ?><hr class="my-3"><?php endif; ?>

            <!-- ── Device ports (bottom) ── -->
            <p class="text-muted small mb-2">Device ports &mdash; select one to patch</p>
            <?php foreach ($devicesAvailable as $dev):
                $devId    = (int)$dev['fosc_device_id'];
                $outs     = isset($dev['attrs']['outs']) ? (int)$dev['attrs']['outs'] : 0;
                // splitter/dwdm: port 0=IN, 1..outs=OUT N; olt_pon/sfp/other: port 1 only
                $portList = ($outs > 0)
                    ? range(0, $outs)
                    : [1];
            ?>
            <div class="mb-4">
              <div class="d-flex align-items-center gap-2 mb-2">
                <strong class="small"><?= h($dev['name']) ?></strong>
                <span class="text-muted small"><?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?></span>
              </div>
              <?php
              // Build port groups: IN alone, then OUT ports in chunks of 8
              if ($outs > 0) {
                  $portGroups = [[0]];
                  foreach (array_chunk(range(1, $outs), 8) as $chunk)
                      $portGroups[] = $chunk;
              } else {
                  $portGroups = [$portList];
              }
              ?>
              <div class="d-flex flex-column gap-1">
                <?php foreach ($portGroups as $grp): ?>
                <div class="d-flex gap-1">
                  <?php foreach ($grp as $p):
                      $pLabel  = ($p === 0) ? 'IN' : 'OUT ' . $p;
                      $pu      = isset($patchUsedPorts[$devId][$p]);
                      $puNote  = $pu ? ($patchUsedPorts[$devId][$p] ?? '') : '';
                  ?>
                    <?php if ($pu): ?>
                    <span title="<?= h($puNote ?: 'Already connected') ?>" style="display:inline-block">
                    <?php endif; ?>
                    <button type="button"
                            class="btn btn-sm<?= $pu ? ' btn-secondary cab-used' : ' btn-outline-success cab-port-btn' ?>"
                            style="min-width:44px;padding:2px 4px;font-size:.6rem;line-height:1.6"
                            data-device="<?= $devId ?>"
                            data-port="<?= $p ?>"
                            data-label="<?= h($pLabel) ?>"
                            <?= $pu ? 'disabled' : '' ?>>
                      <?= h($pLabel) ?>
                    </button>
                    <?php if ($pu): ?></span><?php endif; ?>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <hr class="my-3">

            <!-- ── Action bar + hidden form ── -->
            <div class="row g-2 align-items-end mb-2" style="max-width:520px">
              <div class="col">
                <label class="form-label small mb-1 text-muted">Notes (optional)</label>
                <input type="text" id="cabPatchNotes" class="form-control form-control-sm"
                       placeholder="e.g. port label, technician, date…">
              </div>
              <div class="col-auto">
                <button type="button" id="cabPatchConnect" class="btn btn-primary" disabled>
                  Patch
                </button>
              </div>
            </div>
            <div id="cabPatchStatus" class="text-muted small mb-2">
              Select a fiber and a port above
            </div>

            <form id="cabPatchForm" method="post" class="d-none">
              <input type="hidden" name="csrf"        value="<?= h($CSRF) ?>">
              <input type="hidden" name="action"      value="add_splice">
              <input type="hidden" name="join_kind"   value="connector">
              <input type="hidden" name="notes"       id="cp_notes" value="">
              <input type="hidden" name="a_type"      value="span">
              <input type="hidden" name="a_endpoint"  id="cp_a_endpoint"  value="">
              <input type="hidden" name="a_fiber_lo"  id="cp_a_fiber_lo"  value="">
              <input type="hidden" name="a_fiber_hi"  id="cp_a_fiber_hi"  value="">
              <input type="hidden" name="b_type"      value="device">
              <input type="hidden" name="b_device_id" id="cp_b_device_id" value="">
              <input type="hidden" name="b_port_lo"   id="cp_b_port_lo"   value="">
              <input type="hidden" name="b_port_hi"   id="cp_b_port_hi"   value="">
            </form>

            <script>
            (function () {
              var selSpan = null, selFiber = null, selFiberLabel = '';
              var selDevice = null, selPort = null, selPortLabel = '';

              function refresh() {
                var ready = selSpan !== null && selDevice !== null;
                document.getElementById('cabPatchConnect').disabled = !ready;
                var s = document.getElementById('cabPatchStatus');
                if (ready) {
                  s.textContent = selFiberLabel + ' \u2194 ' + selPortLabel;
                } else if (selSpan !== null) {
                  s.textContent = selFiberLabel + ' selected — now pick a port';
                } else if (selDevice !== null) {
                  s.textContent = selPortLabel + ' selected — now pick a fiber';
                } else {
                  s.textContent = 'Select a fiber and a port above';
                }
              }

              document.querySelectorAll('.cab-fiber-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  document.querySelectorAll('.cab-fiber-btn').forEach(function (b) {
                    b.classList.remove('btn-primary');
                    b.classList.add('btn-outline-primary');
                  });
                  this.classList.remove('btn-outline-primary');
                  this.classList.add('btn-primary');
                  selSpan       = this.dataset.span;
                  selFiber      = parseInt(this.dataset.fiber, 10);
                  selFiberLabel = this.dataset.label;
                  refresh();
                });
              });

              document.querySelectorAll('.cab-port-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  document.querySelectorAll('.cab-port-btn').forEach(function (b) {
                    b.classList.remove('btn-success');
                    b.classList.add('btn-outline-success');
                  });
                  this.classList.remove('btn-outline-success');
                  this.classList.add('btn-success');
                  selDevice    = parseInt(this.dataset.device, 10);
                  selPort      = parseInt(this.dataset.port, 10);
                  selPortLabel = this.dataset.label;
                  refresh();
                });
              });

              document.getElementById('cabPatchConnect').addEventListener('click', function () {
                if (selSpan === null || selDevice === null) return;
                document.getElementById('cp_a_endpoint').value  = selSpan;
                document.getElementById('cp_a_fiber_lo').value  = selFiber;
                document.getElementById('cp_a_fiber_hi').value  = selFiber;
                document.getElementById('cp_b_device_id').value = selDevice;
                document.getElementById('cp_b_port_lo').value   = selPort;
                document.getElementById('cp_b_port_hi').value   = selPort;
                document.getElementById('cp_notes').value       = document.getElementById('cabPatchNotes').value;
                document.getElementById('cabPatchForm').submit();
              });
            })();
            </script>

            <?php if (count($devicesAvailable) >= 2): ?>
            <hr class="my-4">
            <h6 class="text-muted small text-uppercase mb-3" style="letter-spacing:.05em">Patch Cable &mdash; Device Port to Device Port</h6>

            <!-- Source port picker -->
            <p class="text-muted small mb-2">Source port &mdash; select one</p>
            <?php foreach ($devicesAvailable as $dev):
                $devId = (int)$dev['fosc_device_id'];
                $outs  = isset($dev['attrs']['outs']) ? (int)$dev['attrs']['outs'] : 0;
                $portList = ($outs > 0) ? range(0, $outs) : [1];
                if ($outs > 0) {
                    $portGroups = [[0]];
                    foreach (array_chunk(range(1, $outs), 8) as $chunk) $portGroups[] = $chunk;
                } else {
                    $portGroups = [$portList];
                }
            ?>
            <div class="mb-3">
              <div class="d-flex align-items-center gap-2 mb-1">
                <strong class="small"><?= h($dev['name']) ?></strong>
                <span class="text-muted small"><?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?></span>
              </div>
              <div class="d-flex flex-column gap-1">
                <?php foreach ($portGroups as $grp): ?>
                <div class="d-flex gap-1">
                  <?php foreach ($grp as $p):
                      $pLabel = ($p === 0) ? 'IN' : 'OUT ' . $p;
                      $pu     = isset($patchUsedPorts[$devId][$p]);
                      $puNote = $pu ? ($patchUsedPorts[$devId][$p] ?? '') : '';
                  ?>
                    <?php if ($pu): ?><span title="<?= h($puNote ?: 'Already connected') ?>" style="display:inline-block"><?php endif; ?>
                    <button type="button"
                            class="btn btn-sm<?= $pu ? ' btn-secondary cab-used' : ' btn-outline-primary pp-src-btn' ?>"
                            style="min-width:44px;padding:2px 4px;font-size:.6rem;line-height:1.6"
                            data-device="<?= $devId ?>"
                            data-port="<?= $p ?>"
                            data-label="<?= h($dev['name'] . ' ' . $pLabel) ?>"
                            <?= $pu ? 'disabled' : '' ?>>
                      <?= h($pLabel) ?>
                    </button>
                    <?php if ($pu): ?></span><?php endif; ?>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <hr class="my-2">

            <!-- Destination port picker -->
            <p class="text-muted small mb-2">Destination port &mdash; select one (different device)</p>
            <?php foreach ($devicesAvailable as $dev):
                $devId = (int)$dev['fosc_device_id'];
                $outs  = isset($dev['attrs']['outs']) ? (int)$dev['attrs']['outs'] : 0;
                $portList = ($outs > 0) ? range(0, $outs) : [1];
                if ($outs > 0) {
                    $portGroups = [[0]];
                    foreach (array_chunk(range(1, $outs), 8) as $chunk) $portGroups[] = $chunk;
                } else {
                    $portGroups = [$portList];
                }
            ?>
            <div class="mb-3">
              <div class="d-flex align-items-center gap-2 mb-1">
                <strong class="small"><?= h($dev['name']) ?></strong>
                <span class="text-muted small"><?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?></span>
              </div>
              <div class="d-flex flex-column gap-1">
                <?php foreach ($portGroups as $grp): ?>
                <div class="d-flex gap-1">
                  <?php foreach ($grp as $p):
                      $pLabel = ($p === 0) ? 'IN' : 'OUT ' . $p;
                      $pu     = isset($patchUsedPorts[$devId][$p]);
                      $puNote = $pu ? ($patchUsedPorts[$devId][$p] ?? '') : '';
                  ?>
                    <?php if ($pu): ?><span title="<?= h($puNote ?: 'Already connected') ?>" style="display:inline-block"><?php endif; ?>
                    <button type="button"
                            class="btn btn-sm<?= $pu ? ' btn-secondary cab-used' : ' btn-outline-success pp-dst-btn' ?>"
                            style="min-width:44px;padding:2px 4px;font-size:.6rem;line-height:1.6"
                            data-device="<?= $devId ?>"
                            data-port="<?= $p ?>"
                            data-label="<?= h($dev['name'] . ' ' . $pLabel) ?>"
                            <?= $pu ? 'disabled' : '' ?>>
                      <?= h($pLabel) ?>
                    </button>
                    <?php if ($pu): ?></span><?php endif; ?>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- Action bar -->
            <div class="row g-2 align-items-end mb-2" style="max-width:520px">
              <div class="col">
                <label class="form-label small mb-1 text-muted">Notes (optional)</label>
                <input type="text" id="ppNotes" class="form-control form-control-sm"
                       placeholder="e.g. patch cord label, tech, date…">
              </div>
              <div class="col-auto">
                <button type="button" id="ppConnect" class="btn btn-success" disabled>
                  Connect
                </button>
              </div>
            </div>
            <div id="ppStatus" class="text-muted small mb-2">
              Select a source port and a destination port above
            </div>

            <form id="ppForm" method="post" class="d-none">
              <input type="hidden" name="csrf"        value="<?= h($CSRF) ?>">
              <input type="hidden" name="action"      value="add_splice">
              <input type="hidden" name="join_kind"   value="connector">
              <input type="hidden" name="is_plan"     value="0">
              <input type="hidden" name="a_type"      value="device">
              <input type="hidden" name="a_device_id" id="pp_a_device_id" value="">
              <input type="hidden" name="a_port_lo"   id="pp_a_port_lo"   value="">
              <input type="hidden" name="a_port_hi"   id="pp_a_port_hi"   value="">
              <input type="hidden" name="b_type"      value="device">
              <input type="hidden" name="b_device_id" id="pp_b_device_id" value="">
              <input type="hidden" name="b_port_lo"   id="pp_b_port_lo"   value="">
              <input type="hidden" name="b_port_hi"   id="pp_b_port_hi"   value="">
              <input type="hidden" name="notes"       id="pp_notes"       value="">
            </form>

            <script>
            (function () {
              var srcDev = null, srcPort = null, srcLabel = '';
              var dstDev = null, dstPort = null, dstLabel = '';

              function ppRefresh() {
                var samePortSameDev = (srcDev !== null && dstDev !== null
                  && srcDev === dstDev && srcPort === dstPort);
                var ready = srcDev !== null && dstDev !== null && !samePortSameDev;
                document.getElementById('ppConnect').disabled = !ready;
                var s = document.getElementById('ppStatus');
                if (ready) {
                  s.textContent = srcLabel + ' \u2194 ' + dstLabel;
                } else if (samePortSameDev) {
                  s.textContent = 'Source and destination must differ';
                } else if (srcDev !== null) {
                  s.textContent = srcLabel + ' \u2014 now pick a destination port';
                } else if (dstDev !== null) {
                  s.textContent = dstLabel + ' \u2014 now pick a source port';
                } else {
                  s.textContent = 'Select a source port and a destination port above';
                }
              }

              document.querySelectorAll('.pp-src-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  document.querySelectorAll('.pp-src-btn').forEach(function (b) {
                    b.classList.remove('btn-primary');
                    b.classList.add('btn-outline-primary');
                  });
                  this.classList.remove('btn-outline-primary');
                  this.classList.add('btn-primary');
                  srcDev   = parseInt(this.dataset.device, 10);
                  srcPort  = parseInt(this.dataset.port, 10);
                  srcLabel = this.dataset.label;
                  ppRefresh();
                });
              });

              document.querySelectorAll('.pp-dst-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  document.querySelectorAll('.pp-dst-btn').forEach(function (b) {
                    b.classList.remove('btn-success');
                    b.classList.add('btn-outline-success');
                  });
                  this.classList.remove('btn-outline-success');
                  this.classList.add('btn-success');
                  dstDev   = parseInt(this.dataset.device, 10);
                  dstPort  = parseInt(this.dataset.port, 10);
                  dstLabel = this.dataset.label;
                  ppRefresh();
                });
              });

              document.getElementById('ppConnect').addEventListener('click', function () {
                if (srcDev === null || dstDev === null) return;
                document.getElementById('pp_a_device_id').value = srcDev;
                document.getElementById('pp_a_port_lo').value   = srcPort;
                document.getElementById('pp_a_port_hi').value   = srcPort;
                document.getElementById('pp_b_device_id').value = dstDev;
                document.getElementById('pp_b_port_lo').value   = dstPort;
                document.getElementById('pp_b_port_hi').value   = dstPort;
                document.getElementById('pp_notes').value       = document.getElementById('ppNotes').value;
                document.getElementById('ppForm').submit();
              });
            })();
            </script>
            <?php endif; ?>

          </div><!-- #tab-patch -->
          <?php endif; ?>

          <!-- ── Timeline ──────────────────────────────────────────────────── -->
          <div class="tab-pane fade p-3" id="tab-timeline" role="tabpanel">
            <div class="mb-3">
              <a class="btn btn-outline-success btn-sm"
                 href="/index.php?page=timeline_entry&fosc_id=<?= (int)$fosc['fosc_id'] ?>">
                + Add Timeline Entry
              </a>
            </div>
            <?php
              if ($timeline['count'] === 0) {
                  echo '<div class="alert alert-secondary">No timeline entries yet.</div>';
              } else {
                  echo $timeline['html'];
              }
            ?>
          </div>

          <!-- ── OTDR Tab ──────────────────────────────────────────────── -->
          <div class="tab-pane fade p-3" id="tab-otdr" role="tabpanel">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="text-muted small">OTDR traces linked to this closure.</span>
              <a class="btn btn-sm btn-outline-secondary"
                 href="/index.php?page=otdr">Manage in OTDR Library →</a>
            </div>
            <div id="otdrResultsWrap">
              <p class="text-muted">Loading…</p>
            </div>
            <template id="otdrExpandTpl">
              <div class="border rounded bg-light p-3 mb-3 otdr-expand">
                <div class="row g-3">
                  <div class="col-lg-8">
                    <canvas class="otdr-chart" height="180"></canvas>
                  </div>
                  <div class="col-lg-4">
                    <h6>Events</h6>
                    <div style="max-height:220px;overflow-y:auto">
                      <table class="table table-sm mb-0">
                        <thead><tr><th>#</th><th>Dist (m)</th><th>Loss (dB)</th><th>Refl (dB)</th><th>Type</th></tr></thead>
                        <tbody class="events-body"></tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </template>
            <script>
            (function () {
              const fosc_id = <?= (int)($foscId ?? 0) ?>;
              const CSRF    = <?= json_encode($CSRF) ?>;
              const charts  = {};

              async function loadOtdrTab() {
                const resp = await fetch('/api/otdr_result.php?fosc_id=' + fosc_id);
                const data = await resp.json();
                const wrap = document.getElementById('otdrResultsWrap');
                if (!data.ok || !data.results.length) {
                  wrap.innerHTML = '<p class="text-muted">No OTDR results linked to this closure. '
                    + '<a href="/index.php?page=otdr">Upload and link files from the OTDR Library.</a></p>';
                  return;
                }
                wrap.innerHTML = '';
                for (const r of data.results) {
                  const card = document.createElement('div');
                  card.className = 'card mb-3';
                  const lengthStr = r.fiber_length_m != null ? (parseFloat(r.fiber_length_m) / 1000).toFixed(3) + ' km' : '—';
                  const lossStr   = r.total_loss_db  != null ? parseFloat(r.total_loss_db).toFixed(2) + ' dB'  : '—';
                  const testedStr = r.tested_at ? r.tested_at.replace('T', ' ').substring(0, 16) : '—';
                  const spanLabel = r.cable_span_name ? esc(r.cable_span_name) : '—';
                  card.innerHTML = `
                    <div class="card-header d-flex align-items-center gap-3 py-2">
                      <strong>${esc(r.filename)}</strong>
                      <span class="text-muted small">${esc(testedStr)}</span>
                      ${r.wavelength_nm ? '<span class="badge bg-secondary">' + r.wavelength_nm + ' nm</span>' : ''}
                      ${r.fiber_num ? '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">F' + r.fiber_num + '</span>' : ''}
                      <span class="ms-auto small text-muted">${lengthStr} &nbsp;|&nbsp; ${lossStr}</span>
                      <span class="small text-muted">${spanLabel}</span>
                      <button class="btn btn-sm btn-outline-secondary btn-expand-otdr" data-id="${r.id}" data-open="0">Show Trace</button>
                      <button class="btn btn-sm btn-outline-warning btn-unlink-otdr" data-id="${r.id}">Unlink</button>
                    </div>
                    <div class="card-body expand-body-${r.id}" style="display:none"></div>
                  `;
                  wrap.appendChild(card);

                  card.querySelector('.btn-expand-otdr').addEventListener('click', async function () {
                    const id   = parseInt(this.dataset.id);
                    const body = card.querySelector('.expand-body-' + id);
                    if (this.dataset.open === '1') {
                      body.style.display = 'none';
                      this.dataset.open = '0';
                      this.textContent  = 'Show Trace';
                      if (charts[id]) { charts[id].destroy(); delete charts[id]; }
                      return;
                    }
                    this.dataset.open = '1';
                    this.textContent  = 'Hide Trace';
                    body.style.display = '';
                    body.innerHTML     = '<p class="text-muted">Loading…</p>';

                    const resp2 = await fetch('/api/otdr_result.php?id=' + id);
                    const d2    = await resp2.json();
                    if (!d2.ok) { body.innerHTML = '<p class="text-danger">Load failed.</p>'; return; }

                    const tpl  = document.getElementById('otdrExpandTpl').content.cloneNode(true);
                    const wrap2 = tpl.querySelector('.otdr-expand');

                    // Events
                    const evTbody = tpl.querySelector('.events-body');
                    for (const ev of (d2.result.events ?? [])) {
                      const row = document.createElement('tr');
                      row.innerHTML = `<td>${ev.num}</td><td>${ev.distance_m?.toFixed(0) ?? '—'}</td><td>${ev.loss_db != null ? ev.loss_db.toFixed(3) : '—'}</td><td>${ev.reflectance_db != null ? ev.reflectance_db.toFixed(3) : '—'}</td><td><code>${esc(ev.type ?? '')}</code></td>`;
                      evTbody.appendChild(row);
                    }

                    body.innerHTML = '';
                    body.appendChild(tpl);

                    // Chart
                    const pts = d2.result.trace_pts ?? [];
                    if (pts.length) {
                      const canvas = body.querySelector('canvas.otdr-chart');
                      const step   = Math.max(1, Math.floor(pts.length / 2000));
                      const ds     = pts.filter((_, i) => i % step === 0);
                      charts[id] = new Chart(canvas.getContext('2d'), {
                        type: 'line',
                        data: {
                          labels: ds.map(p => p[0].toFixed(1)),
                          datasets: [{ data: ds.map(p => Math.abs(p[1])), borderColor: '#0d6efd', borderWidth: 1, pointRadius: 0, tension: 0 }]
                        },
                        options: {
                          responsive: true, animation: false,
                          plugins: { legend: { display: false } },
                          scales: {
                            x: { title: { display: true, text: 'Distance (m)' }, ticks: { maxTicksLimit: 10 } },
                            y: { reverse: true, title: { display: true, text: 'Level (dB)' } }
                          }
                        }
                      });
                    } else {
                      body.querySelector('.col-lg-8').innerHTML = '<p class="text-muted">No trace data.</p>';
                    }
                  });

                  card.querySelector('.btn-unlink-otdr').addEventListener('click', async function () {
                    if (!confirm('Unlink this OTDR result from the closure?')) return;
                    const id = parseInt(this.dataset.id);
                    await fetch('/api/otdr_result.php', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                      body: JSON.stringify({ id, unlink: true }),
                    });
                    loadOtdrTab();
                  });
                }
              }

              function esc(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
              }

              // Load when tab becomes active
              document.getElementById('tab-otdr-btn').addEventListener('shown.bs.tab', function () {
                if (!document.getElementById('otdrResultsWrap').dataset.loaded) {
                  document.getElementById('otdrResultsWrap').dataset.loaded = '1';
                  loadOtdrTab();
                }
              });
            })();
            </script>
          </div>

        </div><!-- end tab-content -->

        <!-- ── Modals ────────────────────────────────────────────────────── -->

        <!-- Add Patch Cable Modal (creates cable_span + splice in one step) -->
        <div class="modal fade" id="addPatchCableModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <form method="post">
                <input type="hidden" name="action" value="add_patch_cable">
                <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Add Patch Cable at <?= h($fosc['splice_num']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                  <p class="text-muted small mb-3">
                    Creates a new cable span (the physical patch cord) and splices its fiber to an existing cable endpoint — all in one step.
                  </p>

                  <!-- ── Patch cable (A side) ── -->
                  <div class="card mb-3">
                    <div class="card-header py-2"><strong>Patch Cable</strong></div>
                    <div class="card-body">
                      <div class="row g-2 mb-2">
                        <div class="col-md-6">
                          <label class="form-label small mb-1">Cable type</label>
                          <select name="pc_cable_id" class="form-select form-select-sm" required>
                            <option value="">Select…</option>
                            <?php foreach ($allCables as $cab): ?>
                              <option value="<?= (int)$cab['cable_id'] ?>"
                                      data-fibre-count="<?= (int)$cab['fibre_count'] ?>"
                                      <?= ((int)$cab['cable_id'] === $patchSuggestCableId) ? 'selected' : '' ?>>
                                <?= h($cab['name']) ?> — <?= (int)$cab['fibre_count'] ?>F
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label small mb-1">Far-end closure</label>
                          <select name="pc_far_fosc_id" class="form-select form-select-sm" required>
                            <option value="">Select…</option>
                            <?php foreach ($allFoscs as $fc): ?>
                              <?php if ((int)$fc['fosc_id'] === $foscId) continue; ?>
                              <option value="<?= (int)$fc['fosc_id'] ?>"
                                      <?= ((int)$fc['fosc_id'] === $patchSuggestFarFoscId) ? 'selected' : '' ?>>
                                <?= h($fc['splice_num']) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                      <div class="row g-2">
                        <div class="col">
                          <label class="form-label small mb-1">Fiber lo</label>
                          <input type="number" name="pc_a_fiber_lo" class="form-control form-control-sm"
                                 min="1" step="1" value="1" required>
                        </div>
                        <div class="col">
                          <label class="form-label small mb-1">Fiber hi</label>
                          <input type="number" name="pc_a_fiber_hi" id="pcAFiberHi" class="form-control form-control-sm"
                                 min="1" step="1" value="1" required>
                        </div>
                      </div>
                      <div class="form-text" id="pcFiberHelp">
                        Fiber range within the patch cable (usually 1–1 for a single-fiber patch cord).
                      </div>
                    </div>
                  </div>

                  <!-- ── Destination endpoint (B side) ── -->
                  <div class="card mb-3">
                    <div class="card-header py-2"><strong>Destination</strong></div>
                    <div class="card-body">
                      <div class="mb-2">
                        <label class="form-label small mb-1">Cable endpoint</label>
                        <select name="pc_b_endpoint" id="pcBEndpoint" class="form-select form-select-sm" required>
                          <option value="">Select…</option>
                          <?= render_endpoint_options($spanEndpoints) ?>
                        </select>
                      </div>
                      <?= render_fiber_range_picker('pc_b_fiber_lo', 'pc_b_fiber_hi', 'pcBFiber', '#pcBEndpoint', 'fibre') ?>
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label small">Layer</label><br>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="is_plan" id="pcLayerActual" value="0" checked>
                        <label class="form-check-label small" for="pcLayerActual">Actual</label>
                      </div>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="is_plan" id="pcLayerPlan" value="1">
                        <label class="form-check-label small" for="pcLayerPlan">Plan</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label small">Notes (optional)</label>
                      <textarea class="form-control form-control-sm" name="notes" rows="2"></textarea>
                    </div>
                  </div>

                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-success btn-sm" type="submit">Add Patch Cable</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Terminate Cable Modal (auto-creates/reuses a patch_panel + cable->panel splice) -->
        <div class="modal fade" id="terminateCableModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <form method="post">
                <input type="hidden" name="action" value="terminate_cable">
                <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Terminate Cable at <?= h($fosc['splice_num']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <p class="text-muted small mb-3">
                    Splices every fibre in the chosen range onto connectors on a patch panel
                    (port&nbsp;N&nbsp;=&nbsp;fibre&nbsp;N). Patch cords then hook those connectors to other gear.
                  </p>

                  <div class="mb-3">
                    <label class="form-label small mb-1">Cable endpoint</label>
                    <select name="tc_endpoint" class="form-select form-select-sm" required>
                      <option value="">Select…</option>
                      <?= render_endpoint_options($spanEndpoints) ?>
                    </select>
                  </div>

                  <div class="row g-2 mb-2">
                    <div class="col">
                      <label class="form-label small mb-1">Fibre lo</label>
                      <input type="number" name="tc_fiber_lo" class="form-control form-control-sm"
                             min="1" step="1" value="1" required>
                    </div>
                    <div class="col">
                      <label class="form-label small mb-1">Fibre hi</label>
                      <input type="number" name="tc_fiber_hi" class="form-control form-control-sm"
                             min="1" step="1" placeholder="e.g. 48" required>
                    </div>
                    <div class="col">
                      <label class="form-label small mb-1">Connector</label>
                      <select name="tc_connector" class="form-select form-select-sm">
                        <?php foreach (['LC/UPC','LC/APC','LC','SC','SC/APC','FC/APC','FC/PC','E2000','MTP'] as $cn): ?>
                          <option value="<?= h($cn) ?>"><?= h($cn) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label small mb-1">Panel</label>
                    <select name="tc_panel_id" id="tcPanelId" class="form-select form-select-sm"
                            onchange="document.getElementById('tcPanelNameRow').style.display = this.value==='0' ? '' : 'none';">
                      <option value="0">Create new panel…</option>
                      <?php foreach ($devicesHere as $dev): if ($dev['device_type'] !== 'patch_panel') continue; ?>
                        <option value="<?= (int)$dev['fosc_device_id'] ?>">
                          <?= h($dev['name']) ?> — <?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3" id="tcPanelNameRow">
                    <label class="form-label small mb-1">New panel name</label>
                    <input type="text" name="tc_panel_name" class="form-control form-control-sm"
                           value="<?= h($fosc['splice_num']) ?>-PP">
                  </div>

                  <div class="mb-1">
                    <label class="form-label small">Layer</label><br>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="radio" name="is_plan" id="tcLayerActual" value="0" checked>
                      <label class="form-check-label small" for="tcLayerActual">Actual</label>
                    </div>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="radio" name="is_plan" id="tcLayerPlan" value="1">
                      <label class="form-check-label small" for="tcLayerPlan">Plan</label>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary btn-sm" type="submit">Terminate</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Unified Add Splice Modal -->
        <div class="modal fade" id="addSpliceModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <form method="post">
                <input type="hidden" name="action" value="add_splice">
                <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Add Splice at <?= h($fosc['splice_num']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                  <!-- ── A Side ── -->
                  <div class="card mb-3">
                    <div class="card-header py-2 d-flex align-items-center gap-3">
                      <strong>A Side</strong>
                      <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="a_type" id="aTypeSpan" value="span" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="aTypeSpan">Cable Span</label>
                        <input type="radio" class="btn-check" name="a_type" id="aTypeDevice" value="device" autocomplete="off">
                        <label class="btn btn-outline-primary" for="aTypeDevice">Device Port</label>
                      </div>
                    </div>
                    <div class="card-body">
                      <!-- A span section -->
                      <div id="aSideSpan">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Endpoint</label>
                          <select name="a_endpoint" id="aEndpoint" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?= render_endpoint_options($spanEndpoints) ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('a_fiber_lo', 'a_fiber_hi', 'aFiber', '#aEndpoint', 'fibre', true) ?>
                      </div>
                      <!-- A device section -->
                      <div id="aSideDevice" class="d-none">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Device</label>
                          <select name="a_device_id" id="aDeviceSelect" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?php foreach ($devicesAvailable as $dev): ?>
                              <option value="<?= (int)$dev['fosc_device_id'] ?>"
                                      data-device-type="<?= h($dev['device_type']) ?>"
                                      data-mpt-mode="<?= h($dev['attrs']['mode'] ?? '') ?>"
                                      data-outs="<?= (int)($dev['attrs']['outs'] ?? $dev['attrs']['ports'] ?? 1) ?>">
                                <?= h($dev['name']) ?> — <?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?><?= !empty($dev['is_pop_device']) ? ' · POP' : '' ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('a_port_lo', 'a_port_hi', 'aPort', '#aDeviceSelect', 'port', true) ?>
                        <div class="form-text" id="aPortHelp">Port 0 = IN, ports 1+ = outputs</div>
                      </div>
                    </div>
                  </div>

                  <!-- ── B Side ── -->
                  <div class="card mb-3">
                    <div class="card-header py-2 d-flex align-items-center gap-3">
                      <strong>B Side</strong>
                      <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="b_type" id="bTypeSpan" value="span" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="bTypeSpan">Cable Span</label>
                        <input type="radio" class="btn-check" name="b_type" id="bTypeDevice" value="device" autocomplete="off">
                        <label class="btn btn-outline-primary" for="bTypeDevice">Device Port</label>
                      </div>
                    </div>
                    <div class="card-body">
                      <!-- B span section -->
                      <div id="bSideSpan">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Endpoint</label>
                          <select name="b_endpoint" id="bEndpoint" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?= render_endpoint_options($spanEndpoints) ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('b_fiber_lo', 'b_fiber_hi', 'bFiber', '#bEndpoint', 'fibre', true) ?>
                      </div>
                      <!-- B device section -->
                      <div id="bSideDevice" class="d-none">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Device</label>
                          <select name="b_device_id" class="form-select form-select-sm" id="bDeviceSelect">
                            <option value="">Select…</option>
                            <?php foreach ($devicesAvailable as $dev): ?>
                              <option value="<?= (int)$dev['fosc_device_id'] ?>"
                                      data-device-type="<?= h($dev['device_type']) ?>"
                                      data-mpt-mode="<?= h($dev['attrs']['mode'] ?? '') ?>"
                                      data-outs="<?= (int)($dev['attrs']['outs'] ?? $dev['attrs']['ports'] ?? 1) ?>">
                                <?= h($dev['name']) ?> — <?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?><?= !empty($dev['is_pop_device']) ? ' · POP' : '' ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('b_port_lo', 'b_port_hi', 'bPort', '#bDeviceSelect', 'port', true) ?>
                        <div class="form-text" id="bPortHelp">Port 0 = IN, ports 1+ = outputs</div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-4">
                      <label class="form-label small">Splice type</label>
                      <select name="join_kind" id="nSpliceJoinKind" class="form-select form-select-sm">
                        <option value="single" selected>Single (fusion)</option>
                        <option value="ribbon">Ribbon (mass fusion)</option>
                        <option value="pass_through">Pass-through (no cut)</option>
                        <option value="connector">Connector (SC/APC, LC…)</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small">Layer</label><br>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="is_plan" id="spliceLayerActual" value="0" checked>
                        <label class="form-check-label small" for="spliceLayerActual">Actual</label>
                      </div>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="is_plan" id="spliceLayerPlan" value="1">
                        <label class="form-check-label small" for="spliceLayerPlan">Plan</label>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small">Notes (optional)</label>
                      <textarea class="form-control form-control-sm" name="notes" rows="2"></textarea>
                    </div>
                  </div>

                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary btn-sm" type="submit">Add Splice</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Edit Splice Modal -->
        <div class="modal fade" id="editSpliceModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <form method="post">
                <input type="hidden" name="action"         value="edit_splice">
                <input type="hidden" name="csrf"           value="<?= h($CSRF) ?>">
                <input type="hidden" name="splice_join_id" id="eSpliceJoinId" value="">
                <div class="modal-header">
                  <h5 class="modal-title">Edit Splice <span id="editSpliceTitle"></span></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                  <!-- ── A Side ── -->
                  <div class="card mb-3">
                    <div class="card-header py-2 d-flex align-items-center gap-3">
                      <strong>A Side</strong>
                      <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="ea_type" id="eATypeSpan" value="span" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="eATypeSpan">Cable Span</label>
                        <input type="radio" class="btn-check" name="ea_type" id="eATypeDevice" value="device" autocomplete="off">
                        <label class="btn btn-outline-primary" for="eATypeDevice">Device Port</label>
                      </div>
                    </div>
                    <div class="card-body">
                      <div id="eASideSpan">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Endpoint</label>
                          <select name="a_endpoint" id="eAEndpoint" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?= render_endpoint_options($spanEndpoints) ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('a_fiber_lo', 'a_fiber_hi', 'eAFiber', '#eAEndpoint', 'fibre') ?>
                      </div>
                      <div id="eASideDevice" class="d-none">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Device</label>
                          <select name="a_device_id" id="eADeviceSelect" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?php foreach ($devicesAvailable as $dev): ?>
                              <option value="<?= (int)$dev['fosc_device_id'] ?>"
                                      data-device-type="<?= h($dev['device_type']) ?>"
                                      data-mpt-mode="<?= h($dev['attrs']['mode'] ?? '') ?>"
                                      data-outs="<?= (int)($dev['attrs']['outs'] ?? $dev['attrs']['ports'] ?? 1) ?>">
                                <?= h($dev['name']) ?> — <?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?><?= !empty($dev['is_pop_device']) ? ' · POP' : '' ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('a_port_lo', 'a_port_hi', 'eAPort', '#eADeviceSelect', 'port') ?>
                        <div class="form-text" id="eAPortHelp">Port 0 = IN, ports 1+ = outputs</div>
                      </div>
                    </div>
                  </div>

                  <!-- ── B Side ── -->
                  <div class="card mb-3">
                    <div class="card-header py-2 d-flex align-items-center gap-3">
                      <strong>B Side</strong>
                      <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="eb_type" id="eBTypeSpan" value="span" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="eBTypeSpan">Cable Span</label>
                        <input type="radio" class="btn-check" name="eb_type" id="eBTypeDevice" value="device" autocomplete="off">
                        <label class="btn btn-outline-primary" for="eBTypeDevice">Device Port</label>
                      </div>
                    </div>
                    <div class="card-body">
                      <div id="eBSideSpan">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Endpoint</label>
                          <select name="b_endpoint" id="eBEndpoint" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?= render_endpoint_options($spanEndpoints) ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('b_fiber_lo', 'b_fiber_hi', 'eBFiber', '#eBEndpoint', 'fibre') ?>
                      </div>
                      <div id="eBSideDevice" class="d-none">
                        <div class="mb-2">
                          <label class="form-label small mb-1">Device</label>
                          <select name="b_device_id" id="eBDeviceSelect" class="form-select form-select-sm">
                            <option value="">Select…</option>
                            <?php foreach ($devicesAvailable as $dev): ?>
                              <option value="<?= (int)$dev['fosc_device_id'] ?>"
                                      data-device-type="<?= h($dev['device_type']) ?>"
                                      data-mpt-mode="<?= h($dev['attrs']['mode'] ?? '') ?>"
                                      data-outs="<?= (int)($dev['attrs']['outs'] ?? $dev['attrs']['ports'] ?? 1) ?>">
                                <?= h($dev['name']) ?> — <?= h(device_type_label($dev['device_type'], $dev['attrs'])) ?><?= !empty($dev['is_pop_device']) ? ' · POP' : '' ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?= render_fiber_range_picker('b_port_lo', 'b_port_hi', 'eBPort', '#eBDeviceSelect', 'port') ?>
                        <div class="form-text" id="eBPortHelp">Port 0 = IN, ports 1+ = outputs</div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-4">
                      <label class="form-label small">Splice type</label>
                      <select name="join_kind" id="eSpliceJoinKind" class="form-select form-select-sm">
                        <option value="single">Single (fusion)</option>
                        <option value="ribbon">Ribbon (mass fusion)</option>
                        <option value="pass_through">Pass-through (no cut)</option>
                        <option value="connector">Connector (SC/APC, LC…)</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small">Layer</label>
                      <div class="d-flex gap-3 mt-1">
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="is_plan" id="eLayerActual" value="0" checked>
                          <label class="form-check-label small" for="eLayerActual">Actual</label>
                        </div>
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="is_plan" id="eLayerPlan" value="1">
                          <label class="form-check-label small" for="eLayerPlan">Plan</label>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small">Notes (optional)</label>
                      <textarea class="form-control form-control-sm" name="notes" id="eSpliceNotes" rows="2"></textarea>
                    </div>
                  </div>

                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Add Device Modal -->
        <div class="modal fade" id="addDeviceModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post">
                <input type="hidden" name="action" value="add_device">
                <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Add Device to <?= h($fosc['splice_num']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="device_name" required
                           placeholder="e.g. PLC-1">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="device_type" id="devType" required>
                      <!-- Passive gear only: a closure is a field enclosure, so active /
                           laser-source devices (DWDM, OLT PON, SFP) live at a POP —
                           chk_closure_device_passive enforces it. -->
                      <option value="splitter" selected>Splitter (PLC)</option>
                      <option value="patch_panel">Patch Panel / Connector Panel</option>
                      <option value="oadm">OADM</option>
                      <option value="mpt">MPT (Multiport Terminal)</option>
                    </select>
                  </div>
                  <div class="mb-3" id="devMptRow" style="display:none">
                    <div class="row g-2">
                      <div class="col-sm-7">
                        <label class="form-label">MPT type <span class="text-danger">*</span></label>
                        <select class="form-select" name="device_mpt_mode" id="devMptMode">
                          <option value="passive" selected>Passive (pass-through)</option>
                          <option value="split">Internal 1×N splitter</option>
                        </select>
                      </div>
                      <div class="col-sm-5">
                        <label class="form-label">Ports <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="device_mpt_ports" id="devMptPorts"
                               min="1" max="128" value="4" placeholder="e.g. 4">
                        <div class="form-text" id="devMptHelp">Number of ports (1–128)</div>
                      </div>
                    </div>
                  </div>
                  <div class="mb-3" id="devOutsRow">
                    <label class="form-label"><span id="devOutsLabel">Output ports</span> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="devOuts" name="device_outs" min="2" max="128" value="4">
                    <div class="form-text" id="devOutsHelp">Number of output ports (2–128)</div>
                  </div>
                  <div class="mb-3" id="devPatchRow" style="display:none">
                    <div class="row g-2">
                      <div class="col-sm-7">
                        <label class="form-label">Connector type <span class="text-danger">*</span></label>
                        <select class="form-select" name="device_connector" id="devConnector">
                          <option value="LC" selected>LC</option>
                          <option value="SC">SC</option>
                          <option value="SC/APC">SC/APC</option>
                          <option value="FC/APC">FC/APC</option>
                          <option value="FC/PC">FC/PC</option>
                          <option value="E2000">E2000</option>
                          <option value="MTP">MTP/MPO</option>
                        </select>
                      </div>
                      <div class="col-sm-5">
                        <label class="form-label">Port count <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="device_ports" id="devPorts"
                               min="1" max="1000" value="12" placeholder="e.g. 12">
                        <div class="form-text">Ports (1–1000)</div>
                      </div>
                    </div>
                  </div>
                  <div class="mb-1">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="device_notes" rows="2"></textarea>
                  </div>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary btn-sm" type="submit">Add Device</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Edit Device Modal -->
        <div class="modal fade" id="editDeviceModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post">
                <input type="hidden" name="action"         value="edit_device">
                <input type="hidden" name="csrf"           value="<?= h($CSRF) ?>">
                <input type="hidden" name="fosc_device_id" id="editDevId">
                <div class="modal-header">
                  <h5 class="modal-title">Edit Device</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="device_name" id="editDevName" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="device_type" id="editDevType" required>
                      <option value="splitter">Splitter (PLC)</option>
                      <option value="patch_panel">Patch Panel / Connector Panel</option>
                      <option value="oadm">OADM</option>
                      <option value="mpt">MPT (Multiport Terminal)</option>
                    </select>
                  </div>
                  <div class="mb-3" id="editDevMptRow">
                    <div class="row g-2">
                      <div class="col-sm-7">
                        <label class="form-label">MPT type <span class="text-danger">*</span></label>
                        <select class="form-select" name="device_mpt_mode" id="editDevMptMode">
                          <option value="passive">Passive (pass-through)</option>
                          <option value="split">Internal 1×N splitter</option>
                        </select>
                      </div>
                      <div class="col-sm-5">
                        <label class="form-label">Ports <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="device_mpt_ports" id="editDevMptPorts"
                               min="1" max="128">
                        <div class="form-text" id="editDevMptHelp">Number of ports (1–128)</div>
                      </div>
                    </div>
                  </div>
                  <div class="mb-3" id="editDevOutsRow">
                    <label class="form-label"><span id="editDevOutsLabel">Output ports</span> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="device_outs" id="editDevOuts" min="2" max="128">
                    <div class="form-text" id="editDevOutsHelp">Number of output ports (2–128)</div>
                  </div>
                  <div class="mb-3" id="editDevPatchRow">
                    <div class="row g-2">
                      <div class="col-sm-7">
                        <label class="form-label">Connector type <span class="text-danger">*</span></label>
                        <select class="form-select" name="device_connector" id="editDevConnector">
                          <option value="LC">LC</option>
                          <option value="SC">SC</option>
                          <option value="SC/APC">SC/APC</option>
                          <option value="FC/APC">FC/APC</option>
                          <option value="FC/PC">FC/PC</option>
                          <option value="E2000">E2000</option>
                          <option value="MTP">MTP/MPO</option>
                        </select>
                      </div>
                      <div class="col-sm-5">
                        <label class="form-label">Port count <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="device_ports" id="editDevPorts"
                               min="1" max="1000">
                      </div>
                    </div>
                  </div>
                  <div class="mb-1">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="device_notes" id="editDevNotes" rows="2"></textarea>
                  </div>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Edit Closure Modal -->
        <div class="modal fade <?= $openEdit ? 'show' : '' ?>" id="editClosureModal"
             tabindex="-1"
             aria-hidden="<?= $openEdit ? 'false' : 'true' ?>"
             style="<?= $openEdit ? 'display:block;background:rgba(0,0,0,.5);' : '' ?>">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Edit Closure <?= h($fosc['splice_num']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                  onclick="window.location='<?= h(closure_url_build(['fosc_id' => (int)$fosc['fosc_id']])) ?>'"></button>
              </div>
              <form method="post">
                <input type="hidden" name="action"  value="edit_fosc">
                <input type="hidden" name="csrf"    value="<?= h($CSRF) ?>">
                <input type="hidden" name="fosc_id" value="<?= (int)$fosc['fosc_id'] ?>">
                <div class="modal-body">
                  <?php if (!empty($editErrors)): ?>
                    <div class="alert alert-danger">
                      <ul class="mb-0">
                        <?php foreach ($editErrors as $e): ?>
                          <li><?= h($e) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">Splice Number *</label>
                      <input class="form-control" type="text" name="splice_num"
                             value="<?= h($fosc['splice_num']) ?>" maxlength="7" required>
                      <div class="form-text">ABC1234 or L/R + 2 digits (e.g. L01, R03)</div>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Status *</label>
                      <select class="form-select" name="status" required>
                        <?php foreach (FOSC_STATUSES as $opt): ?>
                          <option value="<?= h($opt) ?>" <?= $fosc['status'] === $opt ? 'selected' : '' ?>>
                            <?= h($opt) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Kind *</label>
                      <select class="form-select" name="kind" required>
                        <?php foreach (['closure','mst','terminal','cabinet','optitap'] as $k): ?>
                          <option value="<?= $k ?>" <?= $foscKind === $k ? 'selected' : '' ?>>
                            <?= ucfirst($k) ?><?= $k === 'cabinet' ? ' (FDC)' : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Closure Type *</label>
                      <select class="form-select" name="closure_type_id" required>
                        <?php foreach ($types as $t): ?>
                          <option value="<?= (int)$t['closure_type_id'] ?>"
                            <?= ((int)$t['closure_type_id'] === (int)$fosc['closure_type_id']) ? 'selected' : '' ?>>
                            <?= h($t['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Coordinates (lon,lat or POINT) *</label>
                      <input class="form-control" type="text" name="coord"
                             value="<?= h($prefillCoord) ?>" required>
                      <div class="form-text">Paste from QGIS: <code>lon,lat</code> or <code>POINT(lon lat)</code></div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Notes</label>
                      <textarea class="form-control" name="notes" rows="2"><?= h($fosc['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Street Address</label>
                      <input class="form-control" type="text" name="street_address"
                             value="<?= h($foscAttrs['street_address'] ?? '') ?>"
                             placeholder="e.g. 123 Main St, Moncton">
                      <div class="form-text">Nearest street address to this closure.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">POP</label>
                      <select class="form-select" name="pop_id">
                        <option value="0">— none —</option>
                        <?php foreach ($allPops as $p): ?>
                          <option value="<?= (int)$p['pop_id'] ?>"
                            <?= ((int)$p['pop_id'] === (int)($fosc['pop_id'] ?? 0)) ? 'selected' : '' ?>>
                            <?= h($p['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <div class="form-text">Group this closure into a Point of Presence.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Enclosure Rating *</label>
                      <select class="form-select" name="enclosure_rating" required>
                        <?php foreach (['inside','outside'] as $er): ?>
                          <option value="<?= $er ?>"
                            <?= (($fosc['enclosure_rating'] ?? 'outside') === $er) ? 'selected' : '' ?>>
                            <?= ucfirst($er) ?>-rated
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <div class="form-text">Inside-rated closures allow patch cables; outside-plant does not.</div>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"
                          onclick="window.location='<?= h(closure_url_build(['fosc_id' => (int)$fosc['fosc_id']])) ?>'">
                    Cancel
                  </button>
                  <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <script>
        // ── Remember active tab across POST redirects; honour ?tab= in URL ──────
        var _TAB_KEY = 'closure_tab_<?= (int)$fosc['fosc_id'] ?>';
        var _VALID_TABS = ['overview','splices','devices','patch','timeline'];
        document.addEventListener('DOMContentLoaded', function() {
          var urlTab  = new URLSearchParams(location.search).get('tab');
          var target  = (_VALID_TABS.indexOf(urlTab) !== -1) ? '#tab-' + urlTab
                      : sessionStorage.getItem(_TAB_KEY);
          if (target) {
            var btn = document.querySelector('#closureTabs [data-bs-target="' + target + '"]');
            if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
          }
          document.querySelectorAll('#closureTabs [data-bs-toggle="tab"]').forEach(function(btn) {
            btn.addEventListener('shown.bs.tab', function(e) {
              sessionStorage.setItem(_TAB_KEY, e.target.getAttribute('data-bs-target'));
            });
          });
        });

        <?php if ($openEdit): ?>
        document.addEventListener('DOMContentLoaded', function () {
          bootstrap.Modal.getOrCreateInstance(
            document.getElementById('editClosureModal'), {backdrop: 'static'}).show();
        });
        <?php endif; ?>

        // Toggle A/B side type in unified splice modal
        function _autoSpliceKind() {
          var aDevice = document.getElementById('aTypeDevice').checked;
          var bDevice = document.getElementById('bTypeDevice').checked;
          var sel = document.getElementById('nSpliceJoinKind');
          if (sel && aDevice && bDevice) sel.value = 'connector';
        }
        document.querySelectorAll('input[name="a_type"]').forEach(function(r) {
          r.addEventListener('change', function() {
            document.getElementById('aSideSpan').classList.toggle('d-none', this.value !== 'span');
            document.getElementById('aSideDevice').classList.toggle('d-none', this.value !== 'device');
            _autoSpliceKind();
          });
        });
        document.querySelectorAll('input[name="b_type"]').forEach(function(r) {
          r.addEventListener('change', function() {
            document.getElementById('bSideSpan').classList.toggle('d-none', this.value !== 'span');
            document.getElementById('bSideDevice').classList.toggle('d-none', this.value !== 'device');
            _autoSpliceKind();
          });
        });

        // Reset to defaults when modal closes
        document.getElementById('addSpliceModal')?.addEventListener('hidden.bs.modal', function() {
          document.getElementById('aTypeSpan').checked = true;
          document.getElementById('aSideSpan').classList.remove('d-none');
          document.getElementById('aSideDevice').classList.add('d-none');
          document.getElementById('bTypeSpan').checked = true;
          document.getElementById('bSideSpan').classList.remove('d-none');
          document.getElementById('bSideDevice').classList.add('d-none');
          document.getElementById('aPortHelp').textContent = 'Port 0 = IN, ports 1+ = outputs';
          document.getElementById('bPortHelp').textContent = 'Port 0 = IN, ports 1+ = outputs';
          // Clear the inputs as well: leftover values from the last splice reappearing
          // in the next one was the "it picked a default" bug.
          ['aEndpoint', 'bEndpoint', 'aDeviceSelect', 'bDeviceSelect']
            .forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
          var nNotes = document.querySelector('#addSpliceModal textarea[name="notes"]');
          if (nNotes) nNotes.value = '';
          window.FibrePicker?.clear('#addSpliceModal [data-fibre-picker]');
        });

        // "Add Patch Cable" shortcut — switch to Patch tab and scroll to device-to-device section
        document.getElementById('openPatchCableBtn')?.addEventListener('click', function () {
          var patchBtn = document.getElementById('tab-patch-btn');
          if (patchBtn) {
            bootstrap.Tab.getOrCreateInstance(patchBtn).show();
            setTimeout(function () {
              var section = document.getElementById('ppStatus');
              if (section) section.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 150);
          }
        });

        // Auto-fill port fields when an OLT-PON device is selected
        function onDeviceSelectChange(selectEl, portLoEl, portHiEl, helpEl) {
          var opt = selectEl.options[selectEl.selectedIndex];
          var devType = opt ? opt.getAttribute('data-device-type') : '';
          var outs = opt ? parseInt(opt.getAttribute('data-outs') || '1', 10) : 1;
          if (!outs || outs < 1) outs = 1;
          if ((devType === 'olt_pon' || devType === 'sfp') && outs > 1) {
            // Multi-port laser source (e.g. 8-port OLT, 14-port router): each port is a
            // source output; default to port 1 but let the user pick 1..N.
            if (!portLoEl.value || +portLoEl.value < 1 || +portLoEl.value > outs) portLoEl.value = 1;
            if (!portHiEl.value || +portHiEl.value < +portLoEl.value || +portHiEl.value > outs) portHiEl.value = portLoEl.value;
            helpEl.textContent = (devType === 'sfp' ? 'SFP' : 'OLT PON') + ' source port — choose 1 to ' + outs;
          } else if (devType === 'olt_pon' || devType === 'sfp') {
            portLoEl.value = 1;
            portHiEl.value = 1;
            helpEl.textContent = '\u26a1 ' + (devType === 'sfp' ? 'SFP' : 'OLT PON') + ' — port 1 (laser output) auto-set';
          } else if (devType === 'mpt') {
            var mptMode = opt ? opt.getAttribute('data-mpt-mode') : '';
            helpEl.textContent = mptMode === 'split'
              ? 'Port 0 = IN, ports 1–' + outs + ' = splitter outputs'
              : 'MPT port — choose 1 to ' + outs;
          } else {
            helpEl.textContent = 'Port 0 = IN, ports 1+ = outputs';
          }
          // The picker owns these inputs now — re-read whatever was just set.
          window.FibrePicker?.sync();
        }
        document.getElementById('aDeviceSelect')?.addEventListener('change', function() {
          onDeviceSelectChange(this,
            document.getElementById('aPortLo'),
            document.getElementById('aPortHi'),
            document.getElementById('aPortHelp'));
        });
        document.getElementById('bDeviceSelect')?.addEventListener('change', function() {
          onDeviceSelectChange(this,
            document.getElementById('bPortLo'),
            document.getElementById('bPortHi'),
            document.getElementById('bPortHelp'));
        });

        // Called from Devices tab — open unified modal with B side preset to this device
        function openDeviceSpliceModal(deviceId, deviceName) {
          document.getElementById('bTypeDevice').checked = true;
          document.getElementById('bTypeSpan').checked = false;
          document.getElementById('bSideSpan').classList.add('d-none');
          document.getElementById('bSideDevice').classList.remove('d-none');
          const sel = document.getElementById('bDeviceSelect');
          if (sel) {
            sel.value = deviceId;
            onDeviceSelectChange(sel,
              document.getElementById('bPortLo'),
              document.getElementById('bPortHi'),
              document.getElementById('bPortHelp'));
          }
          window.FibrePicker?.sync('#addSpliceModal [data-fibre-picker]');
          bootstrap.Modal.getOrCreateInstance(
            document.getElementById('addSpliceModal')).show();
        }

        // Fibre/port availability for every range picker on the page (see
        // js/fiber-range-picker.js). Replaces the per-modal "used fibers" lookup that
        // only the Patch Cable form used to have.
        window.FIBRE_PICKER_DATA = <?= json_encode(
            splice_picker_data($splicesHere, $spanEndpoints, $devicesAvailable),
            JSON_UNESCAPED_UNICODE
        ) ?>;

        // Edit Splice modal: radio toggles
        document.querySelectorAll('input[name="ea_type"]').forEach(function(r) {
          r.addEventListener('change', function() {
            document.getElementById('eASideSpan').classList.toggle('d-none', this.value !== 'span');
            document.getElementById('eASideDevice').classList.toggle('d-none', this.value !== 'device');
          });
        });
        document.querySelectorAll('input[name="eb_type"]').forEach(function(r) {
          r.addEventListener('change', function() {
            document.getElementById('eBSideSpan').classList.toggle('d-none', this.value !== 'span');
            document.getElementById('eBSideDevice').classList.toggle('d-none', this.value !== 'device');
          });
        });
        document.getElementById('eADeviceSelect')?.addEventListener('change', function() {
          onDeviceSelectChange(this,
            document.getElementById('eAPortLo'),
            document.getElementById('eAPortHi'),
            document.getElementById('eAPortHelp'));
        });
        document.getElementById('eBDeviceSelect')?.addEventListener('change', function() {
          onDeviceSelectChange(this,
            document.getElementById('eBPortLo'),
            document.getElementById('eBPortHi'),
            document.getElementById('eBPortHelp'));
        });

        // Pre-fill and open the Edit Splice modal
        /**
         * Duplicate a splice row: open the ADD modal pre-filled with the same
         * endpoints, join kind, layer and notes — but with NO fibres selected.
         *
         * The source splice's fibres are by definition already taken, so copying
         * them across would be rejected outright by the database's no-double-use
         * rule. Starting the picker empty puts the user straight on the grid, which
         * greys out what is used and lets them take the next free range.
         */
        function openDuplicateSpliceModal(data) {
          var modal = document.getElementById('addSpliceModal');
          if (!modal) return;

          // Set the A/B side type through the radio's own change handler rather than
          // repeating its show/hide logic here.
          function fillSide(side, target, objId, epKey, epLabel) {
            var isSpan = (target === 'cable_span');
            var radio  = document.getElementById(side + 'Type' + (isSpan ? 'Span' : 'Device'));
            if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change')); }

            if (isSpan) {
              var sel = document.getElementById(side + 'Endpoint');
              if (!sel) return;
              var key = epKey || (objId + '-' + (side === 'a' ? data.a_end : data.b_end));
              if (!sel.querySelector('option[value="' + key + '"]')) {
                var opt = document.createElement('option');
                opt.value = key;
                opt.textContent = epLabel || key;
                sel.appendChild(opt);
              }
              sel.value = key;
              sel.dispatchEvent(new Event('change'));   // makes the picker reload used fibres
            } else {
              var ds = document.getElementById(side + 'DeviceSelect');
              if (!ds) return;
              ds.value = objId;
              ds.dispatchEvent(new Event('change'));
            }
          }

          fillSide('a', data.a_target, data.a_span_id, data.a_ep_key, data.a_ep_label);
          fillSide('b', data.b_target, data.b_span_id, data.b_ep_key, data.b_ep_label);

          var kind = document.getElementById('nSpliceJoinKind');
          if (kind) kind.value = data.join_kind || 'single';
          var layer = document.getElementById(data.is_plan ? 'spliceLayerPlan' : 'spliceLayerActual');
          if (layer) layer.checked = true;
          var notes = modal.querySelector('textarea[name="notes"]');
          if (notes) notes.value = data.notes || '';

          window.FibrePicker?.clear('#addSpliceModal [data-fibre-picker]');
          bootstrap.Modal.getOrCreateInstance(modal).show();
        }

        function openEditSpliceModal(data) {
          document.getElementById('eSpliceJoinId').value = data.id;
          // This splice's own fibres must not read as taken while editing it.
          document.querySelectorAll('#editSpliceModal [data-fibre-picker]')
            .forEach(function (el) { el.setAttribute('data-exclude-id', data.id); });
          document.getElementById('editSpliceTitle').textContent = '#' + data.id;

          var aIsSpan = data.a_target === 'cable_span';
          document.getElementById('eATypeSpan').checked   =  aIsSpan;
          document.getElementById('eATypeDevice').checked = !aIsSpan;
          document.getElementById('eASideSpan').classList.toggle('d-none', !aIsSpan);
          document.getElementById('eASideDevice').classList.toggle('d-none', aIsSpan);
          if (aIsSpan) {
            var aEpSel = document.getElementById('eAEndpoint');
            var aEpKey = data.a_ep_key || (data.a_span_id + '-' + data.a_end);
            if (!aEpSel.querySelector('option[value="' + aEpKey + '"]')) {
              var aOpt = document.createElement('option');
              aOpt.value = aEpKey;
              aOpt.textContent = data.a_ep_label || aEpKey;
              aEpSel.appendChild(aOpt);
            }
            aEpSel.value = aEpKey;
            document.getElementById('eAFiberLo').value  = data.a_lo;
            document.getElementById('eAFiberHi').value  = data.a_hi;
          } else {
            var aSel = document.getElementById('eADeviceSelect');
            if (aSel) { aSel.value = data.a_span_id; onDeviceSelectChange(aSel, document.getElementById('eAPortLo'), document.getElementById('eAPortHi'), document.getElementById('eAPortHelp')); }
            document.getElementById('eAPortLo').value = data.a_lo;
            document.getElementById('eAPortHi').value = data.a_hi;
          }

          var bIsSpan = data.b_target === 'cable_span';
          document.getElementById('eBTypeSpan').checked   =  bIsSpan;
          document.getElementById('eBTypeDevice').checked = !bIsSpan;
          document.getElementById('eBSideSpan').classList.toggle('d-none', !bIsSpan);
          document.getElementById('eBSideDevice').classList.toggle('d-none', bIsSpan);
          if (bIsSpan) {
            var bEpSel = document.getElementById('eBEndpoint');
            var bEpKey = data.b_ep_key || (data.b_span_id + '-' + data.b_end);
            if (!bEpSel.querySelector('option[value="' + bEpKey + '"]')) {
              var bOpt = document.createElement('option');
              bOpt.value = bEpKey;
              bOpt.textContent = data.b_ep_label || bEpKey;
              bEpSel.appendChild(bOpt);
            }
            bEpSel.value = bEpKey;
            document.getElementById('eBFiberLo').value  = data.b_lo;
            document.getElementById('eBFiberHi').value  = data.b_hi;
          } else {
            var bSel = document.getElementById('eBDeviceSelect');
            if (bSel) { bSel.value = data.b_span_id; onDeviceSelectChange(bSel, document.getElementById('eBPortLo'), document.getElementById('eBPortHi'), document.getElementById('eBPortHelp')); }
            document.getElementById('eBPortLo').value = data.b_lo;
            document.getElementById('eBPortHi').value = data.b_hi;
          }

          document.getElementById('eSpliceJoinKind').value = data.join_kind || 'single';
          document.getElementById('eSpliceNotes').value = data.notes || '';
          document.getElementById('eLayerActual').checked = !data.is_plan;
          document.getElementById('eLayerPlan').checked   =  data.is_plan;
          // Hidden lo/hi were set directly above; paint the grid to match.
          window.FibrePicker?.sync('#editSpliceModal [data-fibre-picker]');
          bootstrap.Modal.getOrCreateInstance(document.getElementById('editSpliceModal')).show();
        }

        // Add Device modal: show/hide outputs / patch-panel rows based on type
        (function() {
          var dt        = document.getElementById('devType');
          var outsRow   = document.getElementById('devOutsRow');
          var patchRow  = document.getElementById('devPatchRow');
          var mptRow    = document.getElementById('devMptRow');
          var mptMode   = document.getElementById('devMptMode');
          var mptPorts  = document.getElementById('devMptPorts');
          var mptHelp   = document.getElementById('devMptHelp');
          var help      = document.getElementById('devOutsHelp');
          if (!dt) return;
          var outsInput = document.getElementById('devOuts');
          var outsLabel = document.getElementById('devOutsLabel');
          function updateMptFields() {
            var split = mptMode && mptMode.value === 'split';
            if (mptPorts) mptPorts.min = split ? 2 : 1;
            if (mptHelp)  mptHelp.textContent = split
              ? 'Splitter outputs (2–128); e.g. 4 for a 1×4 split MPT'
              : 'Number of pass-through ports (1–128); e.g. 4';
          }
          function updateDevFields() {
            var v = dt.value;
            var isLaser = (v === 'olt_pon' || v === 'sfp');
            var isOuts  = (v === 'splitter' || v === 'dwdm' || isLaser);
            if (outsRow)  outsRow.style.display  = isOuts ? '' : 'none';
            if (patchRow) patchRow.style.display = (v === 'patch_panel') ? '' : 'none';
            if (mptRow)   mptRow.style.display   = (v === 'mpt') ? '' : 'none';
            if (outsInput) outsInput.min = isLaser ? 1 : 2;
            if (outsLabel) outsLabel.textContent = isLaser ? 'Source ports' : 'Output ports';
            if (help) {
              help.textContent = isLaser
                ? 'Number of source ports (1–128); 1 = single transceiver, e.g. 8 for an 8-port OLT, 14 for a router'
                : (v === 'dwdm'
                  ? 'Number of wavelength channels / output ports (2–128); e.g. 4 for a 1×4 MUX/DEMUX'
                  : 'Number of output ports (2–128); e.g. 4 for a 1×4 PLC splitter');
            }
            updateMptFields();
          }
          dt.addEventListener('change', updateDevFields);
          if (mptMode) mptMode.addEventListener('change', updateMptFields);
          updateDevFields();
        })();

        // Edit Device modal: populate fields and show
        function openEditDeviceModal(id, name, type, attrsRaw, notes) {
          var attrs = {};
          try { attrs = typeof attrsRaw === 'string' ? JSON.parse(attrsRaw) : (attrsRaw || {}); } catch(e) {}
          document.getElementById('editDevId').value    = id;
          document.getElementById('editDevName').value  = name;
          document.getElementById('editDevNotes').value = notes || '';
          var dt       = document.getElementById('editDevType');
          var outsRow  = document.getElementById('editDevOutsRow');
          var patchRow = document.getElementById('editDevPatchRow');
          var mptRow   = document.getElementById('editDevMptRow');
          var mptMode  = document.getElementById('editDevMptMode');
          var mptPorts = document.getElementById('editDevMptPorts');
          var mptHelp  = document.getElementById('editDevMptHelp');
          var help     = document.getElementById('editDevOutsHelp');
          dt.value = type;
          if (attrs.outs)      document.getElementById('editDevOuts').value      = attrs.outs;
          if (attrs.ports)     document.getElementById('editDevPorts').value     = attrs.ports;
          if (attrs.connector) document.getElementById('editDevConnector').value = attrs.connector;
          if (mptMode  && attrs.mode)  mptMode.value  = attrs.mode;
          if (mptPorts && type === 'mpt' && attrs.ports) mptPorts.value = attrs.ports;
          var outsInput = document.getElementById('editDevOuts');
          var outsLabel = document.getElementById('editDevOutsLabel');
          function updateEditMptFields() {
            var split = mptMode && mptMode.value === 'split';
            if (mptPorts) mptPorts.min = split ? 2 : 1;
            if (mptHelp)  mptHelp.textContent = split
              ? 'Splitter outputs (2–128)'
              : 'Number of pass-through ports (1–128)';
          }
          function updateEditDevFields() {
            var v = dt.value;
            var isLaser = (v === 'olt_pon' || v === 'sfp');
            var isOuts  = (v === 'splitter' || v === 'dwdm' || isLaser);
            if (outsRow)  outsRow.style.display  = isOuts ? '' : 'none';
            if (patchRow) patchRow.style.display = (v === 'patch_panel') ? '' : 'none';
            if (mptRow)   mptRow.style.display   = (v === 'mpt') ? '' : 'none';
            if (outsInput) outsInput.min = isLaser ? 1 : 2;
            if (outsLabel) outsLabel.textContent = isLaser ? 'Source ports' : 'Output ports';
            if (help) help.textContent = isLaser
              ? 'Number of source ports (1–128); 1 = single transceiver, e.g. 8 for an OLT, 14 for a router'
              : (v === 'dwdm'
                ? 'Number of wavelength channels / output ports (2–128)'
                : 'Number of output ports (2–128)');
            updateEditMptFields();
          }
          dt.removeEventListener('change', updateEditDevFields);
          dt.addEventListener('change', updateEditDevFields);
          if (mptMode) {
            mptMode.removeEventListener('change', updateEditMptFields);
            mptMode.addEventListener('change', updateEditMptFields);
          }
          updateEditDevFields();
          bootstrap.Modal.getOrCreateInstance(document.getElementById('editDeviceModal')).show();
        }
        </script>

    <?php else: // LIST VIEW ?>

        <?php if ($flash): ?>
          <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>">
            <?= h($flash['msg']) ?>
          </div>
        <?php endif; ?>

        <!-- Page header + filter row -->
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
          <h3 class="mb-0 me-2">Closures</h3>

          <!-- Inline filter form -->
          <form class="d-flex align-items-center gap-2 flex-wrap flex-grow-1" method="get">
            <input type="hidden" name="page" value="closure">
            <input class="form-control form-control-sm" style="max-width:200px"
                   type="text" name="q" id="closureSearch" value="<?= h($q) ?>" placeholder="Search splice # or notes"
                   autofocus autocomplete="off">
            <select class="form-select form-select-sm" name="status" style="max-width:130px">
              <option value="">Any status</option>
              <?php foreach (FOSC_STATUSES as $s): ?>
                <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="type_id" style="max-width:170px">
              <option value="0">Any type</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['closure_type_id'] ?>"
                  <?= $typeId === (int)$t['closure_type_id'] ? 'selected' : '' ?>>
                  <?= h($t['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button>
            <?php if ($q !== '' || $status !== '' || $typeId !== 0): ?>
              <a class="btn btn-link btn-sm text-muted p-0" href="index.php?page=closure">Clear</a>
            <?php endif; ?>
          </form>

          <button class="btn btn-success btn-sm ms-auto"
                  data-bs-toggle="modal" data-bs-target="#addClosureModal">
            + Add Closure
          </button>
        </div>

        <!-- Add New Closure modal -->
        <div class="modal fade" id="addClosureModal" tabindex="-1"
             aria-labelledby="addClosureModalLabel" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="addClosureModalLabel">Add New Closure</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <form method="post">
                <div class="modal-body">
                  <input type="hidden" name="action" value="add_fosc">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <?php if (!empty($formErrors)): ?>
                    <div class="alert alert-danger">
                      <ul class="mb-0">
                        <?php foreach ($formErrors as $e): ?>
                          <li><?= h($e) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                  <div class="mb-2">
                    <label class="form-label small fw-semibold">Splice Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm text-uppercase"
                           name="splice_num" maxlength="7" placeholder="ABC1234" required
                           value="<?= h($_POST['splice_num'] ?? '') ?>">
                    <div class="form-text">ABC1234 or L/R + 2 digits (e.g. L01)</div>
                  </div>
                  <div class="mb-2">
                    <label class="form-label small fw-semibold">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select form-select-sm" required>
                      <?php foreach (FOSC_STATUSES as $s): ?>
                        <option value="<?= h($s) ?>"
                          <?= ($s === 'plan' && empty($_POST['status'])) || (isset($_POST['status']) && $_POST['status'] === $s) ? 'selected' : '' ?>>
                          <?= h($s) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="mb-2">
                    <label class="form-label small fw-semibold">Closure Type <span class="text-danger">*</span></label>
                    <select name="closure_type_id" class="form-select form-select-sm" required>
                      <option value="">Select type...</option>
                      <?php foreach ($types as $t): ?>
                        <option value="<?= (int)$t['closure_type_id'] ?>"
                          <?= isset($_POST['closure_type_id']) && (int)$_POST['closure_type_id'] === (int)$t['closure_type_id'] ? 'selected' : '' ?>>
                          <?= h($t['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="mb-2">
                    <label class="form-label small fw-semibold">Coordinates (lon,lat or POINT) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="coord"
                           placeholder="-64.7803, 46.0899" required
                           value="<?= h($_POST['coord'] ?? '') ?>">
                    <div class="form-text">Copy from QGIS attribute (lon,lat) or WKT POINT(lon lat).</div>
                  </div>
                  <div class="mb-1">
                    <label class="form-label small fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control form-control-sm" rows="2"
                              placeholder="Optional notes..."><?= h($_POST['notes'] ?? '') ?></textarea>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-success btn-sm">Add Closure</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php if (!empty($formErrors)): ?>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              bootstrap.Modal.getOrCreateInstance(document.getElementById('addClosureModal')).show();
            });
          </script>
        <?php endif; ?>

        <p class="text-secondary small mb-2">
          <?= $total ?> closure<?= $total === 1 ? '' : 's' ?>
          <?php if ($totalPages > 1): ?> &mdash; page <?= $pageNum ?>/<?= $totalPages ?><?php endif; ?>
        </p>

        <?php if (!$list): ?>
          <div class="alert alert-warning">No closures found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Splice #</th>
                  <th>Status</th>
                  <th>Type</th>
                  <th>Lat</th>
                  <th>Lon</th>
                  <th>Updated</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="closureListBody">
                <?php foreach ($list as $row): ?>
                  <tr>
                    <td><code><?= h($row['splice_num']) ?></code></td>
                    <td>
                      <?php $badge = status_badge_class($row['status']); ?>
                      <span class="badge bg-<?= $badge ?>"><?= h($row['status']) ?></span>
                    </td>
                    <td><?= h($row['closure_type']) ?></td>
                    <td><?= h($row['lat']) ?></td>
                    <td><?= h($row['lon']) ?></td>
                    <td class="text-nowrap"><?= h($row['updated_at']) ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-secondary"
                         href="<?= h(closure_url_build($baseParams + ['fosc_id' => (int)$row['fosc_id']])) ?>">
                        Open
                      </a>
                      <a class="btn btn-sm btn-outline-primary"
                         href="<?= h(closure_url_build($baseParams + ['fosc_id' => (int)$row['fosc_id'], 'edit' => 1])) ?>">
                        Edit
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav>
              <ul class="pagination justify-content-center">
                <li class="page-item <?= $pageNum <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link"
                     href="<?= h(closure_url_build($baseParams + ['page_num' => $pageNum - 1])) ?>">
                    Previous
                  </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                  <li class="page-item <?= $i == $pageNum ? 'active' : '' ?>">
                    <a class="page-link"
                       href="<?= h(closure_url_build($baseParams + ['page_num' => $i])) ?>">
                      <?= $i ?>
                    </a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= $pageNum >= $totalPages ? 'disabled' : '' ?>">
                  <a class="page-link"
                     href="<?= h(closure_url_build($baseParams + ['page_num' => $pageNum + 1])) ?>">
                    Next
                  </a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        <?php endif; ?>

    <?php endif; // end LIST ?>

    <script>
    (function () {
      var inp  = document.getElementById('closureSearch');
      var body = document.getElementById('closureListBody');
      if (!inp || !body) return;
      inp.addEventListener('input', function () {
        var q = inp.value.trim().toLowerCase();
        Array.from(body.rows).forEach(function (tr) {
          tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
      });
    })();
    </script>

    <?php
    return ob_get_clean();
});