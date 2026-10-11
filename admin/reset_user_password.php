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
        flash('danger', 'Select a user and enter a password of at least 8 characters.');
    } else {
        $userQuery = $pdo->prepare("
            SELECT u.id, u.username, u.email, r.role_name 
            FROM users u 
            JOIN roles r ON r.id = u.role_id 
            WHERE u.id = :user_id 
            LIMIT 1
        ");
        $userQuery->execute([':user_id' => $userId]);
        $user = $userQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            flash('danger', 'User account not found.');
        } else {
            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
            $update->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':user_id' => $userId
            ]);
            logAudit('PASSWORD_RESET', 'admin', $userId, 'Password reset for ' . ucfirst($user['role_name']) . ' user by administrator.');
            flash('success', 'Password reset for ' . $user['username'] . ' (' . ucfirst($user['role_name']) . ').');
            redirect('reset_user_password.php');
        }
    }
}

// Get all users with their roles
$users = $pdo->query('
    SELECT u.id, u.username, u.email, r.role_name, u.full_name 
    FROM users u 
    JOIN roles r ON r.id = u.role_id 
    ORDER BY r.role_name, u.full_name
')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Reset User Password';
require __DIR__ . '/../includes/header.php';
?>

<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow">Administrator security</p>
        <h1 class="h3">Reset User Password</h1>
        <p class="text-muted">Reset the password for any user account. Works for admins, doctors, patients, receptionists, and all other roles.</p>
        
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            
            <label class="form-label" for="user_id">Select User</label>
            <select class="form-select mb-3" id="user_id" name="user_id" required>
                <option value="">Choose a user...</option>
                <?php 
                $lastRole = null;
                foreach ($users as $user): 
                    if ($lastRole !== $user['role_name']): 
                        if ($lastRole !== null): ?>
                        </optgroup>
                        <?php endif; ?>
                        <optgroup label="<?= e(ucfirst($user['role_name'])) ?>s">
                    <?php 
                        $lastRole = $user['role_name'];
                    endif; 
                    ?>
                    <option value="<?= (int) $user['id'] ?>">
                        <?= e($user['full_name'] ?? $user['username']) ?> 
                        (<?= e($user['username']) ?> - <?= e($user['email']) ?>)
                    </option>
                <?php endforeach; ?>
                <?php if ($lastRole !== null): ?>
                </optgroup>
                <?php endif; ?>
            </select>
            
            <label class="form-label" for="new_password">New Password</label>
            <input 
                class="form-control mb-3" 
                id="new_password" 
                type="password" 
                name="new_password" 
                minlength="8" 
                placeholder="Minimum 8 characters"
                required
            >
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Reset Password</button>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
