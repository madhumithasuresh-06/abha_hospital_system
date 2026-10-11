<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null) || $identifier === '' || $password === '') {
        flash('danger', 'Please provide valid login details.');
    } else {
        $statement = $pdo->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :identifier OR u.email = :identifier LIMIT 1');
        $statement->execute([':identifier' => $identifier]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            logAudit('LOGIN_FAILED', 'user', null, 'Invalid local login attempt.', null, null);
            flash('danger', 'Invalid login credentials.');
        } elseif ($user['status'] !== 'active') {
            logAudit('LOGIN_FAILED', 'user', (int) $user['id'], 'Login blocked because the account is not active.', (int) $user['id'], $user['role_name']);
            flash('warning', 'Account inactive.');
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id']; $_SESSION['username'] = $user['username']; $_SESSION['role'] = $user['role_name'];
            logAudit('LOGIN', 'user', (int) $user['id'], 'User logged in with local authentication.', (int) $user['id'], $user['role_name']);
            redirect(dashboardForRole($user['role_name']));
        }
    }
}
$pageTitle = 'Local Login'; require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5"><div class="card shadow-sm border-0"><div class="card-body p-4"><h1 class="h3">Local Login</h1><p class="text-muted">Use an account from the existing users table.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><label class="form-label" for="identifier">Username or email</label><input class="form-control mb-3" id="identifier" name="identifier" required autofocus><label class="form-label" for="password">Password</label><input class="form-control mb-3" id="password" type="password" name="password" required><button class="btn btn-primary w-100">Log in</button></form><a class="btn btn-outline-dark w-100 mt-3" href="google_login.php">Continue with Google</a><p class="small text-muted mt-3 mb-0">Need a first account? Use <a href="setup_admin.php">administrator setup</a>.</p></div></div></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>