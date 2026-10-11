<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

requireRole('admin');

$statement = $pdo->prepare(
    "SELECT u.id, u.username, u.email, u.full_name, u.password_hash
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.id = :id AND r.role_name = 'admin' LIMIT 1"
);
$statement->execute([':id' => $_SESSION['user_id']]);
$admin = $statement->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    http_response_code(404);
    exit('Administrator account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } elseif ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Enter a full name, username, and valid email address.');
    } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
        flash('danger', 'The current password is incorrect.');
    } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
        flash('danger', 'The new password must contain at least 8 characters.');
    } else {
        try {
            $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $admin['password_hash'];
            $update = $pdo->prepare(
                'UPDATE users SET full_name = :full_name, username = :username, email = :email, password_hash = :password_hash WHERE id = :id'
            );
            $update->execute([
                ':full_name' => $fullName,
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':id' => $admin['id'],
            ]);
            $_SESSION['username'] = $username;
            logAudit('ADMIN_ACCOUNT_UPDATED', 'user', (int) $admin['id'], 'Administrator account details were updated.');
            flash('success', 'Administrator account updated. Use the new username or email to sign in.');
            redirect('update_admin.php');
        } catch (PDOException $exception) {
            flash('danger', 'Could not update the account. Username or email may already exist.');
        }
    }
}

$pageTitle = 'Administrator Account';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-shell"><div class="setup-card">
    <p class="eyebrow">Account security</p>
    <h1 class="h3">Update Administrator Account</h1>
    <p class="text-muted">Your current password is required to save changes. Leave the new password blank to keep it unchanged.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <label class="form-label" for="full_name">Full name</label>
        <input class="form-control mb-3" id="full_name" name="full_name" value="<?= e($admin['full_name'] ?? '') ?>" required>
        <label class="form-label" for="username">Username</label>
        <input class="form-control mb-3" id="username" name="username" value="<?= e($admin['username']) ?>" required>
        <label class="form-label" for="email">Email</label>
        <input class="form-control mb-3" id="email" type="email" name="email" value="<?= e($admin['email']) ?>" required>
        <label class="form-label" for="new_password">New password</label>
        <input class="form-control mb-3" id="new_password" type="password" name="new_password" minlength="8">
        <label class="form-label" for="current_password">Current password</label>
        <input class="form-control mb-3" id="current_password" type="password" name="current_password" required>
        <button class="btn btn-primary">Save account changes</button>
    </form>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>