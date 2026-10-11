<?php
session_start(); require_once __DIR__ . '/includes/sso.php'; require_once __DIR__ . '/includes/audit.php';
if (isset($_SESSION['user_id'])) { logAudit('LOGOUT', 'user', (int) $_SESSION['user_id'], 'User logged out.'); }
ssoLogout(); $_SESSION = []; if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']); } session_destroy(); header('Location: index.php'); exit;