<?php
session_start(); require_once __DIR__ . '/../includes/auth.php'; requireRole('doctor');
$doctorQuery = $pdo->prepare('SELECT id, doctor_name FROM doctors WHERE user_id = :user_id LIMIT 1'); $doctorQuery->execute([':user_id' => $_SESSION['user_id']]); $doctor = $doctorQuery->fetch(PDO::FETCH_ASSOC); $scheduled = 0; $completed = 0; $prescriptionCount = 0; $recentPatients = [];
if ($doctor) {
    $countQuery = $pdo->prepare("SELECT status, COUNT(*) AS total FROM appointments WHERE doctor_id = :doctor_id GROUP BY status"); $countQuery->execute([':doctor_id' => $doctor['id']]); foreach ($countQuery as $row) { if ($row['status'] === 'scheduled') { $scheduled = (int) $row['total']; } if ($row['status'] === 'completed') { $completed = (int) $row['total']; } }
    $prescriptionQuery = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE doctor_id = :doctor_id"); $prescriptionQuery->execute([':doctor_id' => $doctor['id']]); $prescriptionCount = (int) $prescriptionQuery->fetchColumn();
    $recentPatientsQuery = $pdo->prepare("SELECT p.id AS patient_id, i.full_name, i.demo_health_id, MAX(a.appointment_date) AS last_visit_date FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN demo_health_identities i ON i.id = p.health_identity_id WHERE a.doctor_id = :doctor_id AND a.status = 'completed' GROUP BY p.id, i.full_name, i.demo_health_id ORDER BY last_visit_date DESC LIMIT 6"); $recentPatientsQuery->execute([':doctor_id' => $doctor['id']]); $recentPatients = $recentPatientsQuery->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle = 'Doctor Dashboard'; require __DIR__ . '/../includes/header.php';
?>
<div class="hero-panel p-4 rounded-3 mb-4">
    <p class="text-uppercase text-secondary small fw-semibold mb-1">Clinical workspace</p>
    <h1 class="h2">Welcome, <?= e($doctor['doctor_name'] ?? $_SESSION['username']) ?></h1>
    <p class="mb-0">Manage assigned appointments and document patient care securely.</p>
    <div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-primary" href="<?= e(appUrl('doctor/appointments.php')) ?>">View appointments</a><a class="btn btn-outline-dark" href="<?= e(appUrl('doctor/medical_records.php')) ?>">Medical records</a></div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card stat-card p-3 h-100"><div class="text-muted small">Scheduled appointments</div><div class="display-6 fw-bold"><?= $scheduled ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card p-3 h-100"><div class="text-muted small">Completed consultations</div><div class="display-6 fw-bold"><?= $completed ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card p-3 h-100"><div class="text-muted small">Prescriptions issued</div><div class="display-6 fw-bold"><?= $prescriptionCount ?></div></div></div>
</div>
<section class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div><p class="eyebrow mb-1">Continuity of care</p><h2 class="h5 mb-0">Recently Visited Patients</h2></div>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(appUrl('doctor/appointments.php')) ?>">All appointments</a>
        </div>
        <label class="visually-hidden" for="recent-patient-search">Search patient</label>
        <input class="form-control mb-3" id="recent-patient-search" type="search" placeholder="Search patient..." autocomplete="off">
        <?php if ($recentPatients): ?>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Patient Name</th><th>Last Visit</th><th>Action</th></tr></thead><tbody id="recent-patients-table-body">
                <?php foreach ($recentPatients as $recentPatient): ?><tr data-patient-name="<?= e(strtolower($recentPatient['full_name'])) ?>"><td><?= e($recentPatient['full_name']) ?></td><td><?= e($recentPatient['last_visit_date']) ?></td><td><a class="btn btn-sm btn-primary" href="<?= e(appUrl('doctor/patient_data.php?patient_id=' . $recentPatient['patient_id'])) ?>">View History</a></td></tr><?php endforeach; ?>
            </tbody></table></div><p class="text-muted mb-0 d-none" id="recent-patients-no-results">No matching patients found.</p>
        <?php else: ?><p class="text-muted mb-0">No completed patient visits yet.</p><?php endif; ?>
    </div>
</section>
<script>
    const recentPatientSearch = document.getElementById('recent-patient-search');
    const recentPatientRows = document.querySelectorAll('#recent-patients-table-body tr');
    const recentPatientsNoResults = document.getElementById('recent-patients-no-results');
    if (recentPatientSearch) {
        recentPatientSearch.addEventListener('input', function () {
            const searchTerm = this.value.trim().toLowerCase();
            let visibleRows = 0;
            recentPatientRows.forEach(function (row) {
                const matches = row.dataset.patientName.includes(searchTerm);
                row.classList.toggle('d-none', !matches);
                if (matches) { visibleRows++; }
            });
            if (recentPatientsNoResults) { recentPatientsNoResults.classList.toggle('d-none', visibleRows !== 0); }
        });
    }
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
