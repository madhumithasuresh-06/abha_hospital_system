<?php

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/functions.php';

function ssoLogin(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['sso_authenticated'] = true;
    $_SESSION['sso_user'] = [
        'user_id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'hospital_id' => $user['hospital_id'] ?? null,
        'role' => $user['role_name'],
    ];
    logAudit('SSO_LOGIN', 'user', (int) $user['id'], 'User authenticated through Demo SSO.', (int) $user['id'], $user['role_name']);
}

function createApplicationSession(): void
{
    if (!isSSOAuthenticated()) {
        return;
    }

    $identity = getSSOUser();
    $_SESSION['user_id'] = $identity['user_id'];
    $_SESSION['username'] = $identity['username'];
    $_SESSION['role'] = $identity['role'];
}

function isSSOAuthenticated(): bool
{
    return !empty($_SESSION['sso_authenticated']) && isset($_SESSION['sso_user']);
}

function getSSOUser(): ?array
{
    return $_SESSION['sso_user'] ?? null;
}

function ssoLogout(): void
{
    if (isSSOAuthenticated()) {
        logAudit('SSO_LOGOUT', 'user', $_SESSION['user_id'] ?? null, 'User ended the Demo SSO session.');
    }
    unset($_SESSION['sso_authenticated'], $_SESSION['sso_user']);
}
