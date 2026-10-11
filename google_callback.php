<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/otp.php';
require_once __DIR__ . '/includes/audit.php';

$code = trim($_GET['code'] ?? '');
$state = trim($_GET['state'] ?? '');

if ($code === '' || $state === '' || $state !== ($_SESSION['google_state'] ?? '')) {
    flash('danger', 'Google Sign-In could not be completed.');
    redirect('login.php');
}

$clientId = env('GOOGLE_CLIENT_ID', '');
$clientSecret = env('GOOGLE_CLIENT_SECRET', '');
$redirectUri = env('GOOGLE_REDIRECT_URI', appUrl('google_callback.php'));

if ($clientId === '' || $clientSecret === '') {
    flash('danger', 'Google OAuth credentials are not configured.');
    redirect('login.php');
}

$tokenPayload = [
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($tokenPayload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tokenResponse = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($tokenResponse ?: '{}', true);
if (!is_array($tokenData) || empty($tokenData['id_token'])) {
    flash('danger', 'Google authentication did not return a valid identity token.');
    redirect('login.php');
}

$idToken = $tokenData['id_token'];
$tokenInfoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$tokenInfoResponse = file_get_contents($tokenInfoUrl);
$tokenInfo = json_decode($tokenInfoResponse ?: '{}', true);

if (!is_array($tokenInfo) || empty($tokenInfo['email']) || !isset($tokenInfo['aud']) || $tokenInfo['aud'] !== $clientId) {
    flash('danger', 'Google email information could not be validated.');
    redirect('login.php');
}

$email = trim((string) $tokenInfo['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('danger', 'Google Sign-In did not return a valid email address.');
    redirect('login.php');
}

$statement = $pdo->prepare(
    'SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = :email LIMIT 1'
);
$statement->execute([':email' => $email]);
$user = $statement->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    flash('danger', 'No active account was found for the authenticated Google email address.');
    redirect('login.php');
}

if (($user['status'] ?? '') !== 'active') {
    flash('warning', 'Account inactive. Please contact the administrator.');
    redirect('login.php');
}

$_SESSION['google_pending_user'] = $user;
$_SESSION['google_pending_email'] = $email;
clearOtpChallenge();
beginOtpChallenge((int) $user['id'], $email, $user['role_name'], 'google_login');
redirect('google_otp.php');
