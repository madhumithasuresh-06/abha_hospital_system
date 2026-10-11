<?php
session_start(); require_once __DIR__ . '/includes/sso.php';
if (!isset($_SESSION['sso_pending_user'])) { redirect('sso_login.php'); }
$user = $_SESSION['sso_pending_user']; unset($_SESSION['sso_pending_user']); ssoLogin($user); createApplicationSession(); redirect(dashboardForRole($user['role_name']));