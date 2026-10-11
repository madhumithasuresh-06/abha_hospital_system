<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
requireRole('patient');

$patientQuery = $pdo->prepare(
    'SELECT p.patient_id, p.hospital_patient_id, p.full_name, p.date_of_birth, p.gender, p.mobile, p.email,
            p.address, p.city, p.state, p.pincode, p.emergency_contact_name, p.emergency_contact_relationship,
            p.emergency_contact_number, p.blood_group, d.demo_health_id, d.verification_status
     FROM patients p
     JOIN demo_health_identities d ON d.id = p.health_identity_id
     WHERE p.user_id = :user_id
     LIMIT 1'
);
$patientQuery->execute([':user_id' => $_SESSION['user_id']]);
$patient = $patientQuery->fetch(PDO::FETCH_ASSOC);
$displayPatientId = $patient['patient_id'] ?? $patient['hospital_patient_id'] ?? null;
$addressParts = array_filter([
    $patient['address'] ?? null,
    $patient['city'] ?? null,
    $patient['state'] ?? null,
    $patient['pincode'] ?? null,
]);
$pageTitle = 'Patient Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="hero-panel p-4 rounded-3 mb-4"><p class="text-uppercase text-secondary small fw-semibold mb-1">Patient space</p><h1 class="h2">Welcome, <?= e($patient['full_name'] ?? $_SESSION['username']) ?></h1><p class="mb-0">Your Health Identity connects your care across participating hospitals.</p><div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-primary" href="<?= e(appUrl('patient/book_appointment.php')) ?>">Request appointment</a><a class="btn btn-outline-dark" href="<?= e(appUrl('patient/appointments.php')) ?>">My appointments</a><a class="btn btn-outline-dark" href="<?= e(appUrl('patient/medical_records.php')) ?>">Medical records</a><a class="btn btn-outline-dark" href="<?= e(appUrl('patient/prescriptions.php')) ?>">Prescriptions</a></div></div>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="card stat-card p-3 h-100"><h2 class="h5">Patient Unique ID</h2><p class="mb-0"><strong><?= e($displayPatientId ?? 'Created after registration') ?></strong></p></div></div><div class="col-md-4"><div class="card stat-card p-3 h-100"><h2 class="h5">Health Identity</h2><?php if ($patient): ?><p class="mb-1"><strong><?= e($patient['demo_health_id']) ?></strong></p><span class="badge text-bg-success">Verified</span><?php else: ?><p class="text-muted mb-0">Identity details are not linked yet.</p><?php endif; ?></div></div></div>
<?php if ($patient): ?>
<section class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Patient Profile</h2>
        <div class="row g-3">
            <div class="col-md-6"><div class="text-muted small">Name</div><div><?= e($patient['full_name']) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Date of Birth</div><div><?= e($patient['date_of_birth']) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Gender</div><div><?= e(ucfirst($patient['gender'])) ?></div></div>
            <div class="col-md-4"><div class="text-muted small">Mobile Number</div><div><?= e($patient['mobile']) ?></div></div>
            <div class="col-md-4"><div class="text-muted small">Email</div><div><?= e($patient['email']) ?></div></div>
            <div class="col-md-4"><div class="text-muted small">Blood Group</div><div><?= e($patient['blood_group'] ?: 'Unknown') ?></div></div>
            <div class="col-md-6"><div class="text-muted small">Address</div><div><?= e(implode(', ', $addressParts)) ?></div></div>
            <div class="col-md-6"><div class="text-muted small">Emergency Contact</div><div><?= e(trim(($patient['emergency_contact_name'] ?? '') . ' - ' . ($patient['emergency_contact_relationship'] ?? '') . ' - ' . ($patient['emergency_contact_number'] ?? ''), ' -')) ?></div></div>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
