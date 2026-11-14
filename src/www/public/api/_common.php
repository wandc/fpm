<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
declare(strict_types=1);
// api/_common.php
// Shared bootstrap for every file in api/.  Include this at the top of each
// resource file.  Not intended to be requested directly.

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === '_common.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../app/config.php';        // $pdo
require_once __DIR__ . '/../phpHelpers/app-helper.php'; // session, $CSRF, h()
require_once __DIR__ . '/../../app/user_identity.php';  // fpm_current_identity()

// Always respond with JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── never leak HTML into a JSON response ─────────────────────────────────────
// Without these, an uncaught PDOException (a CHECK violation, a bad column, a
// dropped connection) reaches PHP's default handler and prints an HTML error
// page. The caller then fails on JSON.parse with "Unexpected token '<'", which
// tells the user nothing about what actually went wrong.
//
// display_errors is disabled for the response body and everything is funnelled
// through a JSON envelope instead; the detail still goes to the error log.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Turn a database exception into a message worth reading, plus an HTTP status.
 *
 * Postgres SQLSTATEs carry enough to explain the common cases without dumping
 * the raw driver text (which includes the SQL and is meaningless to the user).
 *
 * @return array{0:string,1:int} [message, http status]
 */
function api_db_error_message(Throwable $e): array
{
    $sqlState = null;
    if ($e instanceof PDOException) {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();
    }
    // Postgres puts the constraint name in the driver message; surface it,
    // since constraint names in this schema are descriptive (chk_*_format).
    $detail = '';
    if (preg_match('/violates check constraint "([^"]+)"/i', $e->getMessage(), $m)) {
        $detail = ' (' . $m[1] . ')';
    } elseif (preg_match('/violates foreign key constraint "([^"]+)"/i', $e->getMessage(), $m)) {
        $detail = ' (' . $m[1] . ')';
    }

    // Integrity violations (23xxx) are caused by what the caller sent, so they
    // are 400s, not 500s — a 500 would wrongly imply the server is broken.
    // P0001 is PL/pgSQL's RAISE EXCEPTION. Those come from this schema's own domain
    // triggers, and their text is written for the operator ("Splice conflict: cable_span
    // 257 to port/fibre 6 is already used by splice #344"), so pass it through rather
    // than flattening it to a generic string. Nothing but our own RAISE produces P0001,
    // so this cannot leak Postgres internals.
    if ((string)$sqlState === 'P0001'
        && preg_match('/ERROR:\s*(.+?)(?:\r?\n|$)/', $e->getMessage(), $m)) {
        return [trim($m[1]), 409];
    }

    return match ((string)$sqlState) {
        '23514' => ['Value rejected by a database rule' . $detail,  400],
        '23505' => ['That record already exists',                   409],
        '23503' => ['Still referenced by other records' . $detail,  409],
        '23502' => ['A required field was missing',                 400],
        '22001' => ['A value was too long for its field',           400],
        '22P02' => ['A value was the wrong type',                   400],
        default => ['Server error',                                 500],
    };
}

set_exception_handler(function (Throwable $e): void {
    [$message, $status] = api_db_error_message($e);
    // Always log the real detail — the caller only ever sees the summary.
    error_log('[api] uncaught ' . get_class($e) . ': ' . $e->getMessage()
              . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $message]);
});

// Fatals bypass the exception handler, so catch them on the way out too.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'Server error']);
});

// ── auth ─────────────────────────────────────────────────────────────────────

$_apiIdentity = fpm_current_identity();
if (!($_apiIdentity['is_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// ── CSRF (mutations only) ─────────────────────────────────────────────────────

/**
 * Validate the X-CSRF-Token request header against the session token.
 * Call this at the start of every POST / PUT / DELETE handler.
 */
function api_require_csrf(): void
{
    global $CSRF;
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string)$CSRF, $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
        exit;
    }
}

// ── response helpers ──────────────────────────────────────────────────────────

function api_ok(array $data = []): never
{
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function api_err(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/**
 * Decode the JSON request body.  Returns the decoded array or calls api_err().
 */
function api_json_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        api_err('Empty request body');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_err('Invalid JSON body');
    }
    return $data;
}

/**
 * HTTP method of the current request (uppercase).
 */
function api_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/**
 * Delete the timeline (notes and photos) of an asset that is being deleted. timeline_entry has no foreign
 * key to conduits, vaults or POPs (closures cascade through fosc_id), so without this the entries and their
 * photos would be left behind with nothing pointing at them. Call inside the delete's transaction.
 */
function api_delete_timeline(PDO $pdo, string $parentType, int $parentId): void
{
    $pdo->prepare('DELETE FROM app.timeline_entry WHERE parent_type = :pt AND parent_id = :pid')
        ->execute([':pt' => $parentType, ':pid' => (string)$parentId]);
}
