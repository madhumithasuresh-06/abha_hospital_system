<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('warning', 'Please log in to continue.');
        redirect(appUrl('login.php'));
    }
}

function requireRole(string|array $roles): void
{
    requireLogin();
    $allowedRoles = (array) $roles;

    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit('Unauthorized access');
    }
}

function currentUser(): ?array
{
    static $user;
    global $pdo;

    if (!isLoggedIn()) {
        return null;
    }
    if ($user !== null) {
        return $user;
    }

    $statement = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.full_name, u.hospital_id, u.status, r.role_name
         FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1'
    );
    $statement->execute([':id' => $_SESSION['user_id']]);
    $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    return $user;
}

function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
}
