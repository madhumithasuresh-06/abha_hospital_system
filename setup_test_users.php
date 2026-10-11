<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

requireRole('admin');

$testUsers = [
    'patient' => ['username' => 'test_patient', 'email' => 'test.patient@hospital.local', 'password' => 'Patient@12345', 'name' => 'Test Patient'],
    'receptionist' => ['username' => 'test_receptionist', 'email' => 'test.receptionist@hospital.local', 'password' => 'Reception@12345', 'name' => 'Test Receptionist'],
    'doctor' => ['username' => 'test_doctor', 'email' => 'test.doctor@hospital.local', 'password' => 'Doctor@12345', 'name' => 'Test Doctor'],
    'consent_manager' => ['username' => 'test_consent', 'email' => 'test.consent@hospital.local', 'password' => 'Consent@12345', 'name' => 'Test Consent Manager'],
];
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } else {
        foreach ($testUsers as $roleName => $details) {
            $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE role_name = :role_name LIMIT 1');
            $roleQuery->execute([':role_name' => $roleName]);
            $roleId = $roleQuery->fetchColumn();
            $existingQuery = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
            $existingQuery->execute([':username' => $details['username'], ':email' => $details['email']]);

            if ($existingQuery->fetchColumn()) {
                $results[] = ucfirst(str_replace('_', ' ', $roleName)) . ': already exists; skipped.';
                continue;
            }

            $insert = $pdo->prepare(
                'INSERT INTO users (role_id, username, email, password_hash, status)
                 VALUES (:role_id, :username, :email, :password_hash, \'active\')'
            );
            $insert->execute([
                ':role_id' => $roleId,
                ':username' => $details['username'],
                ':email' => $details['email'],
                ':password_hash' => password_hash($details['password'], PASSWORD_DEFAULT),
            ]);
            logAudit('TEST_USER_CREATED', 'user', (int) $pdo->lastInsertId(), 'Development test user created for role: ' . $roleName . '.');
            $results[] = ucfirst(str_replace('_', ' ', $roleName)) . ': created.';
        }
        flash('success', 'Development user setup completed.');
    }
}

$pageTitle = 'Development Test Users';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-shell"><div class="setup-card">
    <p class="eyebrow">Local administration</p>
    <h1 class="h3">Development Test Users</h1>
    <p class="text-muted">Creates missing test accounts for role and SSO verification. Existing users, including the administrator, are skipped.</p>
    <?php foreach ($results as $result): ?><div class="alert alert-secondary py-2"><?= e($result) ?></div><?php endforeach; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button class="btn btn-primary">Create missing test users</button></form>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>