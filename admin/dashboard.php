<?php
session_start(); 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/consent.php';
requireRole('admin');

$counts = []; 
foreach (['users', 'patients', 'doctors', 'hospitals', 'appointments'] as $table) { 
    $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(); 
}

expireConsentRequests($pdo);

$consentCounts = []; 
foreach (['pending', 'approved', 'denied', 'revoked'] as $status) { 
    $query = $pdo->prepare('SELECT COUNT(*) FROM consent_requests WHERE status = :status'); 
    $query->execute([':status' => $status]); 
    $consentCounts[$status] = (int) $query->fetchColumn(); 
}

$consentRequests = $pdo->query('
    SELECT cr.id, cr.patient_id, cr.status, cr.purpose, cr.requested_at, i.full_name, i.demo_health_id 
    FROM consent_requests cr 
    JOIN patients p ON p.id = cr.patient_id 
    JOIN demo_health_identities i ON i.id = p.health_identity_id 
    ORDER BY cr.requested_at DESC LIMIT 10
')->fetchAll(PDO::FETCH_ASSOC);

$auditLogs = $pdo->query('SELECT action, role_name, description, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC); 

$pageTitle = 'Admin Dashboard'; 
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-uppercase text-secondary small fw-semibold mb-1">Control centre</p>
        <h1 class="h2">Admin Dashboard</h1>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('admin/hospitals.php')) ?>">Hospitals</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('admin/departments.php')) ?>">Departments</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('admin/doctors.php')) ?>">Doctors</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('admin/front_desk_officers.php')) ?>">Front Desk Officers</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('consent/requests.php')) ?>">Consent Requests</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('admin/audit_logs.php')) ?>">Audit logs</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('admin/reset_user_password.php')) ?>">Reset passwords</a>
        <a class="btn btn-outline-danger btn-sm" href="<?= e(appUrl('admin/clear_all_data.php')) ?>">Clear all data</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('setup_test_users.php')) ?>">Test users</a>
    </div>
</div>

<h3 class="h5 mt-4 mb-3">System Overview</h3>
<div class="row g-3 mb-4">
    <?php foreach (['users'=>'Users','patients'=>'Patients','doctors'=>'Doctors','hospitals'=>'Hospitals','appointments'=>'Appointments'] as $key => $label): ?>
    <div class="col-6 col-lg">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small"><?= e($label) ?></div>
            <div class="display-6 fw-bold"><?= $counts[$key] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h3 class="h5 mt-4 mb-3">Consent Management</h3>
<div class="row g-3 mb-4">
    <?php foreach (['pending'=>'Pending','approved'=>'Approved','denied'=>'Denied','revoked'=>'Revoked'] as $status => $label): ?>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-muted small"><?= e($label) ?></div>
            <div class="display-6 fw-bold"><?= $consentCounts[$status] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<section class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Consent Requests</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(appUrl('consent/requests.php')) ?>">View all</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($consentRequests as $consent): ?>
                    <tr>
                        <td>
                            <?= e($consent['full_name']) ?><br>
                            <small class="text-muted"><?= e($consent['demo_health_id']) ?></small>
                        </td>
                        <td><?= e($consent['purpose']) ?></td>
                        <td><span class="badge text-bg-<?= $consent['status'] === 'pending' ? 'warning' : ($consent['status'] === 'approved' ? 'success' : 'danger') ?>"><?= e(ucfirst($consent['status'])) ?></span></td>
                        <td><?= e($consent['requested_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$consentRequests): ?>
                    <tr><td colspan="4" class="text-muted">No consent requests.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<section class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Recent audit activity</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Action</th><th>Role</th><th>Description</th><th>Time</th></tr></thead><tbody><?php foreach ($auditLogs as $log): ?><tr><td><span class="badge text-bg-light"><?= e($log['action']) ?></span></td><td><?= e($log['role_name']) ?></td><td><?= e($log['description']) ?></td><td><?= e($log['created_at']) ?></td></tr><?php endforeach; ?><?php if (!$auditLogs): ?><tr><td colspan="4" class="text-muted">No audit activity yet.</td></tr><?php endif; ?></tbody></table></div></div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
