<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

$clientId = env('GOOGLE_CLIENT_ID', '');
if ($clientId === '') {
    flash('danger', 'Google Sign-In is not configured in this environment.');
    redirect('login.php');
}

$redirectUri = env('GOOGLE_REDIRECT_URI', appUrl('google_callback.php'));
$state = bin2hex(random_bytes(16));
$_SESSION['google_state'] = $state;

$authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
    . '?client_id=' . urlencode($clientId)
    . '&redirect_uri=' . urlencode($redirectUri)
    . '&response_type=code'
    . '&scope=' . urlencode('openid email profile')
    . '&access_type=online'
    . '&prompt=consent'
    . '&state=' . urlencode($state);

redirect($authorizationUrl);
