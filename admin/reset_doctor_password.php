<?php

session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newPassword = $_POST['new_password'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } elseif (!$userId || strlen($newPassword) < 8) {
        flash('danger', 'Select a doctor and enter a password of at least 8 characters.');
    } else {
        $doctorQuery = $pdo->prepare("SELECT u.id, u.username FROM users u JOIN roles r ON r.id = u.role_id JOIN doctors d ON d.user_id = u.id WHERE u.id = :user_id AND r.role_name = 'doctor' LIMIT 1");
        $doctorQuery->execute([':user_id' => $userId]);
        $doctor = $doctorQuery->fetch(PDO::FETCH_ASSOC);
        if (!$doctor) {
            flash('danger', 'Doctor account not found.');
        } else {
            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
            $update->execute([':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':user_id' => $userId]);
            logAudit('DOCTOR_PASSWORD_RESET', 'user', $userId, 'Doctor password reset by administrator.');
            flash('success', 'Password reset for ' . $doctor['username'] . '.');
            redirect('reset_doctor_password.php');
        }
    }
}

$doctors = $pdo->query('SELECT u.id, u.username, u.email, d.doctor_name FROM users u JOIN roles r ON r.id = u.role_id JOIN doctors d ON d.user_id = u.id WHERE r.role_name = \'doctor\' ORDER BY d.doctor_name')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Reset Doctor Password';
require __DIR__ . '/../includes/header.php';
?>
<div class="setup-shell"><div class="setup-card"><p class="eyebrow">Administrator security</p><h1 class="h3">Reset Doctor Password</h1><p class="text-muted">This changes the password for an existing doctor account. The doctor profile and role remain unchanged.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><label class="form-label" for="user_id">Doctor</label><select class="form-select mb-3" id="user_id" name="user_id" required><option value="">Select doctor</option><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>"><?= e($doctor['doctor_name'] . ' - ' . $doctor['username'] . ' (' . $doctor['email'] . ')') ?></option><?php endforeach; ?></select><label class="form-label" for="new_password">New password</label><input class="form-control mb-3" id="new_password" type="password" name="new_password" minlength="8" required><button class="btn btn-primary">Reset doctor password</button></form></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>