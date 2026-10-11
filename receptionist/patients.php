<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('receptionist');

$hospitalId = currentHospitalId();
$term = trim($_GET['q'] ?? '');
$patients = [];
if ($hospitalId && $term !== '') {
    $query = $pdo->prepare(
        "SELECT p.id, COALESCE(p.patient_id, p.hospital_patient_id) AS patient_id, i.demo_health_id,
                COALESCE(p.full_name, i.full_name) AS full_name, COALESCE(p.mobile, i.mobile) AS mobile,
                p.blood_group, i.verification_status
         FROM hospital_patient_registrations hpr
         JOIN patients p ON p.id = hpr.patient_id
         JOIN demo_health_identities i ON i.id = p.health_identity_id
         WHERE hpr.hospital_id = :hospital_id
           AND hpr.registration_status = 'approved'
           AND (COALESCE(p.full_name, i.full_name) LIKE :term
                OR i.demo_health_id LIKE :term
                OR p.patient_id LIKE :term
                OR p.hospital_patient_id LIKE :term
                OR COALESCE(p.mobile, i.mobile) LIKE :term)
         ORDER BY COALESCE(p.full_name, i.full_name)"
    );
    $query->execute([':hospital_id' => $hospitalId, ':term' => '%' . $term . '%']);
    $patients = $query->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle = 'Patient Search';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Front Desk Officer</p><h1 class="h2">Patient Search</h1></div><a class="btn btn-primary" href="<?= e(appUrl('receptionist/register_patient.php')) ?>">Register patient</a></div><p class="text-muted">Search is restricted to patients registered at your assigned hospital.</p><form class="row g-2 mb-4"><div class="col-md-9"><input class="form-control" name="q" value="<?= e($term) ?>" placeholder="Patient ID, name, Health Identity, or mobile"></div><div class="col-md-3"><button class="btn btn-outline-primary w-100">Search</button></div></form><div class="card border-0 shadow-sm"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Patient</th><th>Patient ID</th><th>Health Identity</th><th>Mobile</th><th>Blood Group</th><th>Status</th></tr></thead><tbody><?php foreach ($patients as $patient): ?><tr><td><?= e($patient['full_name']) ?></td><td><strong><?= e($patient['patient_id']) ?></strong></td><td><?= e($patient['demo_health_id']) ?></td><td><?= e($patient['mobile']) ?></td><td><?= e($patient['blood_group'] ?: 'Unknown') ?></td><td><span class="badge text-bg-success">Registered</span></td></tr><?php endforeach; ?><?php if (!$patients): ?><tr><td colspan="6" class="text-muted"><?= $term === '' ? 'Enter a search term to find registered patients.' : 'No registered patient found.' ?></td></tr><?php endif; ?></tbody></table></div></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
