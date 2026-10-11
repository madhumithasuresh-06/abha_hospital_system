<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

$userQuery = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id AND status = \'active\' LIMIT 1');
$userQuery->execute([':id' => $_SESSION['user_id']]);
$user = $userQuery->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(403); exit('Active account not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { flash('danger', 'The form expired. Please try again.'); }
    elseif (!password_verify($currentPassword, $user['password_hash'])) { flash('danger', 'The current password is incorrect.'); }
    elseif (strlen($newPassword) < 8) { flash('danger', 'The new password must contain at least 8 characters.'); }
    elseif ($newPassword !== $confirmPassword) { flash('danger', 'The new passwords do not match.'); }
    else { $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id'); $update->execute([':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $user['id']]); logAudit('PASSWORD_CHANGED', 'user', (int) $user['id'], 'User changed their password.'); flash('success', 'Password changed successfully.'); redirect('change_password.php'); }
}

$pageTitle = 'Change Password'; require __DIR__ . '/includes/header.php';
?>
<div class="setup-shell"><div class="setup-card"><p class="eyebrow">Account security</p><h1 class="h3">Change Password</h1><p class="text-muted">Your current password is required. Passwords are stored securely and never displayed.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><label class="form-label" for="current_password">Current password</label><input class="form-control mb-3" id="current_password" type="password" name="current_password" required autofocus><label class="form-label" for="new_password">New password</label><input class="form-control mb-3" id="new_password" type="password" name="new_password" minlength="8" required><label class="form-label" for="confirm_password">Confirm new password</label><input class="form-control mb-3" id="confirm_password" type="password" name="confirm_password" minlength="8" required><button class="btn btn-primary">Change password</button></form></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>