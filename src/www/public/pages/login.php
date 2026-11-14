<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/login.php
/** @var \FPM\App\Template $PAGE */

$PAGE->setTitle('Sign in');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$identity = function_exists('fpm_current_identity') ? fpm_current_identity() : ['is_authenticated' => false];

$returnTo = (string)($_GET['return_to'] ?? '/index.php?page=home');
if ($returnTo === '' || $returnTo[0] !== '/') {
    $returnTo = '/index.php?page=home';
}

if ((bool)($identity['is_authenticated'] ?? false)) {
    header('Location: ' . $returnTo, true, 302);
    exit;
}

// Demo login: a plain username and password from the environment (DEMO_LOGIN_USER / DEMO_LOGIN_PASS), for trying the
// software out. Off unless DEMO_LOGIN_ENABLED=true. Never leave it on for a real installation.
$demoEnabled = strtolower(trim((string)getenv('DEMO_LOGIN_ENABLED'))) === 'true'
    && trim((string)getenv('DEMO_LOGIN_USER')) !== '' && (string)getenv('DEMO_LOGIN_PASS') !== '';
$demoError = '';
if ($demoEnabled && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['demo_user'])) {
    $okUser = hash_equals((string)getenv('DEMO_LOGIN_USER'), (string)$_POST['demo_user']);
    $okPass = hash_equals((string)getenv('DEMO_LOGIN_PASS'), (string)($_POST['demo_pass'] ?? ''));
    if ($okUser && $okPass) {
        $u = trim((string)getenv('DEMO_LOGIN_USER'));
        $_SESSION['auth_user'] = [
            'username' => $u, 'display_name' => $u, 'email' => $u . '@demo.invalid', 'provider' => 'demo',
            'provider_subject' => 'demo-' . $u, 'app_user_id' => 0, 'role' => 'admin',
        ];
        $_SESSION['user'] = $_SESSION['auth_user'];
        session_regenerate_id(true);
        header('Location: ' . $returnTo, true, 302);
        exit;
    }
    sleep(1);
    $demoError = 'Wrong user name or password.';
}

// Check if Google OAuth is configured
$googleClientId = trim((string)getenv('GOOGLE_OAUTH_CLIENT_ID'));
$oauthConfigured = ($googleClientId !== '');

// Dev login is secret-token-gated — never show it on the login page
$devLoginAvailable = false;

// Build OAuth start URL (internal page that redirects to Google)
$oauthUrl = '/index.php?page=oauth2_start&return_to=' . rawurlencode($returnTo);

// Build dev login URL
$devLoginUrl = '/index.php?page=login&dev_login=true&return_to=' . rawurlencode($returnTo);

$PAGE->setContentRenderer(function () use ($oauthConfigured, $oauthUrl, $returnTo, $devLoginAvailable, $devLoginUrl, $demoEnabled, $demoError) {
    ob_start();
    ?>
    <div class="row justify-content-center">
      <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
          <div class="card-header bg-light"><strong>Sign in required</strong></div>
          <div class="card-body">
            <?php if (!$oauthConfigured && !$devLoginAvailable && !$demoEnabled): ?>
              <div class="alert alert-warning" role="alert">
                No login methods are configured on this app yet.
              </div>
            <?php endif; ?>
            <?php if ($demoEnabled): ?>
              <form method="post" class="mb-3">
                <?php if ($demoError !== ''): ?><div class="alert alert-danger py-2"><?= h($demoError) ?></div><?php endif; ?>
                <div class="alert alert-warning py-2 small">Demo login is on. Anyone who can reach this page can sign in with the
                  demo user name and password. Turn it off (DEMO_LOGIN_ENABLED=false) for a real installation.</div>
                <div class="mb-2"><label class="form-label small mb-0" for="demo_user">User name</label>
                  <input class="form-control" id="demo_user" name="demo_user" autocomplete="username" autofocus></div>
                <div class="mb-2"><label class="form-label small mb-0" for="demo_pass">Password</label>
                  <input class="form-control" id="demo_pass" name="demo_pass" type="password" autocomplete="current-password"></div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
              </form>
            <?php endif; ?>
            <div class="d-grid gap-2">
              <?php if ($oauthConfigured): ?>
                <a class="btn btn-white border d-flex align-items-center justify-content-center gap-2 py-2"
                   href="<?= h($oauthUrl) ?>" style="background:#fff">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="20" height="20">
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.16C6.51 42.62 14.62 48 24 48z"/>
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.16C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.55 10.75l7.98-6.16z"/>
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.55 13.25l7.98 6.16C12.43 13.72 17.74 9.5 24 9.5z"/>
                  </svg>
                  Sign in with Google
                </a>
              <?php endif; ?>
              <?php if ($devLoginAvailable): ?>
                <a class="btn btn-info" href="<?= h($devLoginUrl) ?>">Dev Login (<?= h($devLoginUser) ?>)</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
