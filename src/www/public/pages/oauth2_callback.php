<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/oauth2_callback.php
/** @var \FPM\App\Template $PAGE */

$PAGE->setTitle('OAuth2 Callback');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// This page handles the OAuth2 callback from Google
// Google redirects here with an authorization code

// Get the authorization code and state from Google
$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$error = (string)($_GET['error'] ?? '');

// Get the return URL from session
$returnTo = (string)($_SESSION['oauth2_return_to'] ?? '/index.php?page=home');
$sessionState = (string)($_SESSION['oauth2_state'] ?? '');

// Validate return URL
if ($returnTo === '' || $returnTo[0] !== '/') {
    $returnTo = '/index.php?page=home';
}

// Check for OAuth errors
if ($error !== '') {
    $PAGE->setContentRenderer(function () use ($error, $returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-danger text-white"><strong>OAuth2 Error</strong></div>
              <div class="card-body">
                <h5 class="card-title">Authentication Failed</h5>
                <p class="text-muted mb-3">Google OAuth returned an error:</p>
                <div class="alert alert-danger" role="alert">
                  <strong>Error:</strong> <?= h($error) ?>
                </div>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                    Try Again
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

// Validate state parameter (CSRF protection)
if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
    $PAGE->setContentRenderer(function () use ($returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-warning text-dark"><strong>Security Error</strong></div>
              <div class="card-body">
                <h5 class="card-title">Invalid State Parameter</h5>
                <p class="text-muted mb-3">The OAuth state parameter is invalid or missing. This could indicate a CSRF attack.</p>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                    Return to Login
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

// Exchange authorization code for access token
if ($code === '') {
    $PAGE->setContentRenderer(function () use ($returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-warning text-dark"><strong>Missing Authorization Code</strong></div>
              <div class="card-body">
                <h5 class="card-title">No Authorization Code</h5>
                <p class="text-muted mb-3">Google did not provide an authorization code.</p>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                    Try Again
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

// Get Google OAuth credentials
$clientId = trim((string)getenv('GOOGLE_OAUTH_CLIENT_ID'));
$clientSecret = trim((string)getenv('GOOGLE_OAUTH_CLIENT_SECRET'));
$redirectUri = trim((string)getenv('APP_OAUTH_CALLBACK_URL'));

if ($redirectUri === '') {
    $redirectUri = 'https://' . ($_SERVER['HTTP_HOST'] ?? org('app_host')) . '/index.php?page=oauth2_callback';
}

// Exchange code for token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenParams = [
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenParams));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$tokenResponse = curl_exec($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tokenHttpCode !== 200 || $tokenResponse === false) {
    $PAGE->setContentRenderer(function () use ($tokenHttpCode, $tokenResponse, $returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-danger text-white"><strong>Token Exchange Failed</strong></div>
              <div class="card-body">
                <h5 class="card-title">Failed to Exchange Authorization Code</h5>
                <p class="text-muted mb-3">Could not exchange the authorization code for an access token.</p>
                <div class="alert alert-danger" role="alert">
                  <strong>HTTP Status:</strong> <?= h((string)$tokenHttpCode) ?><br>
                  <strong>Response:</strong> <pre><?= h(substr((string)$tokenResponse, 0, 500)) ?></pre>
                </div>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                    Try Again
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = (string)($tokenData['access_token'] ?? '');
$idToken = (string)($tokenData['id_token'] ?? '');

if ($accessToken === '') {
    $PAGE->setContentRenderer(function () use ($tokenResponse, $returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-danger text-white"><strong>No Access Token</strong></div>
              <div class="card-body">
                <h5 class="card-title">Missing Access Token</h5>
                <p class="text-muted mb-3">The token response did not include an access token.</p>
                <div class="alert alert-danger" role="alert">
                  <pre><?= h(substr((string)$tokenResponse, 0, 500)) ?></pre>
                </div>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                    Try Again
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

// Get user info from Google
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Accept: application/json',
]);
$userInfoResponse = curl_exec($ch);
$userInfoHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($userInfoHttpCode !== 200 || $userInfoResponse === false) {
    $PAGE->setContentRenderer(function () use ($userInfoHttpCode, $userInfoResponse, $returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-danger text-white"><strong>User Info Failed</strong></div>
              <div class="card-body">
                <h5 class="card-title">Failed to Retrieve User Information</h5>
                <p class="text-muted mb-3">Could not retrieve user information from Google.</p>
                <div class="alert alert-danger" role="alert">
                  <strong>HTTP Status:</strong> <?= h((string)$userInfoHttpCode) ?><br>
                  <strong>Response:</strong> <pre><?= h(substr((string)$userInfoResponse, 0, 500)) ?></pre>
                </div>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                    Try Again
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

$userInfo = json_decode($userInfoResponse, true);

// Extract user information
$email = (string)($userInfo['email'] ?? '');
$name = (string)($userInfo['name'] ?? '');
$givenName = (string)($userInfo['given_name'] ?? '');
$familyName = (string)($userInfo['family_name'] ?? '');
$picture = (string)($userInfo['picture'] ?? '');
$sub = (string)($userInfo['id'] ?? '');

// Check if new account creation is blocked
$blockNewAccounts = strtolower(trim((string)getenv('BLOCK_NEW_ACCOUNTS'))) === 'true';
$allowedEmails = array_map('trim', explode(',', (string)getenv('ALLOWED_EMAILS')));

if ($blockNewAccounts && !in_array($email, $allowedEmails, true)) {
    $PAGE->setContentRenderer(function () use ($email, $returnTo) {
        ob_start();
        ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-header bg-warning text-dark"><strong>Access Restricted</strong></div>
              <div class="card-body">
                <h5 class="card-title">Account Not Authorized</h5>
                <p class="text-muted mb-3">
                  New account creation is currently restricted. The email address 
                  <strong><?= h($email) ?></strong> is not authorized to access this application.
                </p>
                <p class="text-muted mb-3">
                  Please contact the administrator if you believe you should have access.
                </p>
                <div class="d-grid gap-2 mt-4">
                  <a class="btn btn-primary" href="/index.php?page=login">
                    Return to Login
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    });
    return;
}

// Upsert into app_user — preserve existing display_name if already set
$stUser = $pdo->prepare("
    INSERT INTO app.app_user (email, username, display_name, provider, last_login_at)
    VALUES (:email, :username, :display_name, 'google', now())
    ON CONFLICT (email) DO UPDATE SET
        last_login_at = now(),
        provider      = 'google',
        display_name  = CASE WHEN app.app_user.display_name <> '' THEN app.app_user.display_name
                             ELSE EXCLUDED.display_name END
    RETURNING app_user_id, display_name, role
");
// username must satisfy app_user_username_chk: ^[a-z0-9_][a-z0-9_\.-]{1,63}$
$username = strtolower(preg_replace('/[^a-z0-9_.-]/', '_', preg_replace('/@.*/', '', $email)));
$stUser->execute([
    ':email'        => $email,
    ':username'     => $username,
    ':display_name' => $name !== '' ? $name : $email,
]);
$dbUser = $stUser->fetch(PDO::FETCH_ASSOC);
$appUserId   = $dbUser ? (int)$dbUser['app_user_id'] : 0;
$displayName = ($dbUser && $dbUser['display_name'] !== '') ? $dbUser['display_name'] : ($name ?: $email);
$role        = $dbUser ? $dbUser['role'] : 'user';

// Store user information in session
$_SESSION['auth_user'] = [
    'username'     => $email,
    'display_name' => $displayName,
    'email'        => $email,
    'provider'     => 'google',
    'provider_subject' => $sub,
    'app_user_id'  => $appUserId,
    'role'         => $role,
    'given_name'   => $givenName,
    'family_name'  => $familyName,
    'picture'      => $picture,
];

// Also store in legacy session keys for compatibility
$_SESSION['user'] = $_SESSION['auth_user'];
$_SESSION['google_user'] = $userInfo;

// Clear OAuth state
unset($_SESSION['oauth2_state']);
unset($_SESSION['oauth2_return_to']);

// Regenerate session ID for security
session_regenerate_id(true);

// Redirect to the original destination
header('Location: ' . $returnTo, true, 302);
exit;

// If we reach here, authentication failed or is incomplete
$PAGE->setContentRenderer(function () use ($returnTo, $identity) {
    ob_start();
    ?>
    <div class="row justify-content-center">
      <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header bg-warning text-dark"><strong>OAuth2 Authentication Status</strong></div>
          <div class="card-body">
            <h5 class="card-title">Authentication Incomplete</h5>
            <p class="text-muted mb-3">
              The OAuth2 authentication flow did not complete successfully. 
              This could be due to:
            </p>
            <ul class="text-muted">
              <li>The OAuth provider did not send authentication headers</li>
              <li>The authentication proxy is not configured correctly</li>
              <li>Session data was not preserved during the redirect</li>
            </ul>
            
            <div class="alert alert-info mt-3" role="alert">
              <strong>Debug Information:</strong>
              <pre class="mb-0 mt-2" style="font-size: 0.85rem;"><?= h(json_encode([
                'is_authenticated' => $identity['is_authenticated'] ?? false,
                'username' => $identity['username'] ?? '',
                'email' => $identity['email'] ?? '',
                'provider' => $identity['provider'] ?? '',
                'sources' => $identity['source'] ?? [],
                'session_keys' => array_keys($_SESSION),
                'server_auth_headers' => array_filter($_SERVER, function($key) {
                    return str_contains($key, 'AUTH') || 
                           str_contains($key, 'OIDC') || 
                           str_contains($key, 'OAUTH') ||
                           str_contains($key, 'REMOTE_USER') ||
                           str_contains($key, 'HTTP_X_');
                }, ARRAY_FILTER_USE_KEY),
              ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
            
            <div class="d-grid gap-2 mt-4">
              <a class="btn btn-primary" href="/index.php?page=login&return_to=<?= rawurlencode($returnTo) ?>">
                Return to Login Page
              </a>
              <a class="btn btn-outline-secondary" href="<?= h($returnTo) ?>">
                Try Accessing Original Page
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
