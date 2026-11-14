<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/oauth2_start.php
/** @var \FPM\App\Template $PAGE */

// This page initiates the Google OAuth2 flow directly
// No template rendering - we redirect immediately to Google

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Get the return URL (where to go after OAuth completes)
$returnParam = trim((string)(getenv('APP_OAUTH_RETURN_PARAM') ?: 'rd'));
$returnTo = (string)($_GET[$returnParam] ?? $_GET['return_to'] ?? '/index.php?page=home');

// Validate return URL
if ($returnTo === '' || $returnTo[0] !== '/') {
    $returnTo = '/index.php?page=home';
}

// Store return URL in session for the callback
$_SESSION['oauth2_return_to'] = $returnTo;

// Generate and store state parameter for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth2_state'] = $state;

// Google OAuth2 configuration from environment
$clientId = trim((string)getenv('GOOGLE_OAUTH_CLIENT_ID'));
$redirectUri = trim((string)getenv('APP_OAUTH_CALLBACK_URL'));

if ($clientId === '') {
    http_response_code(500);
    echo '<!DOCTYPE html>
<html>
<head><title>Configuration Error</title></head>
<body>
<h1>OAuth2 Configuration Error</h1>
<p>GOOGLE_OAUTH_CLIENT_ID is not configured in the environment.</p>
<p>Please add it to your .env file and restart the application.</p>
<p><a href="/index.php?page=login">Return to Login</a></p>
</body>
</html>';
    exit;
}

if ($redirectUri === '') {
    $redirectUri = 'https://' . ($_SERVER['HTTP_HOST'] ?? org('app_host')) . '/index.php?page=oauth2_callback';
}

// Build Google OAuth2 authorization URL
$authParams = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'online',
    'prompt' => 'select_account',
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($authParams);

// Redirect to Google
header('Location: ' . $authUrl, true, 302);
exit;
