<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// app/change_tracking.php
//
// Tells the database who is writing, so every row change lands in a change_set
// (see db/migrations/0266__change_tracking.sql). Nothing is written here: the
// session settings are read by app.current_change() on the first write.

if (!function_exists('fpm_bind_actor')) {
    /**
     * @param array $identity result of fpm_current_identity(), or [] before login is known
     */
    function fpm_bind_actor(PDO $pdo, array $identity = []): void
    {
        $cli    = PHP_SAPI === 'cli';
        $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['argv'][0] ?? 'unknown'));
        $path   = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        if ($cli) {
            $env = strtoupper(trim((string)getenv('FPM_ACTOR')));
            if (preg_match('/^[A-Z]{4}$/', $env)) {
                $actor = $env;
            } elseif (preg_match('/^(poller_|collector_|aggregator_|sync_)/', $script)) {
                $actor = 'CRON';
            } elseif (stripos($script, 'alden') !== false || str_starts_with($script, 'link_')) {
                $actor = 'ALDN';
            } else {
                $actor = 'SYST';
            }
            $source = $actor === 'CRON' ? 'cron' : 'script';
            $note   = $script;
            $uid    = 0;
        } else {
            $actor  = 'WEBX';
            $source = str_contains($path, '/api/') ? 'api' : 'web';
            $note   = $source . ': ' . $path;
            $uid    = (int)($identity['app_user_id'] ?? 0);
            $name   = (string)($identity['username'] ?? '');
            if ($name !== '') {
                $note .= ' (' . $name . ')';
            }
        }

        // One round trip. The actor comes from app.actor when the login is a known user.
        $st = $pdo->prepare(
            "SELECT set_config('app.actor', COALESCE((SELECT actor_code FROM app.actor WHERE app_user_id = :u AND active), :a), false),
                    set_config('app.source', :s, false),
                    set_config('app.note', :n, false),
                    set_config('app.user_id', CASE WHEN :u2 > 0 THEN :u3::text ELSE '' END, false),
                    set_config('app.change_id', '', false)"
        );
        $st->execute([':u' => $uid, ':a' => $actor, ':s' => $source, ':n' => substr($note, 0, 280), ':u2' => $uid, ':u3' => $uid]);
    }
}
