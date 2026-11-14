<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/user_settings.php
/** @var \FPM\App\Template $PAGE */

$PAGE->setTitle('User Settings');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$identity = function_exists('fpm_current_identity')
    ? fpm_current_identity()
    : [
        'username' => '',
        'display_name' => '',
        'email' => '',
        'provider' => '',
        'provider_subject' => '',
        'app_user_id' => 0,
        'oauth_state' => '',
        'source' => [],
        'is_authenticated' => false,
    ];

$provider = trim((string)($identity['provider'] ?? ''));
$providerSubject = trim((string)($identity['provider_subject'] ?? ''));
$username = trim((string)($identity['username'] ?? ''));
$displayName = trim((string)($identity['display_name'] ?? ''));
$email = trim((string)($identity['email'] ?? ''));
$appUserId = (int)($identity['app_user_id'] ?? 0);
$oauthState = trim((string)($identity['oauth_state'] ?? ''));
$sourceList = (array)($identity['source'] ?? []);

$hasAuthProfile = (bool)($identity['is_authenticated'] ?? false);

$PAGE->setContentRenderer(function () use (
    $provider,
    $username,
    $displayName,
    $email,
    $appUserId,
    $oauthState,
    $providerSubject,
    $sourceList,
    $hasAuthProfile
) {
    ob_start();
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h3 class="mb-0">User Settings</h3>
      <a class="btn btn-outline-secondary btn-sm" href="/index.php?page=home">Back to Home</a>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card shadow-sm user-settings-card">
          <div class="card-header bg-light">
            <strong>Account</strong>
          </div>
          <div class="card-body">
            <?php if (!$hasAuthProfile): ?>
              <div class="alert alert-info mb-0">
                No OAuth account is connected yet in this environment.
              </div>
            <?php else: ?>
              <dl class="row mb-0">
                <dt class="col-sm-4">Username</dt>
                <dd class="col-sm-8"><?= h($username !== '' ? $username : '—') ?></dd>

                <dt class="col-sm-4">Display name</dt>
                <dd class="col-sm-8"><?= h($displayName !== '' ? $displayName : '—') ?></dd>

                <dt class="col-sm-4">Email</dt>
                <dd class="col-sm-8"><?= h($email !== '' ? $email : '—') ?></dd>

                <dt class="col-sm-4">Provider</dt>
                <dd class="col-sm-8"><?= h($provider !== '' ? $provider : '—') ?></dd>

                <dt class="col-sm-4">Provider subject</dt>
                <dd class="col-sm-8"><?= h($providerSubject !== '' ? $providerSubject : '—') ?></dd>

                <dt class="col-sm-4">App user ID</dt>
                <dd class="col-sm-8"><?= h($appUserId > 0 ? (string)$appUserId : '—') ?></dd>
              </dl>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card shadow-sm user-settings-card">
          <div class="card-header bg-light">
            <strong>OAuth Session</strong>
          </div>
          <div class="card-body">
            <p class="mb-2 small text-muted">
              These values are session-scoped and may be empty until full OAuth flow is enabled.
            </p>
            <dl class="row mb-0">
              <dt class="col-sm-5">OAuth state</dt>
              <dd class="col-sm-7"><?= h($oauthState !== '' ? '[present]' : '—') ?></dd>

              <dt class="col-sm-5">Identity source</dt>
              <dd class="col-sm-7"><?= h($sourceList ? implode(', ', $sourceList) : 'none detected') ?></dd>
            </dl>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card shadow-sm user-settings-card">
          <div class="card-header bg-light">
            <strong>Preferences (coming soon)</strong>
          </div>
          <div class="card-body">
            <ul class="mb-0">
              <li>Theme (light / dark / system)</li>
              <li>Time zone and date format</li>
              <li>Notification preferences</li>
              <li>Default map/layer preferences</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
